<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use Closure;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Runtime\LocalSegment;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Runtime\WorkflowBuilder;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\InPlace\WatchedQueue;
use NeuronCore\Cloud\Tests\Stub\Local\PickyQueue;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\Cloud\Tests\Stub\Units\RecordingPersistence;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Local\LocalTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Units\Queued;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function base64_decode;
use function iterator_to_array;
use function time;
use function unserialize;

/**
 * A segment the application's own code issued: run in the calling process
 * between two notices handed to the queue, or handed to the queue itself.
 */
class LocalSegmentTest extends LocalTestCase
{
    protected const LEASE = 90;

    protected const PAYMENT = ['amount' => 100];

    public function test_a_segment_run_in_place_hands_the_queue_an_announcement_then_an_observe(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->run();

        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsQueued());
    }

    public function test_a_segment_run_in_place_makes_no_call_to_the_platform(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->run();

        self::assertSame([], $bench->platform->received());
    }

    public function test_the_announcement_names_the_segment_of_the_application_and_the_lease_of_its_definition(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->run();

        $announcement = $this->queue->dispatched()[0]->body;
        self::assertStringStartsWith('inv_', $announcement['invocationId']);
        self::assertSame(
            ['start', Origin::APPLICATION, 'greeting', self::WORKFLOW_ID, self::RUN_ID, null, self::LEASE],
            [
                $announcement['verb'],
                $announcement['origin'],
                $announcement['definition'],
                $announcement['workflowId'],
                $announcement['runId'],
                $announcement['attempt'],
                $announcement['leaseSeconds'],
            ],
        );
    }

    public function test_the_announcement_is_in_the_queue_before_the_segment_executes(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $queuedWhenTheSegmentEnded = null;
        $noteTheQueue = function () use (&$queuedWhenTheSegmentEnded): void {
            $queuedWhenTheSegmentEnded = $this->verbsQueued();
        };
        $definition = $this->define('audited', static fn (): Workflow => (new CompletingWorkflow($bench->executions))->subscribe(
            WorkflowEnd::class,
            $noteTheQueue,
        ));

        $this->startOf($definition)->run();

        self::assertSame([Verb::Announce], $queuedWhenTheSegmentEnded);
    }

    public function test_the_observe_carries_how_the_segment_ended_under_the_invocation_id_of_the_announcement(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->run();

        [$announce, $observe] = $this->queue->dispatched();
        self::assertSame(
            [
                'invocationId' => $announce->body['invocationId'],
                'verb' => 'start',
                'outcome' => 'completed',
                'code' => null,
                'errorClass' => null,
                'executedAttempt' => 1,
            ],
            $observe->body,
        );
    }

    public function test_the_observe_of_a_segment_whose_node_threw_says_failed_with_the_exception_class(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);

        $execution = $this->startOf($this->flaky())->run();

        $observe = $this->queue->dispatched()[1]->body;
        self::assertSame(Outcome::Failed, $execution->settlement->outcome);
        self::assertSame(['failed', NodeFailed::class, 1], [$observe['outcome'], $observe['errorClass'], $observe['executedAttempt']]);
    }

    public function test_the_observe_of_a_start_neuron_refused_carries_the_reason(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $this->startOf($order)->run();

        $refused = $this->startOf($order, runId: '1-another-run')->run();

        $observe = $this->queue->dispatched()[3]->body;
        self::assertSame(Outcome::Refused, $refused->settlement->outcome);
        self::assertSame(['refused', RefusalReason::RunInFlight->value, null], [$observe['outcome'], $observe['code'], $observe['executedAttempt']]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    public function test_a_worker_reports_a_segment_run_in_place_from_its_two_notices(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $this->startOf($this->order())->run();

        [, $report] = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Verb::Start, $report->verb);
        self::assertSame(Origin::APPLICATION, $report->origin);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame(1, $report->executedAttempt);
        self::assertSame('suspended', $report->run['status']);
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
    }

    public function test_a_start_run_in_place_is_not_entered_in_the_ledger(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $this->startOf($this->order())->run();

        [, $report] = $this->queue->runAll($bench->cloud);

        self::assertFalse((new Ledger($bench->persistence))->has(self::WORKFLOW_ID, self::RUN_ID));
        self::assertInstanceOf(Report::class, $report);
        self::assertFalse($report->claimed);
    }

    public function test_a_queue_that_does_not_take_the_announcement_fails_the_run_as_dispatch_failed(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting(), queue: new UnreachableQueue());

        $thrown = self::thrownBy(static fn (): mixed => $segment->run());

        self::assertSame(Code::DispatchFailed, $thrown?->reason);
        self::assertInstanceOf(RuntimeException::class, $thrown->getPrevious());
    }

    public function test_nothing_ignites_when_the_queue_does_not_take_the_announcement(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting(), queue: new UnreachableQueue());

        self::thrownBy(static fn (): mixed => $segment->run());

        self::assertSame(0, $bench->executions->total());
        self::assertNull(($bench->persistence)()->get(self::WORKFLOW_ID, '__control'));
    }

    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_state(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting(), queue: new PickyQueue($this->queue, Verb::Observe));

        $state = $segment->run()->state();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame([Verb::Announce], $this->verbsQueued());
    }

    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_exception_of_the_node(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $execution = $this->startOf($this->flaky(), queue: new PickyQueue($this->queue, Verb::Observe))->run();

        $this->expectException(NodeFailed::class);

        $execution->state();
    }

    public function test_the_callback_adjusts_the_workflow_before_it_executes(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $endedUnderTheCallback = false;

        $this->startOf($this->greeting())->run(static function (Workflow $workflow) use (&$endedUnderTheCallback): void {
            $workflow->subscribe(WorkflowEnd::class, static function () use (&$endedUnderTheCallback): void {
                $endedUnderTheCallback = true;
            });
        });

        self::assertTrue($endedUnderTheCallback);
    }

    public function test_a_callback_that_throws_leaves_nothing_announced_and_nothing_run(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting());

        try {
            $segment->run(static function (): never {
                throw new RuntimeException('The request names no stream.');
            });
            self::fail('The exception of the callback was not thrown.');
        } catch (RuntimeException $thrown) {
            self::assertSame('The request names no stream.', $thrown->getMessage());
        }

        self::assertSame([], $this->queue->dispatched());
        self::assertSame(0, $bench->executions->total());
    }

    public function test_a_dispatched_start_is_a_start_of_the_application_with_a_use_by_time_one_hour_ahead(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $before = time();

        $this->startOf($this->greeting())->dispatch();

        [$start] = $this->queue->dispatched();
        self::assertStringStartsWith('inv_', $start->invocationId);
        self::assertSame(
            [Verb::Start, Origin::APPLICATION, 'greeting', self::WORKFLOW_ID, self::RUN_ID, null],
            [$start->verb, $start->origin, $start->definition, $start->workflowId, $start->runId, $start->attempt],
        );
        self::assertGreaterThanOrEqual($before + 3600, $start->expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $start->expiresAt);
    }

    public function test_a_dispatched_start_carries_its_event_serialized(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->dispatch();

        [$start] = $this->queue->dispatched();
        self::assertEquals(new StartEvent(), unserialize((string) base64_decode((string) $start->event, true)));
        self::assertNull($start->payload);
        self::assertNull($start->signal);
    }

    public function test_dispatch_returns_the_run_id_and_executes_nothing(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $runId = $this->startOf($this->greeting())->dispatch();

        self::assertSame(self::RUN_ID, $runId);
        self::assertSame(0, $bench->executions->total());
        self::assertSame([Verb::Start], $this->verbsQueued());
    }

    public function test_a_dispatched_segment_makes_no_call_to_the_platform(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->greeting())->dispatch();

        self::assertSame([], $bench->platform->received());
    }

    public function test_a_dispatched_start_reads_the_snapshot_once_and_writes_nothing(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $store = new RecordingPersistence(($bench->persistence)());

        $this->startOf($this->greeting(), store: $store)->dispatch();

        self::assertSame(['get'], $store->operations());
    }

    public function test_a_dispatched_start_that_meets_a_run_is_refused_as_run_in_flight_and_is_not_queued(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $this->startOf($order)->run();
        $second = $this->startOf($order, runId: '1-another-run');

        try {
            $second->dispatch();
            self::fail('The start was dispatched although a run holds the workflow ID.');
        } catch (RunInFlightException $refusal) {
            self::assertSame(self::WORKFLOW_ID, $refusal->workflowId);
            self::assertSame(self::RUN_ID, $refusal->runId);
            self::assertSame(WorkflowStatus::Suspended, $refusal->status);
            self::assertSame('1-another-run', $refusal->reservedRunId);
        }

        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsQueued(), 'Only the notices of the first start are queued.');
    }

    public function test_a_queue_that_does_not_take_the_segment_fails_the_dispatch_as_dispatch_failed(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting(), queue: new UnreachableQueue());

        $thrown = self::thrownBy(static fn (): string => $segment->dispatch());

        self::assertSame(Code::DispatchFailed, $thrown?->reason);
        self::assertInstanceOf(RuntimeException::class, $thrown->getPrevious());
    }

    #[DataProvider('backends')]
    public function test_a_worker_claims_and_runs_a_dispatched_start(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->startOf($this->greeting())->dispatch();

        [$report] = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertTrue($report->claimed);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_a_dispatched_answer_carries_its_payload_its_fence_and_an_expiry_a_day_ahead(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $waiting = $bench->cloud->invoke(Queued::start('order'));
        $before = time();

        $runId = $this->segment(Verb::Answer, $order, ExecutionRequest::resume(self::PAYMENT, self::RUN_ID, $waiting->run['attempt']))->dispatch();

        [$answer] = $this->queue->dispatched();
        self::assertSame(self::RUN_ID, $runId);
        self::assertSame(
            [Verb::Answer, Origin::APPLICATION, self::RUN_ID, 1, self::PAYMENT, null, null],
            [$answer->verb, $answer->origin, $answer->runId, $answer->attempt, $answer->payload, $answer->signal, $answer->event],
        );
        self::assertGreaterThanOrEqual($before + 86400, $answer->expiresAt);
        self::assertLessThanOrEqual(time() + 86400, $answer->expiresAt);
    }

    public function test_a_dispatched_signal_carries_its_event_name_with_its_payload_and_its_fence(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $waiting = $bench->cloud->invoke(Queued::start('order'));
        $signal = ExecutionRequest::signal(WaitingWorkflow::EVENT, self::PAYMENT, self::RUN_ID, $waiting->run['attempt']);

        $this->segment(Verb::Signal, $order, $signal)->dispatch();

        [$queued] = $this->queue->dispatched();
        self::assertSame(
            [Verb::Signal, WaitingWorkflow::EVENT, self::PAYMENT, 1, null],
            [$queued->verb, $queued->signal, $queued->payload, $queued->attempt, $queued->event],
        );
    }

    public function test_a_dispatched_answer_touches_no_store(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $waiting = $bench->cloud->invoke(Queued::start('order'));
        $store = new RecordingPersistence(($bench->persistence)());
        $answer = ExecutionRequest::resume(self::PAYMENT, self::RUN_ID, $waiting->run['attempt']);

        $this->segment(Verb::Answer, $order, $answer, store: $store)->dispatch();

        self::assertSame([], $store->operations());
    }

    #[DataProvider('backends')]
    public function test_a_worker_executes_a_dispatched_answer_under_the_fence_it_carries(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $order = $this->order();
        $waiting = $bench->cloud->invoke(Queued::start('order'));
        $this->segment(Verb::Answer, $order, ExecutionRequest::resume(self::PAYMENT, self::RUN_ID, $waiting->run['attempt']))->dispatch();

        [$report] = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Verb::Answer, $report->verb);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame(2, $report->executedAttempt);
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
    }

    public function test_an_answer_run_in_place_is_announced_with_its_fence(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $order = $this->order();
        $waiting = $bench->cloud->invoke(Queued::start('order'));

        $execution = $this->segment(Verb::Answer, $order, ExecutionRequest::resume(self::PAYMENT, self::RUN_ID, $waiting->run['attempt']))->run();

        [$announce, $observe] = $this->queue->dispatched();
        self::assertSame(['answer', 1], [$announce->body['verb'], $announce->body['attempt']]);
        self::assertSame(['answer', 'suspended', 2], [$observe->body['verb'], $observe->body['outcome'], $observe->body['executedAttempt']]);
        self::assertSame(self::PAYMENT, $execution->state()->get('payment'));
    }

    public function test_a_streamed_segment_is_announced_before_its_generator_is_returned_and_ignites_nothing_yet(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $this->startOf($this->narration())->events();

        self::assertSame([Verb::Announce], $this->verbsQueued());
        self::assertSame(0, $bench->executions->total());
        self::assertNull(($bench->persistence)()->get(self::WORKFLOW_ID, '__control'));
    }

    public function test_a_streamed_segment_hands_the_queue_its_observe_when_the_stream_ends_and_not_before(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->startOf($this->narration())->events();
        $events->current();
        $queuedMidStream = $this->verbsQueued();

        iterator_to_array($events);

        self::assertSame([Verb::Announce], $queuedMidStream);
        [$announce, $observe] = $this->queue->dispatched();
        self::assertSame(
            [
                'invocationId' => $announce->body['invocationId'],
                'verb' => 'start',
                'outcome' => 'completed',
                'code' => null,
                'errorClass' => null,
                'executedAttempt' => 1,
            ],
            $observe->body,
        );
        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsQueued());
    }

    public function test_a_streamed_segment_makes_no_call_to_the_platform(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        iterator_to_array($this->startOf($this->narration())->events());

        self::assertSame([], $bench->platform->received());
    }

    public function test_a_queue_that_does_not_take_the_announcement_fails_the_stream_before_a_generator_exists(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->narration(), queue: new UnreachableQueue());

        $thrown = self::thrownBy($segment->events(...));

        self::assertSame(Code::DispatchFailed, $thrown?->reason);
        self::assertSame(0, $bench->executions->total());
        self::assertNull(($bench->persistence)()->get(self::WORKFLOW_ID, '__control'));
    }

    public function test_the_callback_adjusts_the_workflow_of_a_stream_before_its_generator_is_returned(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $endedUnderTheCallback = false;
        $calls = 0;

        $events = $this->startOf($this->narration())->events(static function (Workflow $workflow) use (&$endedUnderTheCallback, &$calls): void {
            $calls++;
            $workflow->subscribe(WorkflowEnd::class, static function () use (&$endedUnderTheCallback): void {
                $endedUnderTheCallback = true;
            });
        });
        $callsBeforeTheFirstIteration = $calls;
        iterator_to_array($events);

        self::assertSame([1, 1], [$callsBeforeTheFirstIteration, $calls]);
        self::assertTrue($endedUnderTheCallback);
    }

    public function test_a_stream_whose_consumer_stops_early_hands_an_observe_with_no_outcome_once_neuron_has_failed_the_run(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $queue = new WatchedQueue($bench->executions);
        $heldAtTheObserve = null;
        $queue->beforeTaking(static function (Invocation $message) use ($bench, &$heldAtTheObserve): void {
            if ($message->verb === Verb::Observe) {
                $heldAtTheObserve = (new WorkflowEngine(($bench->persistence)()))->inspect(self::WORKFLOW_ID)?->status;
            }
        });
        $events = $this->startOf($this->narration(), queue: $queue)->events();
        $events->current();

        unset($events);

        [$announce, $observe] = $queue->taken();
        self::assertCount(2, $queue->taken());
        self::assertSame(Verb::Observe, $observe->verb);
        self::assertSame(['invocationId' => $announce->body['invocationId'], 'verb' => 'start'], $observe->body);
        self::assertSame(WorkflowStatus::Failed, $heldAtTheObserve);
    }

    public function test_the_observe_of_a_stream_whose_consumer_stopped_early_is_reported_as_observed_with_the_run_the_store_shows(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->startOf($this->narration())->events();
        $events->current();
        unset($events);

        [, $report] = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Verb::Start, Outcome::Observed, null, null, null, false],
            [$report->verb, $report->outcome, $report->code, $report->errorClass, $report->executedAttempt, $report->claimed],
        );
        self::assertSame([self::RUN_ID, 'failed', 1], [$report->run['runId'], $report->run['status'], $report->run['attempt']]);
    }

    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_state_of_a_stream(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->startOf($this->narration(), queue: new PickyQueue($this->queue, Verb::Observe))->events();

        iterator_to_array($events);

        self::assertSame(WorkflowStatus::Completed, $events->getReturn()->getStatus());
        self::assertSame([Verb::Announce], $this->verbsQueued());
    }

    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_exception_of_a_node_from_the_consumer_of_a_stream(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->startOf($this->flaky(), queue: new PickyQueue($this->queue, Verb::Observe))->events();

        $this->expectException(NodeFailed::class);

        $events->valid();
    }

    public function test_a_start_streamed_in_place_is_not_entered_in_the_ledger(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        iterator_to_array($this->startOf($this->order())->events());

        [, $report] = $this->queue->runAll($bench->cloud);

        self::assertFalse((new Ledger($bench->persistence))->has(self::WORKFLOW_ID, self::RUN_ID));
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, false], [$report->outcome, $report->claimed]);
    }

    #[DataProvider('twoUsesOfOneSegment')]
    public function test_a_second_use_of_a_segment_is_refused_as_segment_used(string $first, string $second): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $segment = $this->startOf($this->greeting());
        $segment->{$first}();

        $thrown = self::thrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $thrown?->reason);
    }

    /**
     * @return array<string, array{string, string}> the method of the first use, and of the second
     */
    public static function twoUsesOfOneSegment(): array
    {
        return [
            'run, then run' => ['run', 'run'],
            'run, then dispatch' => ['run', 'dispatch'],
            'dispatch, then run' => ['dispatch', 'run'],
            'dispatch, then dispatch' => ['dispatch', 'dispatch'],
            'events, then events' => ['events', 'events'],
            'events, then run' => ['events', 'run'],
            'events, then dispatch' => ['events', 'dispatch'],
            'run, then events' => ['run', 'events'],
            'dispatch, then events' => ['dispatch', 'events'],
        ];
    }

    /**
     * The start of a new run under the run ID of these tests, as ManagedWorkflow prepares one.
     */
    protected function startOf(
        Definition $definition,
        string $runId = self::RUN_ID,
        ?DispatcherInterface $queue = null,
        ?PersistenceInterface $store = null,
    ): LocalSegment {
        return $this->segment(Verb::Start, $definition, ExecutionRequest::start(new StartEvent(), $runId), $queue, $store);
    }

    /**
     * @param DispatcherInterface|null $queue null: the queue of the bench
     * @param PersistenceInterface|null $store null: the store of the bench
     */
    protected function segment(
        Verb $verb,
        Definition $definition,
        ExecutionRequest $request,
        ?DispatcherInterface $queue = null,
        ?PersistenceInterface $store = null,
    ): LocalSegment {
        $persistence = $store instanceof PersistenceInterface ? static fn (): PersistenceInterface => $store : $this->bench->persistence;
        $builder = new WorkflowBuilder($persistence);
        $workflow = $builder->build($definition, self::WORKFLOW_ID, $request->runId);

        return new LocalSegment($verb, $definition, self::WORKFLOW_ID, $workflow, $request, $queue ?? $this->queue, $builder);
    }

    /**
     * Completes in one segment.
     */
    protected function greeting(): Definition
    {
        $executions = $this->bench->executions;

        return $this->define('greeting', static fn (): Workflow => new CompletingWorkflow($executions));
    }

    /**
     * Streams three words, then completes.
     */
    protected function narration(): Definition
    {
        $executions = $this->bench->executions;

        return $this->define('narration', static fn (): Workflow => new NarratingWorkflow($executions));
    }

    /**
     * Waits for the event "payment.received", then for a timer that is due.
     */
    protected function order(): Definition
    {
        $executions = $this->bench->executions;

        return $this->define('order', static fn (): Workflow => new WaitingWorkflow($executions));
    }

    /**
     * Its node throws the first time it runs.
     */
    protected function flaky(): Definition
    {
        $executions = $this->bench->executions;

        return $this->define('flaky', static fn (): Workflow => new FlakyWorkflow($executions));
    }

    /**
     * A definition the workers of the bench know as well.
     *
     * @param Closure(): Workflow $factory
     */
    protected function define(string $name, Closure $factory): Definition
    {
        $this->bench->cloud->register($name, $factory, leaseTimeout: self::LEASE);

        return new Definition($name, 1, $factory, self::LEASE);
    }
}
