<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use DateTimeImmutable;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Execution;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;

/**
 * One request given to Neuron: the settlement says how the call ended, and
 * the caller gets back what a direct call to Neuron would have given it.
 */
class ExecutionTest extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const RIVAL_CONTROL = 'the control record of a run that ends at once';

    public function test_a_run_that_completes_settles_as_completed_under_the_attempt_of_its_segment(): void
    {
        $execution = Execution::of($this->completing(), self::start());

        self::assertSame(Outcome::Completed, $execution->settlement->outcome);
        self::assertSame(1, $execution->settlement->executedAttempt);
    }

    public function test_a_run_that_stops_at_a_wait_settles_as_suspended(): void
    {
        $execution = Execution::of($this->waiting(), self::start());

        self::assertSame(Outcome::Suspended, $execution->settlement->outcome);
    }

    public function test_the_state_neuron_returns_is_given_back(): void
    {
        $state = Execution::of($this->completing(), self::start())->state();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(self::RUN_ID, $state->getRunId());
    }

    public function test_a_state_neuron_returns_without_opening_a_segment_has_no_executed_attempt(): void
    {
        $notDue = new DateTimeImmutable('+1 hour');
        Execution::of($this->waiting($notDue), self::start());

        $early = Execution::of($this->waiting($notDue), ExecutionRequest::resume(null, self::RUN_ID, 1));

        self::assertSame(Outcome::Suspended, $early->settlement->outcome);
        self::assertNull($early->settlement->executedAttempt);
        self::assertSame(WorkflowStatus::Suspended, $early->state()->getStatus());
    }

    public function test_what_a_node_throws_settles_as_a_failure_with_its_class_and_the_attempt_that_ran(): void
    {
        $execution = Execution::of($this->flaky(), self::start());

        self::assertSame(Outcome::Failed, $execution->settlement->outcome);
        self::assertSame(NodeFailed::class, $execution->settlement->errorClass);
        self::assertSame(1, $execution->settlement->executedAttempt);
    }

    public function test_what_a_node_throws_is_thrown_back_as_it_is(): void
    {
        $execution = Execution::of($this->flaky(), self::start());

        $this->expectExceptionObject(new NodeFailed('The flaky node failed on purpose.'));

        $execution->state();
    }

    public function test_a_request_neuron_refuses_settles_as_refused_with_its_reason(): void
    {
        Execution::of($this->waiting(), self::start());

        $second = Execution::of($this->waiting(), self::start('1-another-run'));

        self::assertSame(Outcome::Refused, $second->settlement->outcome);
        self::assertSame(RefusalReason::RunInFlight->value, $second->settlement->code);
        self::assertNull($second->settlement->executedAttempt);
    }

    public function test_the_refusal_of_neuron_is_thrown_back_as_it_is(): void
    {
        Execution::of($this->waiting(), self::start());
        $second = Execution::of($this->waiting(), self::start('1-another-run'));

        try {
            $second->state();
            self::fail('The refusal was not thrown.');
        } catch (RunInFlightException $refusal) {
            self::assertSame(self::RUN_ID, $refusal->runId);
            self::assertSame('1-another-run', $refusal->reservedRunId);
        }
    }

    public function test_what_is_thrown_before_a_segment_opens_settles_as_an_abort_and_is_thrown_back(): void
    {
        $unavailable = new InterceptedPersistence(($this->bench()->persistence)(), static function (): never {
            throw new StoreUnavailable('The store is down.');
        });

        $execution = Execution::of($this->completing()->setPersistence($unavailable), self::start());

        self::assertSame(Outcome::Aborted, $execution->settlement->outcome);
        self::assertSame(Code::NotExecuted->value, $execution->settlement->code);
        self::assertSame(StoreUnavailable::class, $execution->settlement->errorClass);
        $this->expectException(StoreUnavailable::class);
        $execution->state();
    }

    public function test_a_conflict_of_the_ignition_is_tried_once_more(): void
    {
        $execution = Execution::of($this->completing()->setPersistence($this->storeWhereARivalRunEndsDuringIgnitions(1)), self::start());

        self::assertSame(Outcome::Completed, $execution->settlement->outcome);
        self::assertSame(1, $this->bench()->executions->count(CompleteNode::class));
    }

    public function test_a_conflict_met_twice_is_a_refusal(): void
    {
        $execution = Execution::of($this->completing()->setPersistence($this->storeWhereARivalRunEndsDuringIgnitions(2)), self::start());

        self::assertSame(Outcome::Refused, $execution->settlement->outcome);
        self::assertSame(RefusalReason::Conflict->value, $execution->settlement->code);
        self::assertSame(0, $this->bench()->executions->total());
    }

    protected static function start(string $runId = self::RUN_ID): ExecutionRequest
    {
        return ExecutionRequest::start(new StartEvent(), $runId);
    }

    protected function bench(): TestBench
    {
        return $this->bench ?? $this->benchOn(TestBench::IN_MEMORY);
    }

    protected function completing(): Workflow
    {
        return $this->bound(new CompletingWorkflow($this->bench()->executions));
    }

    /**
     * Waits for an event with no deadline, then for a timer.
     */
    protected function waiting(?DateTimeImmutable $paymentDeadline = null): Workflow
    {
        return $this->bound(new WaitingWorkflow($this->bench()->executions, $paymentDeadline));
    }

    /**
     * Its node throws the first time it runs.
     */
    protected function flaky(): Workflow
    {
        return $this->bound(new FlakyWorkflow($this->bench()->executions));
    }

    protected function bound(Workflow $workflow): Workflow
    {
        return $workflow->for(self::WORKFLOW_ID)->setPersistence(($this->bench()->persistence)());
    }

    /**
     * Neuron answers "conflict" to a start that found the workflow ID taken and, on reading it, free again. A
     * rival run does that here for the next $ignitions.
     */
    protected function storeWhereARivalRunEndsDuringIgnitions(int $ignitions): PersistenceInterface
    {
        $store = ($this->bench()->persistence)();

        return new InterceptedPersistence($store, static function (string $operation) use ($store, &$ignitions): void {
            if ($operation === 'initializeIfAbsent' && $ignitions > 0) {
                $ignitions--;
                $store->initializeIfAbsent(self::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
            }

            if ($operation === 'get') {
                $store->deleteIfUnchanged(self::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
            }
        });
    }
}
