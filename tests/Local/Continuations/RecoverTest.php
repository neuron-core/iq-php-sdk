<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use Closure;
use DateTimeImmutable;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Local\PickyQueue;
use NeuronCore\Cloud\Tests\Stub\Local\ReviewedWorkflow;
use NeuronCore\Cloud\Tests\Stub\Matrix\SeverablePersistence;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;

/**
 * recover(), for every state the run of a workflow ID can be in: what it
 * returns, what it executes and what it hands to the queue.
 */
class RecoverTest extends ContinuationTestCase
{
    #[DataProvider('backends')]
    public function test_recover_returns_null_and_queues_nothing_when_no_run_holds_the_workflow_id(string $backend): void
    {
        $this->benchOn($backend);

        $recovered = $this->flaky()->recover();

        self::assertNull($recovered);
        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_and_queues_nothing_for_a_run_that_is_suspended_on_a_wait(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $queued = $this->countQueued();

        $recovered = $contract->recover();

        self::assertNull($recovered);
        self::assertSame([], $this->queuedAfter($queued));
        self::assertSame([WorkflowStatus::Suspended, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_recover_leaves_a_run_whose_timer_is_due_to_the_platform(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $order = $this->managed('order', static fn (): Workflow => new WaitingWorkflow($executions, deliveryAt: new DateTimeImmutable('@0')));
        $order->start()->run();
        $order->signal(WaitingWorkflow::EVENT)->run();

        $recovered = $order->recover();

        self::assertNull($recovered);
        self::assertInstanceOf(SleepUntilRequest::class, $this->storedRun()?->interrupt);
        self::assertSame(1, $executions->count(DeliverNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_executes_the_node_of_a_failed_run_again_in_this_process_and_returns_the_state(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->flakyThatFailed();

        $recovered = $flaky->recover();

        self::assertInstanceOf(WorkflowState::class, $recovered);
        self::assertSame(WorkflowStatus::Completed, $recovered->getStatus());
        self::assertSame(2, $executions->count(FlakyNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_of_a_failed_run_hands_the_queue_the_announcement_of_a_wake_and_its_observe(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyThatFailed();
        $queued = $this->countQueued();

        $flaky->recover();

        [$announcement, $observe] = $this->queuedAfter($queued);
        self::assertCount(2, $this->queuedAfter($queued));
        self::assertSame([Verb::Announce, Verb::Observe], [$announcement->verb, $observe->verb]);
        self::assertSame(['wake', 'wake'], [$announcement->body['verb'] ?? null, $observe->body['verb'] ?? null]);
        self::assertSame(Origin::APPLICATION, $announcement->origin);
    }

    #[DataProvider('backends')]
    public function test_recover_calls_no_platform_from_the_calling_process(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $flaky = $this->flakyThatFailed();
        $received = count($bench->platform->received());

        $flaky->recover();

        self::assertCount($received, $bench->platform->received());
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_of_a_recovery_when_a_worker_takes_its_notices(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $flaky = $this->flakyThatFailed();

        $flaky->recover();
        $this->work();

        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_the_wake_of_recover_is_fenced_with_the_run_id_and_the_attempt_the_snapshot_showed(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyThatFailed(failures: 2);
        self::thrownFrom(static fn (): ?WorkflowState => $flaky->recover());
        $failedTwice = $this->storedRun();
        $queued = $this->countQueued();

        $flaky->recover();

        [$announcement, $observe] = $this->queuedAfter($queued);
        self::assertSame(2, $failedTwice?->executionAttempt);
        self::assertSame(['wake', $failedTwice->runId, 2], self::announced($announcement));
        self::assertSame(['completed', null, 3], self::settled($observe));
    }

    #[DataProvider('backends')]
    public function test_recover_throws_the_exception_of_a_node_that_fails_again(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->flakyThatFailed(failures: 2);

        $thrown = self::thrownFrom(static fn (): ?WorkflowState => $flaky->recover());

        self::assertSame(NodeFailed::class, $thrown::class);
        self::assertSame(2, $executions->count(FlakyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_run_whose_node_failed_again_stays_failed_under_the_attempt_recover_raised(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyThatFailed(failures: 2);
        $queued = $this->countQueued();

        self::thrownFrom(static fn (): ?WorkflowState => $flaky->recover());

        [, $observe] = $this->queuedAfter($queued);
        self::assertSame([WorkflowStatus::Failed, 2], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
        self::assertSame(['failed', null, 2], self::settled($observe));
    }

    #[DataProvider('backends')]
    public function test_recover_replays_the_answer_a_failed_run_had_accepted(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $review = $this->managed('review', static fn (): Workflow => new ReviewedWorkflow(static function () use ($executions): void {
            $executions->record(ReviewedWorkflow::class);

            if ($executions->count(ReviewedWorkflow::class) === 1) {
                throw new NodeFailed('The review could not be filed.');
            }
        }));
        $review->start()->run();
        self::thrownFrom(static fn (): WorkflowState => $review->submitInputs(['approved' => true])->run());

        $recovered = $review->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertSame(['approved' => true], $recovered->get('review'));
    }

    #[DataProvider('backends')]
    public function test_recover_throws_a_refusal_that_escaped_from_the_node_instead_of_taking_it_for_a_rival(string $backend): void
    {
        $this->benchOn($backend);
        $busy = $this->busyThatFailed(static function (): never {
            throw new StaleWorkflowRunException('invoice:123', 'run-of-the-invoice', null);
        });

        $thrown = self::thrownFrom(static fn (): ?WorkflowState => $busy->recover());

        self::assertSame(StaleWorkflowRunException::class, $thrown::class);
        self::assertSame([WorkflowStatus::Failed, 2], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_recover_takes_a_stale_attempt_refusal_that_escaped_from_the_node_for_a_rival_and_returns_null(string $backend): void
    {
        $this->benchOn($backend);
        $busy = $this->busyThatFailed(static function (): never {
            throw new WorkflowRefusedException('The invoice was taken over.', RefusalReason::StaleAttempt);
        });

        $recovered = $busy->recover();

        self::assertNull($recovered);
        self::assertSame([WorkflowStatus::Failed, 2], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_when_the_segment_it_executed_lost_the_run_to_a_rival_while_its_node_was_in_flight(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $busy = $this->busyThatFailed(function (): void {
            $this->holdUntil((int) $this->storedRun()?->leaseExpiresAt);
            $this->thePlatformWakesTheRun('busy');
        }, leaseTimeout: 1);
        $queued = $this->countQueued();

        $recovered = $busy->recover();

        [, $observe] = $this->queuedAfter($queued);
        self::assertNull($recovered);
        self::assertSame(['refused', 'stale_attempt', 2], self::settled($observe));
        self::assertSame(3, $executions->count(BusyNode::class), 'The node ran in the failed start, in recover() and in the rival.');
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_and_queues_nothing_for_a_run_that_is_running_under_a_lease_that_has_not_passed(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $recovered = false;
        $queuedByRecover = null;
        $whileRunning = function () use (&$recovered, &$queuedByRecover): void {
            $queued = $this->countQueued();
            $recovered = $this->bench->cloud->workflow('busy', self::WORKFLOW_ID)->recover();
            $queuedByRecover = $this->queuedAfter($queued);
        };

        $this->managed('busy', static fn (): Workflow => new BusyWorkflow($executions, $whileRunning))->start()->run();

        self::assertNull($recovered);
        self::assertSame([], $queuedByRecover);
        self::assertSame(1, $executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_takes_over_a_run_left_running_under_a_lease_that_has_passed_and_returns_its_state(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $busy = $this->busyWhoseWorkerDied(leaseTimeout: 1);
        $this->holdUntil((int) $this->storedRun()?->leaseExpiresAt);

        $recovered = $busy->recover();

        self::assertInstanceOf(WorkflowState::class, $recovered);
        self::assertSame([WorkflowStatus::Completed, 2], [$recovered->getStatus(), $recovered->getExecutionAttempt()]);
        self::assertSame(2, $executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_of_a_dead_run_hands_the_queue_a_wake_fenced_with_the_attempt_of_the_dead_worker(string $backend): void
    {
        $this->benchOn($backend);
        $busy = $this->busyWhoseWorkerDied(leaseTimeout: 1);
        $dead = $this->storedRun();
        $this->holdUntil((int) $dead?->leaseExpiresAt);
        $queued = $this->countQueued();

        $busy->recover();

        [$announcement, $observe] = $this->queuedAfter($queued);
        self::assertSame(['wake', $dead?->runId, 1], self::announced($announcement));
        self::assertSame(['completed', null, 2], self::settled($observe));
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_a_run_whose_worker_died_after_it_accepted_an_answer_by_replaying_that_answer(string $backend): void
    {
        $this->benchOn($backend);
        $review = $this->reviewWhoseWorkerDiedOnceAnswered(['approved' => true]);
        $this->holdUntil((int) $this->storedRun()?->leaseExpiresAt);

        $recovered = $review->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertSame(['approved' => true], $recovered->get('review'));
    }

    #[DataProvider('backends')]
    public function test_recover_leaves_alone_a_dead_run_whose_lease_has_not_passed_yet(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $busy = $this->busyWhoseWorkerDied(leaseTimeout: 600);
        $queued = $this->countQueued();

        $recovered = $busy->recover();

        self::assertNull($recovered);
        self::assertSame([], $this->queuedAfter($queued));
        self::assertSame(1, $executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_and_queues_nothing_for_a_run_that_is_running_with_no_lease(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $busy = $this->managed('busy', static fn (): Workflow => new BusyWorkflow($executions, static function (): void {
        }));
        $store = new SeverablePersistence(($this->bench->persistence)());
        $startedOutsideTheSdk = (new BusyWorkflow($executions, $store->sever(...)))->for(self::WORKFLOW_ID)->setPersistence($store);
        self::thrownFrom(static fn (): WorkflowState => $startedOutsideTheSdk->run());

        $recovered = $busy->recover();

        $run = $this->storedRun();
        self::assertNull($recovered);
        self::assertSame([], $this->queue->dispatched());
        self::assertSame([WorkflowStatus::Running, null, 1], [$run?->status, $run?->leaseExpiresAt, $run?->executionAttempt]);
        self::assertSame(1, $executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_throws_dispatch_failed_and_executes_nothing_when_the_queue_does_not_take_the_announcement(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->flakyThatFailed();
        $this->bench->cloud->setDispatcher(new PickyQueue($this->queue, Verb::Announce));
        $flaky = $this->bench->cloud->workflow('flaky', self::WORKFLOW_ID);

        $thrown = self::cloudExceptionFrom(static fn (): ?WorkflowState => $flaky->recover());

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::DispatchFailed, $thrown->reason);
        self::assertSame(1, $executions->count(FlakyNode::class));
        self::assertSame([WorkflowStatus::Failed, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_recover_still_returns_the_state_when_the_queue_does_not_take_the_observe(string $backend): void
    {
        $this->benchOn($backend);
        $this->flakyThatFailed();
        $this->bench->cloud->setDispatcher(new PickyQueue($this->queue, Verb::Observe));
        $flaky = $this->bench->cloud->workflow('flaky', self::WORKFLOW_ID);

        $recovered = $flaky->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
    }

    #[DataProvider('backends')]
    public function test_recover_throws_unknown_version_and_executes_nothing_in_a_process_that_lacks_the_version_of_the_failed_run(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->managed('flaky', static fn (): Workflow => new FlakyWorkflow($executions), version: 2);
        self::thrownFrom(static fn (): WorkflowState => $flaky->start()->run());
        $queued = $this->countQueued();
        $olderDeploy = $this->anotherProcess()->register('flaky', static fn (): Workflow => new FlakyWorkflow($executions), version: 1);

        $thrown = self::cloudExceptionFrom(static fn (): ?WorkflowState => $olderDeploy->workflow('flaky', self::WORKFLOW_ID)->recover());

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
        self::assertSame([], $this->queuedAfter($queued));
        self::assertSame(1, $executions->count(FlakyNode::class));
    }

    /**
     * "flaky": its only node throws $failures times, then completes the run.
     */
    protected function flaky(int $failures = 1): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->managed('flaky', static fn (): Workflow => new FlakyWorkflow($executions, $failures));
    }

    /**
     * A start was run in a request and its node threw: the run is failed under attempt 1, and a worker has
     * posted the notices of that segment.
     */
    protected function flakyThatFailed(int $failures = 1): ManagedWorkflow
    {
        $flaky = $this->flaky($failures);
        self::thrownFrom(static fn (): WorkflowState => $flaky->start()->run());
        $this->work();

        return $flaky;
    }

    /**
     * "busy" completes in one segment, unless its node throws. A start was run in a request and the node
     * threw: the run is failed under attempt 1, and a worker has posted the notices of that segment.
     *
     * @param Closure(): void $onceRecovered called from inside the node when recover() executes it again
     */
    protected function busyThatFailed(Closure $onceRecovered, int $leaseTimeout = 600): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $whileExecuting = static function () use ($executions, $onceRecovered): void {
            match ($executions->count(BusyNode::class)) {
                1 => throw new NodeFailed('The busy node failed on purpose.'),
                2 => $onceRecovered(),
                default => null,
            };
        };
        $busy = $this->managed('busy', static fn (): Workflow => new BusyWorkflow($executions, $whileExecuting), leaseTimeout: $leaseTimeout);
        self::thrownFrom(static fn (): WorkflowState => $busy->start()->run());
        $this->work();

        return $busy;
    }

    /**
     * "review" waits for the event "review.done" and completes. A worker took a dispatched answer under a
     * lease of 1 second and was cut off from the store once Neuron had accepted it: the run is left running,
     * with its answered wait still attached.
     *
     * @param array<string, mixed> $answer
     */
    protected function reviewWhoseWorkerDiedOnceAnswered(array $answer): ManagedWorkflow
    {
        $store = new SeverablePersistence(($this->bench->persistence)());
        $worker = $this->anotherProcess($store)
            ->register('review', static fn (): Workflow => new ReviewedWorkflow($store->sever(...)), leaseTimeout: 1);
        $worker->workflow('review', self::WORKFLOW_ID)->start()->run();
        $worker->workflow('review', self::WORKFLOW_ID)->submitInputs($answer)->dispatch();
        $this->queue->runAll($worker);

        self::assertSame(WorkflowStatus::Running, $this->storedRun()?->status);
        self::assertInstanceOf(InterruptRequest::class, $this->storedRun()?->interrupt);

        return $this->managed('review', static fn (): Workflow => new ReviewedWorkflow(static function (): void {
        }));
    }

    /**
     * "busy" completes in one segment. A worker started a run of it under a lease of $leaseTimeout seconds and
     * was cut off from the store while its node was in flight, as when it is killed: nothing it wrote
     * afterwards arrived, and the run is left running under that lease.
     */
    protected function busyWhoseWorkerDied(int $leaseTimeout): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $store = new SeverablePersistence(($this->bench->persistence)());
        $worker = $this->anotherProcess($store)
            ->register('busy', static fn (): Workflow => new BusyWorkflow($executions, $store->sever(...)), leaseTimeout: $leaseTimeout);
        self::thrownFrom(static fn (): WorkflowState => $worker->workflow('busy', self::WORKFLOW_ID)->start()->run());
        $this->work();

        self::assertSame(WorkflowStatus::Running, $this->storedRun()?->status);

        return $this->managed('busy', static fn (): Workflow => new BusyWorkflow($executions, static function (): void {
        }), leaseTimeout: $leaseTimeout);
    }
}
