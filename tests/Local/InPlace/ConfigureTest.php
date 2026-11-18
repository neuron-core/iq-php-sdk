<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Tests\Stub\InPlace\SilentAdapter;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\Matrix\MatrixWorkflow;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\InPlace\Stage;
use NeuronCore\IQ\Tests\Support\InPlace\TracedCallback;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function count;
use function time;

/**
 * configure() adjusts the copy of the workflow that run() executes in the
 * calling process, and no other: a streaming endpoint hooks its response onto
 * that copy, and a worker has no response to write to. What section 9.1 of
 * the design imposes on every workflow stays the SDK's, whatever the callback sets.
 */
class ConfigureTest extends InPlaceTestCase
{
    #[DataProvider('preparedKinds')]
    public function test_run_executes_the_workflow_the_callback_adjusted(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $stage->play();

        self::assertSame(1, $callback->segmentsHeard);
    }

    #[DataProvider('preparedKinds')]
    public function test_run_gives_the_callback_one_workflow_bound_to_the_workflow_id(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $stage->play();

        self::assertCount(1, $callback->adjusted);
        self::assertInstanceOf(MatrixWorkflow::class, $callback->adjusted[0]);
        self::assertSame(Stage::WORKFLOW_ID, $callback->adjusted[0]->getWorkflowId());
    }

    #[DataProvider('preparedKinds')]
    public function test_the_callback_is_not_called_while_the_segment_is_prepared(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $stage->prepare();

        self::assertSame([], $callback->adjusted);
    }

    #[DataProvider('preparedKinds')]
    public function test_the_callback_is_applied_before_the_announcement_reaches_the_queue(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $queuedBefore = count($stage->queue->taken());
        $queuedWhenApplied = null;
        $stage->thread->configure(static function () use ($stage, &$queuedWhenApplied): void {
            $queuedWhenApplied = count($stage->queue->taken());
        });

        $stage->play();

        self::assertSame($queuedBefore, $queuedWhenApplied);
    }

    #[DataProvider('preparedKinds')]
    public function test_dispatch_never_calls_the_callback(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $stage->prepare()->dispatch();

        self::assertSame([], $callback->adjusted);
    }

    #[DataProvider('preparedKinds')]
    public function test_the_worker_that_runs_a_dispatched_segment_executes_a_copy_the_callback_never_adjusted(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());
        $stage->prepare()->dispatch();
        $bodiesRun = $stage->bench->executions->total();

        $stage->drain();

