<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Continuations;

use Closure;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\Continuations\OutpacedPersistence;
use NeuronCore\IQ\Tests\Stub\FlakyNode;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\Matrix\SeverablePersistence;
use NeuronCore\IQ\Tests\Stub\RacingPersistence;
use NeuronCore\IQ\Tests\Stub\Runner\BusyNode;
use NeuronCore\IQ\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\IQ\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * recover() when another process gets to the run first: the platform's wake
 * executed by a worker, or the worker that was taken for dead. The wake of
 * recover() carries the attempt its snapshot showed, so only one of the two
 * executes, and recover() returns null when it is the other one.
 */
class RecoverRaceTest extends ContinuationTestCase
{
    #[DataProvider('backends')]
    public function test_recover_returns_null_when_a_rival_completed_the_run_between_its_snapshot_and_its_wake(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->flakyOutpacedByThePlatformWake(failures: 1);

        $recovered = $flaky->recover();

        self::assertNull($recovered);
        self::assertNull($this->storedRun(), 'The rival completed the run.');
        self::assertSame(2, $executions->count(FlakyNode::class), 'Only the rival executed the node again.');
    }

    #[DataProvider('backends')]
    public function test_the_wake_of_recover_that_met_a_run_a_rival_completed_was_refused_as_stale_run(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyOutpacedByThePlatformWake(failures: 1);
        $queued = $this->countQueued();

        $flaky->recover();

        [, $observe] = $this->queuedAfter($queued);
        self::assertSame(['refused', 'stale_run', null], self::settled($observe));
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_when_a_rival_raised_the_attempt_between_its_snapshot_and_its_wake(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->flakyOutpacedByThePlatformWake(failures: 2);

        $recovered = $flaky->recover();

        self::assertNull($recovered);
        self::assertSame([WorkflowStatus::Failed, 2], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
        self::assertSame(2, $executions->count(FlakyNode::class), 'Only the rival executed the node again.');
    }

    #[DataProvider('backends')]
    public function test_the_wake_of_recover_that_met_an_attempt_a_rival_raised_was_refused_as_stale_attempt(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyOutpacedByThePlatformWake(failures: 2);
        $queued = $this->countQueued();

        $flaky->recover();

        [, $observe] = $this->queuedAfter($queued);
        self::assertSame(['refused', 'stale_attempt', null], self::settled($observe));
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_when_a_rival_claims_the_run_just_before_its_own_claim_is_written(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->flakyThatFailed(failures: 1);
        $store = new RacingPersistence(($this->bench->persistence)(), 1, $this->platformWakeOfFlaky());
        $outraced = $this->processOn($store)->workflow('flaky', self::WORKFLOW_ID);
        $queued = $this->countQueued();

        $recovered = $outraced->recover();

        [, $observe] = $this->queuedAfter($queued);
        self::assertNull($recovered);
        self::assertSame(['refused', 'stale_attempt', null], self::settled($observe));
        self::assertSame(2, $executions->count(FlakyNode::class), 'Only the rival executed the node again.');
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_when_the_worker_it_took_for_dead_renewed_its_lease_between_its_snapshot_and_its_wake(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->busyWhoseWorkerFellSilent();
        $store = new OutpacedPersistence(($this->bench->persistence)(), $this->theSilentWorkerCommitsAStep(...));
        $outpaced = $this->processOn($store)->workflow('busy', self::WORKFLOW_ID);
        $queued = $this->countQueued();

        $recovered = $outpaced->recover();

        [, $observe] = $this->queuedAfter($queued);
        self::assertNull($recovered);
        self::assertSame(['refused', 'executing', null], self::settled($observe));
        self::assertSame(1, $executions->count(BusyNode::class), 'The run was not taken from its worker.');
    }

    /**
     * "flaky": its only node throws $failures times, then completes the run. A start run in a request failed,
     * under attempt 1, and a worker has posted its notices.
     */
    protected function flakyThatFailed(int $failures): void
    {
        $executions = $this->bench->executions;
        $flaky = $this->managed('flaky', static fn (): Workflow => new FlakyWorkflow($executions, $failures));
        self::thrownFrom(static fn (): WorkflowState => $flaky->start()->run());
        $this->work();
    }

    /**
     * The failed run of "flaky", as a process sees it in which a worker executes the platform's wake for that
     * run right after recover() has read its snapshot.
     */
    protected function flakyOutpacedByThePlatformWake(int $failures): ManagedWorkflow
    {
        $this->flakyThatFailed($failures);
        $store = new OutpacedPersistence(($this->bench->persistence)(), $this->platformWakeOfFlaky());

        return $this->processOn($store)->workflow('flaky', self::WORKFLOW_ID);
    }

    /**
     * What the rival does: a worker of the application executes the wake the platform sent for the failed run.
     *
     * @return Closure(): void
     */
    protected function platformWakeOfFlaky(): Closure
    {
        return function (): void {
            $this->thePlatformWakesTheRun('flaky');
        };
    }

    /**
     * The process that calls recover(): every store it builds is $store.
     */
    protected function processOn(PersistenceInterface $store): IQ
    {
        $executions = $this->bench->executions;

        return $this->anotherProcess($store)
            ->register('flaky', static fn (): Workflow => new FlakyWorkflow($executions))
            ->register('busy', static fn (): Workflow => new BusyWorkflow($executions, static function (): void {
            }));
    }

    /**
     * A worker started a run of "busy" under a lease of 1 second and has written nothing since: the lease has
     * passed, and the run looks dead.
     */
    protected function busyWhoseWorkerFellSilent(): void
    {
        $executions = $this->bench->executions;
        $store = new SeverablePersistence(($this->bench->persistence)());
        $worker = $this->anotherProcess($store)
            ->register('busy', static fn (): Workflow => new BusyWorkflow($executions, $store->sever(...)), leaseTimeout: 1);
        self::thrownFrom(static fn (): WorkflowState => $worker->workflow('busy', self::WORKFLOW_ID)->start()->run());
        $this->work();
        $this->holdUntil((int) $this->storedRun()?->leaseExpiresAt);
    }

    /**
     * What a worker that was only slow does next: Neuron renews the lease in the control record of the run
     * with every step it commits. No stub can make a severed worker write again, so the record is rewritten
     * here as that commit would have left it.
     */
    protected function theSilentWorkerCommitsAStep(): void
    {
        $store = ($this->bench->persistence)();
        $serializer = new PhpSerializer();
        $stored = (string) $store->get(self::WORKFLOW_ID, '__control');
        $control = $serializer->unserialize($stored);
        self::assertInstanceOf(WorkflowControl::class, $control);

        $store->writeIfUnchanged(self::WORKFLOW_ID, '__control', $stored, [
            '__control' => $serializer->serialize($control->heartbeat(time() + 600)),
        ]);
    }
}
