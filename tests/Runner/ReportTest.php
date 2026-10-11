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
use NeuronCore\Cloud\Tests\Stub\Runner\WatchedWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;
use function gc_disable;
use function gc_enable;
use function gc_enabled;
use function ini_set;
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
    public function test_the_report_of_a_failed_segment_holds_the_exception_the_node_threw(string $backend): void
    {
        $bench = $this->benchWithFlaky($backend);

        $report = $bench->cloud->invoke($this->start('flaky'));

        self::assertInstanceOf(NodeFailed::class, $report->exception);
        self::assertSame(self::NODE_FAILURE_MESSAGE, $report->exception->getMessage());
        self::assertSame($report->errorClass, $report->exception::class);
        self::assertArrayNotHasKey('exception', $this->reportsSent()[0], 'The platform is sent the class and nothing else.');
    }

    /**
     * An exception keeps the arguments of every frame of its trace unless PHP is told to leave them out, and the
     * workflow that executed is one of them: it lives as long as the report that holds the exception, and no longer.
     */
    #[DataProvider('backends')]
    public function test_the_workflow_of_a_failed_segment_is_released_with_its_report_although_php_keeps_the_arguments_of_a_trace(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        WatchedWorkflow::$instances = [];
        $bench->cloud->register('watched', static fn (): WatchedWorkflow => new WatchedWorkflow($executions));
        $setting = ini_set('zend.exception_ignore_args', '0');
        $collecting = gc_enabled();
        gc_disable();

        try {
            $report = $bench->cloud->invoke($this->start('watched'));
            $thrown = $report->exception;
            $heldByTheReport = WatchedWorkflow::alive();
            unset($report, $thrown);
            $leftBehind = WatchedWorkflow::alive();
        } finally {
            if ($collecting) {
                gc_enable();
            }

            if ($setting !== false) {
                ini_set('zend.exception_ignore_args', $setting);
            }
        }

        self::assertGreaterThan(2, count(WatchedWorkflow::$instances), 'The runner builds a workflow for the segment and another for the snapshot, each a copy of what the factory made.');
        self::assertSame(1, $heldByTheReport, 'Only the workflow whose node threw is held, by the trace of what it threw.');
        self::assertSame(0, $leftBehind, 'A workflow the runner built outlives the report of its segment.');
    }

    #[DataProvider('backends')]
    public function test_the_workflow_of_a_failed_segment_is_released_with_the_call_where_php_leaves_the_arguments_out_of_a_trace(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        WatchedWorkflow::$instances = [];
        $bench->cloud->register('watched', static fn (): WatchedWorkflow => new WatchedWorkflow($executions));
        $setting = ini_set('zend.exception_ignore_args', '1');
        $collecting = gc_enabled();
        gc_disable();

        try {
            $report = $bench->cloud->invoke($this->start('watched'));
            $heldByTheReport = WatchedWorkflow::alive();
        } finally {
            if ($collecting) {
                gc_enable();
            }

            if ($setting !== false) {
                ini_set('zend.exception_ignore_args', $setting);
            }
        }

        self::assertInstanceOf(NodeFailed::class, $report->exception);
        self::assertSame(0, $heldByTheReport, 'The report of a failed segment keeps a workflow alive although its exception holds no argument.');
    }

    #[DataProvider('backends')]
    public function test_the_report_an_observe_posts_for_a_failed_segment_holds_the_class_and_no_exception(string $backend): void
    {
        $bench = $this->benchWithFlaky($backend);
        // The report is lost: the failure waits in the queue, and a worker that did not execute the segment reports it.
        $bench->platform->failNext(2);
        $bench->cloud->invoke($this->start('flaky'));

        $delivered = $this->queue->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $delivered[1]);
        self::assertSame([Outcome::Failed, NodeFailed::class], [$delivered[1]->outcome, $delivered[1]->errorClass]);
        self::assertNull($delivered[1]->exception);
    }

    #[DataProvider('backends')]
    public function test_a_report_of_a_call_that_neither_failed_nor_was_aborted_holds_no_exception(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions));

        $completed = $bench->cloud->invoke($this->start('greeting'));
        $rejected = $bench->cloud->invoke($this->start('greeting'));
        $suspended = $bench->cloud->invoke($this->start('order', fields: ['invocationId' => 'inv_second', 'runId' => '1-0192f3a8-0000-7000-8000-000000000002']));
        $refused = $bench->cloud->invoke($this->start('order', fields: ['invocationId' => 'inv_third', 'runId' => '1-0192f3a8-0000-7000-8000-000000000003']));

        self::assertSame(
            [Outcome::Completed, Outcome::Rejected, Outcome::Suspended, Outcome::Refused],
            [$completed->outcome, $rejected->outcome, $suspended->outcome, $refused->outcome],
        );
        self::assertSame(
            [null, null, null, null],
            [$completed->exception, $rejected->exception, $suspended->exception, $refused->exception],
        );
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
