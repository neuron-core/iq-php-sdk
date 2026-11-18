<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use DateTimeImmutable;
use Generator;
use JsonException;
use LogicException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Execution;
use NeuronCore\IQ\Runtime\Settlement;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\FlakyNode;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\NarrateNode;
use NeuronCore\IQ\Tests\Stub\NarratingWorkflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\IQ\Tests\Stub\Units\EndForwardingNode;
use NeuronCore\IQ\Tests\Stub\Units\FramingAdapter;
use NeuronCore\IQ\Tests\Stub\Units\NarratingWorkflowSeenAs;
use NeuronCore\IQ\Tests\Stub\Units\RunOverridingWorkflow;
use NeuronCore\IQ\Tests\Stub\Units\UnframableAdapter;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use WeakReference;

use function array_map;
use function count;
use function gc_collect_cycles;
use function iterator_to_array;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function ini_set;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * One request given to Neuron: the settlement says how the call ended, and
 * the caller gets back what a direct call to Neuron would have given it.
 */
class ExecutionTest extends IQTestCase
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

    public function test_a_segment_that_fails_is_told_as_failed_when_neuron_gives_its_first_error_frame(): void
    {
        $events = $this->eventsOf($this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $toldAtTheFirstFrame = $this->toldAt($events, 'start');

        $events->next();

        self::assertEquals(new ProtocolEvent('error'), $events->current());
        self::assertSame([], $toldAtTheFirstFrame);
        self::assertCount(1, $this->settled);
        self::assertSame(
            [Outcome::Failed, NodeFailed::class, 1, null],
            [$this->settled[0]?->outcome, $this->settled[0]?->errorClass, $this->settled[0]?->executedAttempt, $this->settled[0]?->cause],
        );
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_a_caller_that_stops_on_an_error_frame_is_told_nothing_more(): void
    {
        $events = $this->eventsOf($this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $this->toldAt($events, 'error');

        unset($events);

        self::assertCount(1, $this->settled);
        self::assertSame(Outcome::Failed, $this->settled[0]?->outcome);
    }

    public function test_a_caller_that_reads_the_error_frames_to_the_exception_is_told_nothing_more(): void
    {
        $events = $this->eventsOf($this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));
        $this->toldAt($events, 'error');

        try {
            $events->next();
            self::fail('The exception of the node was not thrown.');
        } catch (NodeFailed) {
            self::assertCount(1, $this->settled);
        }
    }

    /**
     * An exception keeps the arguments of every frame of its trace unless PHP is told to leave them out. Neuron
     * holds the one of the node while it gives the error frames, so a stream that was given to the function that
     * reads it is held by that exception: PHP does not destroy it when that function lets it go.
     */
    #[DataProvider('traceSettings')]
    public function test_a_failure_is_told_before_the_caller_can_stop_on_an_error_frame_whatever_a_trace_keeps(string $ignoreArguments): void
    {
        $setting = ini_set('zend.exception_ignore_args', $ignoreArguments);
        $collecting = gc_enabled();
        gc_disable();

        try {
            $this->toldAt($this->eventsOf($this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter())), 'error');
            $told = $this->settled;
        } finally {
            if ($collecting) {
                gc_enable();
            }

            if ($setting !== false) {
                ini_set('zend.exception_ignore_args', $setting);
            }
        }

        self::assertCount(1, $told);
        self::assertSame([Outcome::Failed, NodeFailed::class], [$told[0]?->outcome, $told[0]?->errorClass]);
    }

    /**
     * Neuron reports a listener that threw with the event it reports a failed segment with, and goes on.
     */
    public function test_a_listener_of_the_application_that_throws_while_the_segment_goes_on_is_not_taken_for_its_failure(): void
    {
        $reported = 0;
        $workflow = $this->narrating()
            ->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter())
            ->subscribe(WorkflowStart::class, static fn () => throw new RuntimeException('The audit log is unreachable.'))
            ->subscribe(WorkflowError::class, static function () use (&$reported): void {
                $reported++;
            });
        $events = $this->eventsOf($workflow);

        $toldAtTheFirstItem = $this->toldAt($events, 'item');

        while ($events->valid()) {
            $events->next();
        }

        self::assertSame(1, $reported);
        self::assertSame([], $toldAtTheFirstItem);
        self::assertCount(1, $this->settled);
        self::assertSame(Outcome::Completed, $this->settled[0]?->outcome);
    }

    /**
     * @return array<string, array{int, string, Outcome|null}> the attempts and the run ID suffix by which the run the store
     *                                                         shows as failed differs from the segment, and what the stream
     *                                                         tells for it while the segment goes on
     */
    public static function failedRunsTheStoreShows(): array
    {
        return [
            'the run of this segment, at its attempt' => [0, '', Outcome::Failed],
            'the run of this segment, at the attempt of a process that took it over' => [1, '', null],
            'another run of the workflow ID, at the same attempt' => [0, '-replaced', null],
        ];
    }

    /**
     * A segment that goes on after a listener threw may have lost its run meanwhile, to a process that failed
     * it in its turn: that failure is not this segment's.
     */
    #[DataProvider('failedRunsTheStoreShows')]
    public function test_only_a_failed_run_that_is_the_segment_at_its_attempt_is_taken_for_the_failure_of_the_segment(
        int $attemptsLater,
        string $runIdSuffix,
        ?Outcome $told,
    ): void {
        $narrating = new NarratingWorkflowSeenAs($this->bench()->executions);
        $narrating->seenAs = static fn (WorkflowRunSnapshot $held): WorkflowRunSnapshot => new WorkflowRunSnapshot(
            $held->runId . $runIdSuffix,
            WorkflowStatus::Failed,
            $held->executionAttempt + $attemptsLater,
            null,
            $held->workflowId,
            $held->startEvent,
        );
        $events = $this->eventsOf(
            $this->bound($narrating)
                ->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter())
                ->subscribe(WorkflowStart::class, static fn () => throw new RuntimeException('The audit log is unreachable.')),
        );

        $toldAtTheFirstItem = $this->toldAt($events, 'item');

        self::assertSame($told instanceof Outcome ? [$told] : [], array_map(static fn (?Settlement $ended): ?Outcome => $ended?->outcome, $toldAtTheFirstItem));
    }

    /**
     * As with the end of a segment: the SDK hears of the failure from Neuron, after the listeners of the
     * application. One of them that throws on that event keeps it from the SDK.
     */
    public function test_a_caller_that_stops_on_an_error_frame_is_told_nothing_when_a_listener_of_the_application_hid_the_failure(): void
    {
        $events = $this->eventsOf(
            $this->flaky()
                ->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter())
                ->subscribe(WorkflowError::class, static fn () => throw new RuntimeException('The audit log is unreachable.')),
        );
        $toldAtTheErrorFrame = $this->toldAt($events, 'error');

        unset($events);
        // Where PHP keeps the arguments of a trace, the exception Neuron holds keeps the stream until then.
        gc_collect_cycles();

        self::assertSame([[], [null]], [$toldAtTheErrorFrame, $this->settled]);
        self::assertSame([WorkflowStatus::Failed], $this->heldWhenSettled);
    }

    public function test_a_store_that_does_not_answer_when_the_segment_fails_leaves_the_failure_to_the_end_of_the_stream(): void
    {
        $failed = false;
        $store = new InterceptedPersistence(($this->bench()->persistence)(), static function (string $operation) use (&$failed): void {
            if ($operation === 'get' && $failed) {
                throw new StoreUnavailable('The store went away.');
            }

            // The node throws in the first step: the next write is the one that marks the run failed.
            $failed = $failed || $operation === 'writeIfUnchanged';
        });
        // Counted and not kept: the event holds the exception, which may hold the stream.
        $failuresForwarded = 0;
        $application = new class ($failuresForwarded) implements EventDispatcherInterface {
            public function __construct(protected int &$failures)
            {
            }

            public function dispatch(object $event): object
            {
                $this->failures += (int) ($event instanceof WorkflowError);

                return $event;
            }
        };
        $events = $this->eventsOf(
            $this->flaky()->setPersistence($store)->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter())->setEventDispatcher($application),
        );

        $toldAtTheErrorFrame = $this->toldAt($events, 'error');
        unset($events);
        gc_collect_cycles();

        self::assertTrue($failed);
        self::assertSame([[], [null]], [$toldAtTheErrorFrame, $this->settled]);
        self::assertSame(1, $failuresForwarded, 'The failure did not reach the dispatcher of the application.');
    }

    /**
     * A listener that throws on the event of a node is reported under that node: the SDK does not ask the store
     * whether the segment failed, as it does for an error the workflow itself reports.
     */
    public function test_the_store_is_not_read_for_a_listener_that_throws_on_the_event_of_a_node(): void
    {
        $reads = ['a quiet segment' => 0, 'a segment under a listener that throws at each node' => 0];

        foreach ($reads as $segment => $none) {
            $store = new InterceptedPersistence(new InMemoryPersistence(), static function (string $operation) use (&$reads, $segment): void {
                $reads[$segment] += (int) ($operation === 'get');
            });
            $workflow = $this->narrating()->setPersistence($store);

            if ($segment !== 'a quiet segment') {
                $workflow->subscribe(WorkflowNodeStart::class, static fn () => throw new RuntimeException('The audit log is unreachable.'));
            }

            iterator_to_array($this->eventsOf($workflow), false);
        }

        self::assertSame([Outcome::Completed, Outcome::Completed], [$this->settled[0]?->outcome, $this->settled[1]?->outcome]);
        self::assertSame($reads['a quiet segment'], $reads['a segment under a listener that throws at each node']);
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
     * Take a stream up to a frame of the stream adapter, as a function that was given the stream does.
     *
     * @param Generator<int, object, mixed, WorkflowState> $events
     * @return array<int, Settlement|null> what the stream had told of how its call ended when it gave that frame
     */
    protected function toldAt(Generator $events, string $frame): array
    {
        while ($events->valid()) {
            $current = $events->current();

            if ($current instanceof ProtocolEvent && $current->type === $frame) {
                return $this->settled;
            }

            $events->next();
        }

        self::fail("The stream ended without giving '{$frame}'.");
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

    /**
     * @return array<string, array{string}> the values of zend.exception_ignore_args
     */
    public static function traceSettings(): array
    {
        return ['PHP keeps the arguments of a trace' => ['0'], 'PHP leaves the arguments out of a trace' => ['1']];
    }

    /**
     * @return array<string, array{string, string}> a call, and the value of zend.exception_ignore_args it is made under
     */
    public static function callsUnderEachTraceSetting(): array
    {
        $cases = [];

        foreach (['PHP keeps the arguments of a trace' => '0', 'PHP leaves the arguments out of a trace' => '1'] as $setting => $value) {
            foreach (['a call that completes', 'a call whose node throws', 'a call Neuron throws for before a segment opens'] as $call) {
                $cases["{$call}, where {$setting}"] = [$call, $value];
            }
        }

        return $cases;
    }

    /**
     * An exception keeps the arguments of every frame of its trace unless PHP is told to leave them out, and the
     * workflow is one of them. Nothing the SDK keeps for itself may hold that exception: the workflow, and the
     * store connection it holds, must go when the caller lets go of what the call returned.
     */
    #[DataProvider('callsUnderEachTraceSetting')]
    public function test_a_workflow_is_released_with_what_its_call_returned_whatever_a_trace_keeps(string $call, string $ignoreArguments): void
    {
        $workflow = match ($call) {
            'a call that completes' => $this->completing(),
            'a call whose node throws' => $this->flaky(),
            // Neuron throws for a workflow with no workflow ID, before it opens a segment.
            default => (new Workflow())->addNode(new CompleteNode($this->bench()->executions)),
        };
        $released = WeakReference::create($workflow);
        $setting = ini_set('zend.exception_ignore_args', $ignoreArguments);
        $collecting = gc_enabled();
        gc_disable();

        try {
            $execution = Execution::of($workflow, self::start());
            $outcome = $execution->settlement->outcome;
            $held = $execution->settlement->cause;
            unset($workflow, $execution, $held);
            $stillAlive = $released->get() instanceof Workflow;
        } finally {
            if ($collecting) {
                gc_enable();
            }

            if ($setting !== false) {
                ini_set('zend.exception_ignore_args', $setting);
            }
        }

        self::assertSame(
            ['a call that completes' => Outcome::Completed, 'a call whose node throws' => Outcome::Failed][$call] ?? Outcome::Aborted,
            $outcome,
        );
        self::assertFalse($stillAlive, 'Something the SDK keeps holds the exception of the call, and with it the workflow.');
    }

    /**
     * The SDK keeps how a stream failed from the moment Neuron said it, in a listener the workflow holds. Kept
     * with what the node threw, that record would hold the workflow through the arguments of the trace.
     */
    #[DataProvider('traceSettings')]
    public function test_a_workflow_is_released_with_the_stream_that_failed_whatever_a_trace_keeps(string $ignoreArguments): void
    {
        $workflow = $this->flaky()->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter());
        $released = WeakReference::create($workflow);
        $setting = ini_set('zend.exception_ignore_args', $ignoreArguments);
        $collecting = gc_enabled();
        gc_disable();

        try {
            $events = Execution::events($workflow, self::start(), static function (): void {
            });

            try {
                while ($events->valid()) {
                    $events->next();
                }
            } catch (NodeFailed $thrown) {
                unset($thrown);
            }

            unset($workflow, $events);
            $stillAlive = $released->get() instanceof Workflow;
        } finally {
            if ($collecting) {
                gc_enable();
            }

            if ($setting !== false) {
                ini_set('zend.exception_ignore_args', $setting);
            }
        }

        self::assertFalse($stillAlive, 'Something the SDK keeps holds the exception of the stream, and with it the workflow.');
    }

    public function test_a_workflow_is_released_with_the_call_that_executed_it(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'iq-');
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
