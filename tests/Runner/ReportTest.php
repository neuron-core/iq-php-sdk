<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use DateTimeImmutable;
use NeuronAI\HttpClient\HttpMethod;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Step 7 of the runner and section 9.8: after a segment, the outcome and a
 * snapshot read from the store just before sending.
 */
class ReportTest extends RunnerTestCase
{
    protected const NODE_FAILURE_MESSAGE = 'The flaky node failed on purpose.';

    #[DataProvider('backends')]
    public function test_a_node_that_throws_is_reported_as_failed_with_its_exception_class_and_a_failed_snapshot(string $backend): void
    {
        $bench = $this->benchWithFlaky($backend);

        $report = $bench->cloud->invoke($this->start('flaky'));

        self::assertSame(Outcome::Failed, $report->outcome);
        self::assertNull($report->code);
        self::assertSame(NodeFailed::class, $report->errorClass);
        self::assertSame(1, $report->executedAttempt);
        self::assertTrue($report->snapshotRead);
        self::assertSame('failed', $report->run['status']);
        self::assertSame(1, $report->run['attempt']);
        self::assertNull($report->run['leaseExpiresAt']);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Failed);
    }

    #[DataProvider('backends')]
    public function test_the_message_of_a_node_exception_never_reaches_the_platform(string $backend): void
    {
        $bench = $this->benchWithFlaky($backend);
        // The report is lost, so the failure also travels through the queue before it is reported.
        $bench->platform->failNext(2);

        $bench->cloud->invoke($this->start('flaky'));
        $this->queue->runAll($bench->cloud);

        $sent = json_encode([$this->announcementsSent(), $this->reportsSent()], JSON_THROW_ON_ERROR);
        self::assertStringContainsString(json_encode(NodeFailed::class, JSON_THROW_ON_ERROR), $sent);
        self::assertStringNotContainsString(self::NODE_FAILURE_MESSAGE, $sent);
    }

    #[DataProvider('backends')]
    public function test_a_job_posts_one_announcement_and_one_report_with_the_api_key_and_a_three_second_timeout(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $bench->cloud->invoke($this->start('greeting'));

        $requests = $bench->platform->received();
        self::assertCount(2, $requests);
        self::assertSame(TestBench::PLATFORM_URL . self::ANNOUNCEMENTS, $requests[0]->uri);
        self::assertSame(TestBench::PLATFORM_URL . self::REPORTS, $requests[1]->uri);
        foreach ($requests as $request) {
            self::assertSame(HttpMethod::POST, $request->method);
            self::assertSame('Bearer ' . TestBench::API_KEY, $request->headers['Authorization']);
            self::assertMatchesRegularExpression('~^neuron-cloud-php/\d+\.\d+\.\d+$~', $request->headers['User-Agent']);
            self::assertSame(3.0, $request->timeout);
        }
    }

    #[DataProvider('backends')]
    public function test_a_report_the_platform_recorded_with_its_snapshot_leaves_nothing_in_the_queue(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $bench->cloud->invoke($this->start('greeting'));

        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('backends')]
    public function test_a_report_the_platform_does_not_record_leaves_an_observe_that_carries_the_outcome(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerPlatformGoingDownMidSegment($bench, status: 503);

        $report = $bench->cloud->invoke($this->start('busy'));

        self::assertSame(Outcome::Completed, $report->outcome, 'Nothing local waits for the platform.');
        self::assertCount(1, $this->queue->dispatched());
        self::assertSame(Verb::Observe, $this->queue->dispatched()[0]->verb);
        self::assertSame([
            'invocationId' => 'inv_start',
            'verb' => 'start',
            'outcome' => 'completed',
            'code' => null,
            'errorClass' => null,
            'executedAttempt' => 1,
        ], $this->queue->dispatched()[0]->body);
    }

    #[DataProvider('backends')]
    public function test_a_report_the_platform_refuses_for_good_leaves_an_observe_all_the_same(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerPlatformGoingDownMidSegment($bench, status: 401);

        $report = $bench->cloud->invoke($this->start('busy'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertCount(1, $this->queue->dispatched());
        self::assertSame(Verb::Observe, $this->queue->dispatched()[0]->verb);
        self::assertSame('completed', $this->queue->dispatched()[0]->body['outcome']);
    }

    #[DataProvider('backends')]
    public function test_a_queued_observe_reports_the_outcome_it_carries_with_a_fresh_snapshot(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow(
            $executions,
            new DateTimeImmutable('-1 minute'),
            new DateTimeImmutable('+1 hour'),
        ));
        // The platform is down for the whole first segment: its announcement and its report wait in the queue.
        $bench->platform->failNext(2);
        $started = $bench->cloud->invoke($this->start('order'));
        $woken = $bench->cloud->invoke($this->wake('order', $started));

        $delivered = $this->queue->runAll($bench->cloud);

        self::assertCount(2, $delivered);
        self::assertContainsOnlyInstancesOf(Report::class, $delivered);
        self::assertSame('inv_start', $delivered[1]->invocationId);
        self::assertSame(Verb::Start, $delivered[1]->verb);
        self::assertSame(Outcome::Suspended, $delivered[1]->outcome);
        self::assertSame(1, $delivered[1]->executedAttempt);
        self::assertTrue($delivered[1]->snapshotRead);
        self::assertSame($woken->run, $delivered[1]->run, 'The snapshot is the run as it is when the observe runs.');
        self::assertSame(2, $delivered[1]->run['attempt']);
        self::assertTrue($delivered[1]->claimed, 'An observe reads the ledger for a start.');
    }

    #[DataProvider('backends')]
    public function test_invoke_still_returns_the_report_when_the_queue_refuses_its_observe(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerPlatformGoingDownMidSegment($bench, status: 503);
        $bench->cloud->setDispatcher(new UnreachableQueue());

        $report = $bench->cloud->invoke($this->start('busy', self::APPLICATION));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $report->executedAttempt);
        self::assertTrue($report->claimed);
        self::assertCount(1, $this->reportsSent(), 'The report was posted once, and lost.');
    }

    #[DataProvider('backends')]
    public function test_a_report_whose_snapshot_cannot_be_read_is_posted_with_snapshot_read_false(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->storeCannotBeReadForTheReport();

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome, 'A store that cannot be read must not hide how the call ended.');
        self::assertFalse($report->snapshotRead);
        self::assertNull($report->run);
        self::assertFalse($this->reportsSent()[0]['snapshotRead']);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_a_recorded_report_whose_snapshot_cannot_be_read_is_followed_by_no_observe(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->storeCannotBeReadForTheReport();

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertFalse($report->snapshotRead);
        self::assertSame([], $this->queue->dispatched(), 'The platform asks by itself for a snapshot it was not shown.');
    }

    protected function benchWithFlaky(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->cloud->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1));

        return $bench;
    }

    /**
     * "busy" completes in one segment, and the platform stops answering while that segment executes: it
     * recorded the announcement, and answers $status to the report.
     */
    protected function registerPlatformGoingDownMidSegment(TestBench $bench, int $status): void
    {
        $executions = $bench->executions;
        $platform = $bench->platform;

        $bench->cloud->register('busy', static fn (): BusyWorkflow => new BusyWorkflow(
            $executions,
            static function () use ($platform, $status): void {
                $platform->failNext(1, $status);
            },
        ));
    }

    /**
     * A start that ignites a run only writes to the partition of its workflow ID: the first read of it is
     * the snapshot for the report.
     */
    protected function storeCannotBeReadForTheReport(): void
    {
        $this->failStoreOn('get', self::WORKFLOW_ID);
    }
}
