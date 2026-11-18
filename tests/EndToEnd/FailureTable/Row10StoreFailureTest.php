<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Wait;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\FaultyStore;
use NeuronCore\IQ\Tests\Support\ExampleApp\PlannedFailure;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function array_column;

/**
 * Row 10 of section 7 of the design: the store fails during a commit, or
 * cannot be read for the report. The outcome and the snapshot can disagree,
 * or "snapshotRead" is false and the platform asks: the platform plans from
 * a snapshot that was read, never from the word.
 */
class Row10StoreFailureTest extends FailureTableTestCase
{
    protected const ORDER = 'R1001';

    public function test_a_store_that_cannot_be_read_for_the_report_is_reported_as_unread_and_the_platform_asks(): void
    {
        // A worker reads the store twice for a start: the ledger for its claim, then the run for its report.
        $this->shop->faults->plan(FaultyStore::READ, Faults::PASS, Faults::FAIL);
        $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $this->platform->tick();
        $unread = Wait::until('the report arrived', fn (): array|false => $this->reports(self::ORDER)[0] ?? false);
        $heldBeforeItAsked = $this->platform->run($runId);

        $this->awaitWait($runId, 'wait_for_event');
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);

        self::assertSame(['suspended', 1, false, null], [$unread['outcome'], $unread['executedAttempt'], $unread['snapshotRead'], $unread['run']]);
        self::assertNull($heldBeforeItAsked['run'], 'A report whose snapshot was not read shows nothing of the run.');
        self::assertSame([], $this->carried(self::ORDER, Verb::Observe), 'No observe follows a report the platform recorded.');
        self::assertSame([true, $this->stored(self::ORDER)], [$asked['answer']['snapshotRead'], $asked['answer']['run']]);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_commit_whose_answer_is_lost_is_reported_failed_with_a_snapshot_that_says_suspended(): void
    {
        // The fourth write of a worker for a start commits the suspension of the run: the store holds it, the worker never learns so.
        $this->shop->faults->plan(FaultyStore::WRITTEN, Faults::PASS, Faults::PASS, Faults::PASS, Faults::FAIL);
        $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $failed = $this->awaitReport(self::ORDER, 'failed');
        $this->awaitWait($runId, 'wait_for_event');

        self::assertSame([PlannedFailure::class, 1], [$failed['errorClass'], $failed['executedAttempt']]);
        self::assertSame(['suspended', 1], [$failed['run']['status'], $failed['run']['attempt']], 'The word and the snapshot disagree.');
        self::assertSame([], $this->platform->run($runId)['failures'], 'The platform counts a failure from a snapshot, never from the word.');
        self::assertSame([], $this->crossed(WireLog::WAKE, self::ORDER), 'No retry: the run waits.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_completion_whose_answer_is_lost_is_reported_failed_with_no_run_and_the_platform_cannot_tell_how_the_order_ended(): void
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        $this->shop->startWorker();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);

        // The last write of the wake of the timer removes the records of the completed run: its answer is lost.
        $this->shop->faults->plan(FaultyStore::REMOVED, Faults::FAIL);
        $failed = $this->awaitReport(self::ORDER, 'failed');
        $ended = $this->awaitClosed($runId, 'ended_unknown');

        self::assertSame(['wake', PlannedFailure::class, 3, null], [$failed['verb'], $failed['errorClass'], $failed['executedAttempt'], $failed['run']]);
        self::assertSame([], $ended['failures'], 'No snapshot showed a failed run: nothing is retried.');
        self::assertSame([2], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'));
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER), 'The order shipped, and its run is gone.');
    }

    public function test_a_wake_whose_admission_was_committed_and_its_answer_lost_is_reported_aborted_although_the_attempt_was_raised(): void
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);

        // The next write is the admission of the wake of the timer: it raises the attempt, and its answer is lost.
        $this->shop->faults->plan(FaultyStore::WRITTEN, Faults::FAIL);
        $aborted = $this->awaitReport(self::ORDER, 'aborted');
        $this->awaitClosed($runId, 'completed');

        self::assertSame(['wake', 'not_executed', PlannedFailure::class, null], [$aborted['verb'], $aborted['code'], $aborted['errorClass'], $aborted['executedAttempt']]);
        self::assertSame(['running', 3], [$aborted['run']['status'], $aborted['run']['attempt']], 'Nothing ran, and the attempt was raised.');
        self::assertSame([2, 3], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'), 'The platform fenced its next wake with the attempt of the snapshot.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER));
    }
}
