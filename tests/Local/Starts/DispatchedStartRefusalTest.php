<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Starts;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Flows\StartTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function in_array;
use function time;
use function usleep;

/**
 * A dispatched start never replaces a run (section 9.6 and row 16 of section
 * 7 of the design): whatever state the run holding the workflow ID is in,
 * the request is refused and nothing is queued.
 */
class DispatchedStartRefusalTest extends StartTestCase
{
    /** What the request that dispatched a second start was thrown; null when it was thrown nothing. */
    protected ?RunInFlightException $refusal = null;

    #[DataProvider('holdersOnEveryBackend')]
    public function test_a_start_dispatched_for_a_workflow_id_a_run_holds_is_refused_as_run_in_flight(
        string $backend,
        string $holder,
        WorkflowStatus $status,
    ): void {
        $this->benchOn($backend);

        $this->dispatchAStartWhileARunHoldsTheWorkflowId($holder);

        self::assertInstanceOf(RunInFlightException::class, $this->refusal);
        self::assertSame($status, $this->refusal->status);
        self::assertSame(self::WORKFLOW_ID, $this->refusal->workflowId);
    }

    #[DataProvider('holdersOnEveryBackend')]
    public function test_a_start_refused_as_run_in_flight_is_not_queued(string $backend, string $holder): void
    {
        $this->benchOn($backend);

        $this->dispatchAStartWhileARunHoldsTheWorkflowId($holder);

        self::assertNotContains(Verb::Start, $this->verbsQueued());
    }

    #[DataProvider('holdersThatStayOnEveryBackend')]
    public function test_a_start_refused_as_run_in_flight_names_the_run_that_holds_the_workflow_id_and_leaves_it_as_it_was(
        string $backend,
        string $holder,
        WorkflowStatus $status,
    ): void {
        $this->benchOn($backend);

        $this->dispatchAStartWhileARunHoldsTheWorkflowId($holder);

        $held = $this->holder();
        self::assertSame([$held?->runId, $held?->executionAttempt], [$this->refusal?->runId, $this->refusal?->executionAttempt]);
        self::assertSame([$status, 1], [$held?->status, $held?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_a_start_dispatched_once_the_lease_of_a_run_has_passed_does_not_take_that_run_over(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $this->dispatchAStartWhileARunHoldsTheWorkflowId('overdue');

        self::assertInstanceOf(RunInFlightException::class, $this->refusal);
        self::assertLessThanOrEqual(time(), $this->refusal->leaseExpiresAt, 'The lease of the holder had passed when the start was refused.');
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    /**
     * @return iterable<string, array{string, string, WorkflowStatus}> a backend, the run that holds the workflow
     *                                                                  ID, and the status the refusal shows
     */
    public static function holdersOnEveryBackend(): iterable
    {
        yield from self::holdersThatStayOnEveryBackend();

        foreach (self::backends() as $store => [$backend]) {
            yield "a run executing under a live lease, on {$store}" => [$backend, 'executing', WorkflowStatus::Running];
            yield "a run executing under a lease that has passed, on {$store}" => [$backend, 'overdue', WorkflowStatus::Running];
            yield "a run executing with no lease, started outside the SDK, on {$store}" => [$backend, 'unleased', WorkflowStatus::Running];
        }
    }

    /**
     * @return iterable<string, array{string, string, WorkflowStatus}> the holders that are still there, unchanged,
     *                                                                  when the request has been refused
     */
    public static function holdersThatStayOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "a run suspended on a wait, on {$store}" => [$backend, 'suspended', WorkflowStatus::Suspended];
            yield "a run that failed, on {$store}" => [$backend, 'failed', WorkflowStatus::Failed];
            yield "a completion retained by code outside the SDK, on {$store}" => [$backend, 'retained', WorkflowStatus::Completed];
        }
    }

    /**
     * Put a run on the workflow ID, in the state the test names, and have a
     * second request dispatch a start of "job" for the same workflow ID: once
     * the run has stopped when it stays there, from inside its node while it
     * is executing.
     */
    protected function dispatchAStartWhileARunHoldsTheWorkflowId(string $holder): void
    {
        $executions = $this->bench->executions;
        $secondRequest = $holder === 'overdue' ? $this->dispatchOnceTheLeaseHasPassed(...) : $this->dispatch(...);

        $this->bench->cloud->register('job', static fn (): Workflow => match ($holder) {
            'suspended' => new WaitingWorkflow($executions),
            'failed' => new FlakyWorkflow($executions),
            'executing', 'overdue' => new BusyWorkflow($executions, $secondRequest),
            default => new CompletingWorkflow($executions),
        }, leaseTimeout: $holder === 'overdue' ? 1 : 600);

        match ($holder) {
            'failed' => $this->failAStart(),
            'retained' => $this->outsideTheSdk(new CompletingWorkflow($executions))->retainCompletionUntilAcknowledged()->run(),
            'unleased' => $this->outsideTheSdk(new BusyWorkflow($executions, $secondRequest))->run(),
            default => $this->job()->start()->run(),
        };

        if (in_array($holder, ['suspended', 'failed', 'retained'], true)) {
            $secondRequest();
        }
    }

    protected function job(): ManagedWorkflow
    {
        return $this->bench->cloud->workflow('job', self::WORKFLOW_ID);
    }

    /**
     * A second request dispatches a start of "job" for the workflow ID.
     */
    protected function dispatch(): void
    {
        try {
            $this->job()->start()->dispatch();
        } catch (RunInFlightException $refusal) {
            $this->refusal = $refusal;
        }
    }

    /**
     * Neuron reads time(), so only the real clock can make the lease of the run that is executing pass.
     */
    protected function dispatchOnceTheLeaseHasPassed(): void
    {
        while ($this->holder()?->leaseExpiresAt > time()) {
            usleep(50_000);
        }

        $this->dispatch();
    }

    protected function failAStart(): void
    {
        try {
            $this->job()->start()->run();
            self::fail('The node did not throw.');
        } catch (NodeFailed) {
        }
    }

    /**
     * A workflow bound to the workflow ID on the store of the bench, as code that does not go through the SDK runs it.
     */
    protected function outsideTheSdk(Workflow $workflow): Workflow
    {
        return $workflow->for(self::WORKFLOW_ID)->setPersistence(($this->bench->persistence)());
    }
}