        self::assertSame(2, $stage->bench->executions->total() - $bodiesRun, 'The worker ran the segment.');
        self::assertSame([[], 0], [$callback->adjusted, $callback->segmentsHeard]);
    }

    #[DataProvider('backends')]
    public function test_recover_executes_its_wake_on_a_copy_the_callback_never_adjusted(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Recovery);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame([[], 0], [$callback->adjusted, $callback->segmentsHeard]);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_segment_keeps_the_callback_that_was_set_when_it_was_prepared(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $setBefore = new TracedCallback();
        $setAfter = new TracedCallback();
        $stage->thread->configure($setBefore->closure());
        $prepared = $stage->prepare();
        $stage->thread->configure($setAfter->closure());

        $stage->play($prepared->run(...));

        self::assertSame([1, 0], [$setBefore->segmentsHeard, $setAfter->segmentsHeard]);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_later_callback_replaces_the_earlier_one_for_the_segments_prepared_after_it(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $earlier = new TracedCallback();
        $later = new TracedCallback();
        $stage->thread->configure($earlier->closure())->configure($later->closure());

        $stage->play();

        self::assertSame([0, 1], [$earlier->segmentsHeard, $later->segmentsHeard]);
        self::assertSame([], $earlier->adjusted);
    }

    #[DataProvider('backends')]
    public function test_a_callback_serves_every_segment_prepared_until_another_replaces_it(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, Ending::Waits);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());
        $stage->thread->start()->run();

        $state = $stage->thread->signal(MatrixWorkflow::EVENT, Stage::ANSWER)->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(2, $callback->segmentsHeard);
        self::assertCount(2, $callback->adjusted);
    }

    #[DataProvider('preparedKinds')]
    public function test_what_the_callback_throws_reaches_the_caller_as_it_is(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $refused = new RuntimeException('The response of this request is already closed.');
        $stage->thread->configure(static fn () => throw $refused);

        $played = $stage->play();

        self::assertSame($refused, $played->thrown);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_throws_leaves_nothing_announced(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn () => throw new RuntimeException('The response of this request is already closed.'));

        $played = $stage->play();

        self::assertSame([], $played->queued);
        self::assertSame(0, $played->requests);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_throws_leaves_nothing_run_and_nothing_written(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn () => throw new RuntimeException('The response of this request is already closed.'));
        $prepared = $stage->prepare();
        $writes = $stage->journal->exposedTo(FaultMode::AfterWrite);

        $played = $stage->play($prepared->run(...));

        self::assertSame([0, []], [$played->bodiesRun, $played->attemptsOpened]);
        self::assertSame($writes, $stage->journal->exposedTo(FaultMode::AfterWrite));
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_sets_another_persistence_leaves_the_run_in_the_store_of_the_sdk(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $another = new InMemoryPersistence();
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setPersistence($another));

        $stage->play();

        $run = $stage->stored();
        self::assertSame(['suspended', $kind->continuesARun() ? 2 : 1], [$run['status'] ?? null, $run['attempt'] ?? null]);
        self::assertNull(StoredRun::of($another, Stage::WORKFLOW_ID));
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_removes_the_lease_leaves_the_run_under_the_lease_of_its_definition(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setLeaseTimeout(null));
        $startedAt = time();

        $leaseExpiresAt = $this->leaseHeldWhileTheSegmentRuns($stage);

        self::assertGreaterThanOrEqual($startedAt + Stage::LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + Stage::LEASE, $leaseExpiresAt);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_sets_another_lease_leaves_the_run_under_the_lease_of_its_definition(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setLeaseTimeout(Stage::LEASE * 10));
        $startedAt = time();

        $leaseExpiresAt = $this->leaseHeldWhileTheSegmentRuns($stage);

        self::assertGreaterThanOrEqual($startedAt + Stage::LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + Stage::LEASE, $leaseExpiresAt);
    }

    #[DataProvider('preparedKinds')]
    public function test_a_callback_that_retains_the_completion_leaves_no_run_once_the_segment_completed_it(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->retainCompletionUntilAcknowledged());

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(WorkflowStatus::Completed, $played->returned->getStatus());
        self::assertNull($stage->stored());
    }

    #[DataProvider('preparedKinds')]
    public function test_run_streams_the_segment_through_the_stream_adapter_the_callback_set(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $adapter = new SilentAdapter();
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): SilentAdapter => $adapter));

        $stage->play();

        self::assertSame(['start', 'end'], $adapter->asked);
    }

    #[DataProvider('preparedKinds')]
    public function test_run_tells_the_channel_the_callback_set_how_the_segment_ended(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $completed = [];
        $channel = new CallbackChannel(onCompleted: static function (WorkflowState $state, string $workflowId) use (&$completed): void {
            $completed[] = $workflowId;
        });
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setChannel(static fn (): CallbackChannel => $channel));

        $stage->play();

        self::assertSame([Stage::WORKFLOW_ID], $completed);
    }

    /**
     * Run the segment of the stage in this process.
     *
     * @return int|null the time at which the lease of the run expires, as the store showed it while a node body ran
     */
    protected function leaseHeldWhileTheSegmentRuns(Stage $stage): ?int
    {
        $leaseExpiresAt = null;
        $stage->midBody->whenReached(static function () use ($stage, &$leaseExpiresAt): void {
            $leaseExpiresAt = $stage->stored()['leaseExpiresAt'] ?? null;
        });

        $stage->play();

        return $leaseExpiresAt;
    }
}
