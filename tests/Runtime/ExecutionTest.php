<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use DateTimeImmutable;
use Generator;
use JsonException;
use LogicException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Execution;
use NeuronCore\Cloud\Runtime\Settlement;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NarrateNode;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Stub\Units\EndForwardingNode;
use NeuronCore\Cloud\Tests\Stub\Units\FramingAdapter;
use NeuronCore\Cloud\Tests\Stub\Units\RunOverridingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Units\UnframableAdapter;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use WeakReference;

use function count;
use function iterator_to_array;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * One request given to Neuron: the settlement says how the call ended, and
 * the caller gets back what a direct call to Neuron would have given it.
 */
class ExecutionTest extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const RIVAL_CONTROL = 'the control record of a run that ends at once';

    /** @var array<int, Settlement|null> how each call taken as events ended, as its generator told it: null when it could not say */
    protected array $settled = [];

    /** @var array<int, WorkflowStatus|null> the status of the run in the store each time a generator told how its call ended */
    protected array $heldWhenSettled = [];

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

    public function test_a_class_that_overrides_run_is_executed_through_its_own_run(): void
    {
        $workflow = $this->bound(new RunOverridingWorkflow($this->bench()->executions, failures: 0));

        $execution = Execution::of($workflow, self::start());

        self::assertSame(Outcome::Completed, $execution->settlement->outcome);
        self::assertSame(1, $this->bench()->executions->count(RunOverridingWorkflow::class));
    }

    public function test_a_conflict_of_the_ignition_asks_the_run_of_the_class_once_more(): void
    {
        $workflow = $this->bound(new RunOverridingWorkflow($this->bench()->executions, failures: 0))
            ->setPersistence($this->storeWhereARivalRunEndsDuringIgnitions(1));

        Execution::of($workflow, self::start());

        self::assertSame(2, $this->bench()->executions->count(RunOverridingWorkflow::class));
        self::assertSame(1, $this->bench()->executions->count(FlakyNode::class));
    }

    #[DataProvider('lastFramesAnAdapterCannotBuild')]
    public function test_a_run_neuron_closed_before_the_stream_adapter_threw_settles_as_neuron_closed_it(string $unframable, Outcome $closedAs): void
    {
        $workflow = $unframable === 'end' ? $this->completing() : $this->waiting();
        $workflow->setStreamAdapter(static fn (): UnframableAdapter => new UnframableAdapter($unframable));

        $execution = Execution::of($workflow, self::start());

        self::assertSame(
            [$closedAs, null, null, 1],
            [$execution->settlement->outcome, $execution->settlement->code, $execution->settlement->errorClass, $execution->settlement->executedAttempt],
        );
    }

    #[TestWith(['end'])]
    #[TestWith(['interrupt'])]
    public function test_what_the_stream_adapter_threw_after_neuron_closed_the_run_is_still_thrown_back(string $unframable): void
    {
        $workflow = $unframable === 'end' ? $this->completing() : $this->waiting();
        $workflow->setStreamAdapter(static fn (): UnframableAdapter => new UnframableAdapter($unframable));
        $execution = Execution::of($workflow, self::start());

        $this->expectException(LogicException::class);

        $execution->state();
    }

    /**
     * @return array<string, array{string, Outcome}> the method of the adapter that throws, and how Neuron had closed the segment
     */
    public static function lastFramesAnAdapterCannotBuild(): array
    {
        return [
            'the end frame of a segment that completed' => ['end', Outcome::Completed],
            'the frame of the wait of a segment that suspended' => ['interrupt', Outcome::Suspended],
        ];
    }

    public function test_events_yield_what_the_events_of_neuron_yield_and_return_its_state(): void
    {
        $twin = (new NarratingWorkflow($this->bench()->executions))->for(self::WORKFLOW_ID)->setPersistence(new InMemoryPersistence());
        $events = $this->eventsOf($this->narrating());

        $yielded = iterator_to_array($events);

        self::assertEquals(iterator_to_array($twin->events(self::start())), $yielded);
        self::assertSame([WorkflowStatus::Completed, self::RUN_ID], [$events->getReturn()->getStatus(), $events->getReturn()->getRunId()]);
    }

    public function test_events_admit_the_request_on_the_first_iteration_and_not_before(): void
    {
        $events = $this->eventsOf($this->narrating());
        $heldBefore = $this->held();

        $events->valid();

        self::assertNull($heldBefore);
        self::assertSame(WorkflowStatus::Running, $this->held()?->status);
    }

    public function test_events_tell_once_how_the_call_ended_when_the_stream_ends(): void
    {
        $events = $this->eventsOf($this->narrating());

        iterator_to_array($events);

        self::assertCount(1, $this->settled);
        self::assertSame([Outcome::Completed, 1], [$this->settled[0]->outcome, $this->settled[0]->executedAttempt]);
    }

    public function test_events_throw_the_refusal_of_neuron_on_the_first_iteration_and_settle_as_refused(): void
    {
        Execution::of($this->waiting(), self::start());
        $second = $this->eventsOf($this->waiting(), self::start('1-another-run'));

        try {
            $second->valid();
            self::fail('The refusal was not thrown.');
        } catch (RunInFlightException $refusal) {
            self::assertSame(self::RUN_ID, $refusal->runId);
        }

        self::assertCount(1, $this->settled);
        self::assertSame([Outcome::Refused, RefusalReason::RunInFlight->value], [$this->settled[0]->outcome, $this->settled[0]->code]);
    }

    public function test_events_throw_what_a_node_throws_and_settle_as_a_failure_with_its_class(): void
    {
        $events = $this->eventsOf($this->flaky());

        try {
            $events->valid();
            self::fail('The exception of the node was not thrown.');
        } catch (NodeFailed) {
            self::assertCount(1, $this->settled);
        }

        self::assertSame(
            [Outcome::Failed, NodeFailed::class, 1],
            [$this->settled[0]->outcome, $this->settled[0]->errorClass, $this->settled[0]->executedAttempt],
        );
    }

    public function test_a_caller_that_stops_before_the_end_is_told_nothing_of_how_the_call_ended_once_neuron_has_failed_the_run(): void
    {
        $events = $this->eventsOf($this->narrating());
        $events->current();

        unset($events);

        self::assertSame([null], $this->settled);
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_a_caller_that_stops_on_the_error_frame_of_a_node_that_threw_is_told_nothing_of_how_the_call_ended(): void
    {
        $events = $this->eventsOf($this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $events->next();
        self::assertEquals(new ProtocolEvent('error'), $events->current());

        unset($events);

        self::assertSame([null], $this->settled);
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_a_caller_that_stops_is_still_told_when_neuron_throws_while_it_releases_the_run(): void
    {
        $events = $this->eventsOf($this->narrating()->setStreamAdapter(static fn (): UnframableAdapter => new UnframableAdapter('error')));
        $events->current();

        $this->expectException(LogicException::class);

        try {
            unset($events);
        } finally {
            self::assertCount(1, $this->settled);
        }
    }

    public function test_a_caller_that_stops_on_the_last_event_is_told_nothing_when_a_listener_of_the_application_hid_the_end_of_the_segment(): void
    {
        $events = $this->eventsOf($this->waiting()->subscribe(WorkflowEnd::class, static function (): never {
            throw new RuntimeException('The audit log is unreachable.');
        }));
        self::assertInstanceOf(InterruptEvent::class, $events->current());

        unset($events);

        self::assertSame([null], $this->settled);
        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);
    }

    public function test_the_end_of_a_workflow_a_node_passes_on_is_not_taken_for_the_end_of_the_segment(): void
    {
        // Neuron's own report of the end is kept from the SDK: the last end it hears is the one the node passed on.
        $hidingItsOwnEnd = static function (WorkflowEnd $end): void {
            if ($end->source instanceof Workflow) {
                throw new RuntimeException('The audit log is unreachable.');
            }
        };
        $events = $this->eventsOf($this->bound((new Workflow())->addNodes([new EndForwardingNode()])->subscribe(WorkflowEnd::class, $hidingItsOwnEnd)));
        $events->current();

        unset($events);

        self::assertSame([null], $this->settled);
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_a_caller_that_stops_at_the_last_event_of_a_segment_that_suspended_is_settled_as_suspended(): void
    {
        $events = $this->eventsOf($this->waiting());
        self::assertInstanceOf(InterruptEvent::class, $events->current());

        unset($events);

        self::assertCount(1, $this->settled);
        self::assertSame(
            [Outcome::Suspended, null, 1],
            [$this->settled[0]->outcome, $this->settled[0]->errorClass, $this->settled[0]->executedAttempt],
        );
        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);
    }

    public function test_a_caller_that_stops_at_the_last_frame_of_a_segment_that_completed_is_settled_as_completed(): void
    {
        $events = $this->eventsOf($this->completing()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $events->next();
        self::assertEquals(new ProtocolEvent('end'), $events->current());

        unset($events);

        self::assertCount(1, $this->settled);
        self::assertSame([Outcome::Completed, 1], [$this->settled[0]->outcome, $this->settled[0]->executedAttempt]);
        self::assertNull($this->held());
    }

    public function test_an_exception_thrown_into_the_last_event_of_a_segment_that_suspended_is_thrown_back_and_settles_as_suspended(): void
    {
        $events = $this->eventsOf($this->waiting());
        self::assertInstanceOf(InterruptEvent::class, $events->current());
        $unencodable = new JsonException('The wire cannot carry this event.');

        try {
            $events->throw($unencodable);
            self::fail('The exception was not thrown back.');
        } catch (JsonException $thrown) {
            self::assertSame($unencodable, $thrown);
        }

        self::assertCount(1, $this->settled);
        self::assertSame(
            [Outcome::Suspended, null, 1],
            [$this->settled[0]?->outcome, $this->settled[0]?->errorClass, $this->settled[0]?->executedAttempt],
        );
        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);
    }

    public function test_an_exception_thrown_into_the_last_frame_of_a_segment_that_completed_is_thrown_back_and_settles_as_completed(): void
    {
        $events = $this->eventsOf($this->completing()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $events->next();
        self::assertEquals(new ProtocolEvent('end'), $events->current());
        $unencodable = new JsonException('The wire cannot carry this event.');

        try {
            $events->throw($unencodable);
            self::fail('The exception was not thrown back.');
        } catch (JsonException $thrown) {
            self::assertSame($unencodable, $thrown);
        }

        self::assertCount(1, $this->settled);
        self::assertSame(
            [Outcome::Completed, null, 1],
            [$this->settled[0]?->outcome, $this->settled[0]?->errorClass, $this->settled[0]?->executedAttempt],
        );
        self::assertNull($this->held());
    }

    #[DataProvider('lastFramesAnAdapterCannotBuild')]
    public function test_events_throw_what_the_stream_adapter_threw_after_neuron_closed_the_run_and_settle_as_neuron_closed_it(
        string $unframable,
        Outcome $closedAs,
    ): void {
        $workflow = $unframable === 'end' ? $this->completing() : $this->waiting();
        $events = $this->eventsOf($workflow->setStreamAdapter(static fn (): UnframableAdapter => new UnframableAdapter($unframable)));

        try {
            iterator_to_array($events, false);
            self::fail('The exception of the adapter was not thrown.');
        } catch (LogicException) {
            self::assertCount(1, $this->settled);
        }

        self::assertSame(
            [$closedAs, null, 1],
            [$this->settled[0]?->outcome, $this->settled[0]?->errorClass, $this->settled[0]?->executedAttempt],
        );
    }

    public function test_an_exception_thrown_into_the_events_fails_the_run_and_is_thrown_back(): void
    {
        $events = $this->eventsOf($this->narrating());
        $events->current();
        $unencodable = new JsonException('The wire cannot carry this event.');

        try {
            $events->throw($unencodable);
            self::fail('The exception was not thrown back.');
        } catch (JsonException $thrown) {
            self::assertSame($unencodable, $thrown);
        }

        self::assertCount(1, $this->settled);
        self::assertSame([Outcome::Failed, JsonException::class], [$this->settled[0]->outcome, $this->settled[0]->errorClass]);
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_an_exception_thrown_into_the_events_reaches_neuron_which_gives_the_error_frame_of_the_adapter_before_it_throws_back(): void
    {
        $events = $this->eventsOf($this->narrating()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $events->current();
        $unencodable = new JsonException('The wire cannot carry this event.');

        $given = $events->throw($unencodable);

        self::assertEquals(new ProtocolEvent('error'), $given);
        $this->expectExceptionObject($unencodable);
        $events->next();
    }

    public function test_events_try_a_conflict_of_the_ignition_once_more(): void
    {
        $events = $this->eventsOf($this->narrating()->setPersistence($this->storeWhereARivalRunEndsDuringIgnitions(1)));

        $yielded = iterator_to_array($events);

        self::assertCount(count(NarratingWorkflow::WORDS), $yielded);
        self::assertSame(Outcome::Completed, $this->settled[0]->outcome);
        self::assertSame(1, $this->bench()->executions->count(NarrateNode::class));
    }

    public function test_events_that_are_never_taken_ignite_nothing_and_tell_nothing(): void
    {
        $events = $this->eventsOf($this->narrating());

        unset($events);

        self::assertSame([], $this->settled);
        self::assertNull($this->held());
    }

    protected static function start(string $runId = self::RUN_ID): ExecutionRequest
    {
        return ExecutionRequest::start(new StartEvent(), $runId);
    }

    /**
     * The call as a caller takes it step by step. What the generator tells is kept, with the status the run
     * had in the store at that moment.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    protected function eventsOf(Workflow $workflow, ?ExecutionRequest $request = null): Generator
    {
        return Execution::events($workflow, $request ?? self::start(), function (?Settlement $ended): void {
            $this->settled[] = $ended;
            $this->heldWhenSettled[] = $this->held()?->status;
        });
    }

    /**
     * The run holding the workflow ID, as the store shows it now.
     */
    protected function held(): ?WorkflowRunSnapshot
    {
        return $this->completing()->inspect();
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
     * Streams three words, then completes.
     */
    protected function narrating(): Workflow
    {
        return $this->bound(new NarratingWorkflow($this->bench()->executions));
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

    public function test_a_workflow_is_released_with_the_call_that_executed_it(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-');
        $workflow = (new Workflow())->addNode(new CompleteNode(new ExecutionLog($log)));
        $released = WeakReference::create($workflow);
        $collecting = gc_enabled();
        gc_disable();

        Execution::of($workflow, ExecutionRequest::start(new StartEvent(), '1-released'));
        unset($workflow);
        $stillAlive = $released->get() instanceof Workflow;

        if ($collecting) {
            gc_enable();
        }

        unlink($log);

        self::assertFalse($stillAlive, 'The listener of the SDK must not keep the workflow it listens to alive.');
    }
}
