<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
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

    #[DataProvider('answersThatRecordNothing')]
    public function test_the_exception_of_an_observe_the_platform_did_not_record_carries_the_report_that_was_built(string $backend, int $status, Code $reason): void
    {
        $bench = $this->benchOn($backend);
        $started = $this->orderSuspended($bench);
        $bench->platform->failNext(1, $status);

        $thrown = $this->thrownBy($this->observeOf($this->start('order')));

        self::assertSame($reason, $thrown->reason);
        self::assertInstanceOf(Report::class, $thrown->report, 'What the store shows does not wait for the platform.');
        self::assertSame([Outcome::Observed, 'inv_start', Verb::Start], [$thrown->report->outcome, $thrown->report->invocationId, $thrown->report->verb]);
        self::assertSame([$started->run, true, true], [$thrown->report->run, $thrown->report->claimed, $thrown->report->snapshotRead]);
        self::assertSame($this->reportsSent()[1], $thrown->report->toArray(), 'It is the report the platform was sent.');
    }

    #[DataProvider('backends')]
    public function test_the_report_of_an_observe_the_platform_did_not_record_says_how_the_segment_ended_when_the_observe_carries_it(string $backend): void
    {
        $bench = $this->benchOn($backend);
        // The platform is down for the whole segment and stays down for the first try of each notice.
        $bench->platform->failNext(4);
        $bench->cloud->invoke($this->start('greeting'));

        $thrown = $this->queue->runAll($bench->cloud)[1];

        self::assertInstanceOf(CloudException::class, $thrown);
        self::assertSame(Code::ReportUndelivered, $thrown->reason);
        self::assertSame([Outcome::Completed, 1], [$thrown->report?->outcome, $thrown->report?->executedAttempt]);
    }

    #[DataProvider('backends')]
    public function test_the_exception_of_an_observe_the_platform_never_answered_still_says_what_the_client_threw(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $unreachable = HttpException::networkError(HttpRequest::post(TestBench::PLATFORM_URL), 'Connection refused');
        $cloud = (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new ScriptedHttpClient($unreachable)))
            ->setPersistence($bench->persistence)
            ->register('greeting', static fn (): Workflow => new Workflow());

        try {
            $cloud->invoke($this->observeOf($this->start('greeting')));
        } catch (CloudException $thrown) {
            self::assertSame(Code::ReportUndelivered, $thrown->reason);
            self::assertSame('The platform gave no answer to ' . self::REPORTS . '.', $thrown->getMessage());
            self::assertSame($unreachable, $thrown->getPrevious(), 'What kept the report from the platform is lost with the report.');
            self::assertSame(Outcome::Observed, $thrown->report?->outcome);

            return;
        }

        self::fail('invoke() returned a report the platform never received.');
    }

    #[DataProvider('backends')]
    public function test_the_exception_of_an_observe_the_platform_answered_with_an_error_keeps_the_sentence_of_that_answer(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 503);

        $thrown = $this->thrownBy($this->observeOf($this->start('greeting')));

        self::assertSame('The platform answered 503 to ' . self::REPORTS . ' and recorded nothing.', $thrown->getMessage());
    }

    /**
     * @return array<string, array{string, int, Code}> every backend, with an answer of the platform that records nothing
     */
    public static function answersThatRecordNothing(): array
    {
        $cases = [];

        foreach (self::backends() as $name => [$backend]) {
            $cases["{$name}, the platform is away"] = [$backend, 503, Code::ReportUndelivered];
            $cases["{$name}, the platform refuses the API key"] = [$backend, 401, Code::Unauthorized];
            $cases["{$name}, the platform refuses the report for good"] = [$backend, 400, Code::InvalidRequest];
        }

        return $cases;
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
