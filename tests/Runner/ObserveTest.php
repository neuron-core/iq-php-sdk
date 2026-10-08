<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 9.8: an observe reads the store as it is now and posts one report.
 * These observes carry no outcome, as the one queued for a job that died.
 */
class ObserveTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_an_observe_that_carries_no_outcome_reports_observed_with_the_run_as_it_is(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $started = $this->orderSuspended($bench);

        $report = $bench->cloud->invoke($this->observeOf($this->start('order')));

        self::assertSame(Outcome::Observed, $report->outcome);
        self::assertSame('inv_start', $report->invocationId, 'The report speaks for the segment, not for the observe.');
        self::assertSame(Verb::Start, $report->verb);
        self::assertNull($report->code);
        self::assertNull($report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertTrue($report->snapshotRead);
        self::assertSame($started->run, $report->run);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Observed);
    }

    #[DataProvider('backends')]
    public function test_an_observe_of_a_start_whose_run_id_is_in_the_ledger_reports_claimed_true(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->orderSuspended($bench);

        $report = $bench->cloud->invoke($this->observeOf($this->start('order')));

        self::assertTrue($report->claimed);
        self::assertTrue($this->reportsSent()[1]['claimed']);
    }

    #[DataProvider('backends')]
    public function test_an_observe_of_a_start_whose_run_id_is_not_in_the_ledger_reports_claimed_false(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->cloud->invoke($this->observeOf($this->start('greeting')));

        self::assertSame(Outcome::Observed, $report->outcome);
        self::assertFalse($report->claimed);
        self::assertFalse($this->reportsSent()[0]['claimed']);
        self::assertNull($report->run);
        self::assertNull($this->ledgerRecord(), 'An observe reads the ledger and writes nothing.');
    }

    #[DataProvider('backends')]
    public function test_an_observe_of_a_wake_an_answer_or_a_signal_reports_claimed_null(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $started = $this->orderSuspended($bench);

        $reports = [
            $bench->cloud->invoke($this->observeOf($this->wake('order', $started))),
            $bench->cloud->invoke($this->observeOf($this->answer('order', $started, ['amount' => 100]))),
            $bench->cloud->invoke($this->observeOf($this->signal('order', $started, WaitingWorkflow::EVENT))),
        ];

        foreach ($reports as $report) {
            self::assertSame(Outcome::Observed, $report->outcome);
            self::assertNull($report->claimed, "The ledger holds starts only: it says nothing of a {$report->verb->value}.");
            self::assertSame($started->run, $report->run);
        }
    }

    #[DataProvider('backends')]
    public function test_an_observe_that_cannot_read_the_run_posts_one_report_without_a_snapshot_and_ends(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->orderSuspended($bench);
        $this->failStoreOn('get', self::WORKFLOW_ID);

        $report = $bench->cloud->invoke($this->observeOf($this->start('order')));

        self::assertSame(Outcome::Observed, $report->outcome);
        self::assertFalse($report->snapshotRead);
        self::assertNull($report->run);
        self::assertNull($report->claimed);
        self::assertCount(2, $this->reportsSent(), 'The report of the start, then the one of the observe.');
        self::assertFalse($this->reportsSent()[1]['snapshotRead']);
    }

    #[DataProvider('backends')]
    public function test_an_observe_of_a_start_that_cannot_read_the_ledger_reports_neither_the_run_nor_the_claim(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->orderSuspended($bench);
        $this->failStoreOn('get', $this->ledgerPartition());

        $report = $bench->cloud->invoke($this->observeOf($this->start('order')));

        self::assertSame(Outcome::Observed, $report->outcome);
        self::assertFalse($report->snapshotRead);
        self::assertNull($report->run, 'A snapshot that was read only in part is not reported.');
        self::assertNull($report->claimed);
        self::assertCount(2, $this->reportsSent(), 'The report of the start, then the one of the observe.');
    }

    #[DataProvider('backends')]
    public function test_an_observe_that_cannot_read_the_store_still_reports_the_outcome_it_carries(string $backend): void
    {
        $bench = $this->benchOn($backend);
        // The platform is down for the whole segment: its announcement and its report wait in the queue.
        $bench->platform->failNext(2);
        $bench->cloud->invoke($this->start('greeting'));
        $this->failStoreOn('get', self::WORKFLOW_ID);

        $delivered = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $delivered[1]);
        self::assertSame(Outcome::Completed, $delivered[1]->outcome);
        self::assertSame(1, $delivered[1]->executedAttempt);
        self::assertFalse($delivered[1]->snapshotRead);
        self::assertNull($delivered[1]->run);
        self::assertNull($delivered[1]->claimed);
        self::assertSame([], $this->queue->runAll($bench->cloud), 'The observe is not tried again: the platform recorded its report.');
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_an_observe_the_platform_answers_401_throws_unauthorized(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 401);

        self::assertSame(Code::Unauthorized, $this->thrownBy($this->observeOf($this->start('greeting')))->reason);
    }

    #[DataProvider('backends')]
    public function test_an_observe_the_platform_answers_403_throws_unauthorized(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 403);

        self::assertSame(Code::Unauthorized, $this->thrownBy($this->observeOf($this->start('greeting')))->reason);
    }

    #[DataProvider('backends')]
    public function test_an_observe_the_platform_answers_400_invalid_request_throws_invalid_request(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 400);

        self::assertSame(Code::InvalidRequest, $this->thrownBy($this->observeOf($this->start('greeting')))->reason);
    }

    #[DataProvider('backends')]
    public function test_an_observe_the_platform_answers_503_throws_report_undelivered(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 503);

        self::assertSame(Code::ReportUndelivered, $this->thrownBy($this->observeOf($this->start('greeting')))->reason);
    }

    /**
     * "order" started and suspended on its wait for "payment.received".
     *
     * @return Report the report of the start
     */
    protected function orderSuspended(TestBench $bench): Report
    {
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions));

        return $bench->cloud->invoke($this->start('order'));
    }
}
