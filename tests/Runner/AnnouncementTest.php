<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Step 4 of the runner and section 9.8: "a segment of this run is starting".
 * A job posts it once, leaves it to the queue when that fails, and only a
 * segment of the application's own is stopped when the queue fails too.
 */
class AnnouncementTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_the_announcement_of_a_start_is_posted_before_the_start_is_claimed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $announcedBeforeTheClaim = null;
        $this->interceptStore(function (string $operation, string $partition) use (&$announcedBeforeTheClaim): void {
            if ($partition === $this->ledgerPartition()) {
                $announcedBeforeTheClaim ??= $this->announcementsSent();
            }
        });

        $bench->iq->invoke($this->start('greeting'));

        self::assertCount(1, $announcedBeforeTheClaim);
        self::assertSame(self::RUN_ID, $announcedBeforeTheClaim[0]['runId']);
    }

    #[DataProvider('backends')]
    public function test_the_announcement_of_a_start_names_the_segment_and_the_lease_of_its_definition(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench, leaseTimeout: 120);

        $bench->iq->invoke($this->start('order'));

        $announcement = $this->announcementsSent()[0];
        unset($announcement['at']);
        self::assertSame([
            'protocol' => 1,
            'invocationId' => 'inv_start',
            'verb' => 'start',
            'origin' => 'platform',
            'definition' => 'order',
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'attempt' => null,
            'leaseSeconds' => 120,
        ], $announcement);
    }

    #[DataProvider('backends')]
    public function test_the_announcement_of_an_answer_carries_the_fence_of_its_invocation(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench);
        $started = $bench->iq->invoke($this->start('order'));

        $bench->iq->invoke($this->answer('order', $started, ['amount' => 100]));

        $announcement = $this->announcementsSent()[1];
        self::assertSame('answer', $announcement['verb']);
        self::assertSame('application', $announcement['origin']);
        self::assertSame(self::RUN_ID, $announcement['runId']);
        self::assertSame(1, $announcement['attempt']);
    }

    #[DataProvider('backends')]
    public function test_an_announcement_the_platform_does_not_record_is_handed_to_the_queue_and_the_segment_runs(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext();

        $report = $bench->iq->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        self::assertCount(1, $this->announcementsSent(), 'A job posts its announcement once.');
        self::assertCount(1, $this->queue->dispatched());
        self::assertSame(Verb::Announce, $this->queue->dispatched()[0]->verb);
        self::assertSame($this->announcementsSent()[0], $this->queue->dispatched()[0]->body);
    }

    #[DataProvider('backends')]
    public function test_an_announcement_the_platform_refuses_for_good_is_handed_to_the_queue_and_the_segment_runs(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext(1, 401);

        $report = $bench->iq->invoke($this->start('greeting', self::APPLICATION));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        self::assertCount(1, $this->queue->dispatched());
        self::assertSame(Verb::Announce, $this->queue->dispatched()[0]->verb);
    }

    #[DataProvider('backends')]
    public function test_a_queued_announcement_is_posted_later_as_it_was_prepared(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext();
        $bench->iq->invoke($this->start('greeting'));

        $delivered = $this->queue->runAll($bench->iq);

        self::assertCount(1, $delivered);
        self::assertInstanceOf(Report::class, $delivered[0]);
        self::assertSame(Outcome::Observed, $delivered[0]->outcome);
        self::assertSame($this->announcementsSent()[0], $this->announcementsSent()[1]);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
    }

    #[DataProvider('backends')]
    public function test_an_application_start_is_aborted_as_announce_failed_and_thrown_when_the_queue_refuses_its_announcement_too(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $thrown = $this->thrownBy($this->start('greeting', self::APPLICATION));

        self::assertSame(Code::AnnounceFailed, $thrown->reason);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::AnnounceFailed->value);
    }

    #[DataProvider('backends')]
    public function test_the_report_of_announce_failed_carries_the_class_of_what_the_queue_threw(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $this->thrownBy($this->start('greeting', self::APPLICATION));

        self::assertSame(Code::AnnounceFailed->value, $this->reportsSent()[0]['code']);
        self::assertSame(RuntimeException::class, $this->reportsSent()[0]['errorClass']);
    }

    #[DataProvider('backends')]
    public function test_the_exception_thrown_for_announce_failed_carries_what_the_queue_threw_as_its_previous(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $thrown = $this->thrownBy($this->start('greeting', self::APPLICATION));

        self::assertInstanceOf(RuntimeException::class, $thrown->getPrevious());
        self::assertSame('The queue is down.', $thrown->getPrevious()->getMessage());
    }

    #[DataProvider('backends')]
    public function test_a_start_aborted_as_announce_failed_claims_nothing_and_runs_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $this->thrownBy($this->start('greeting', self::APPLICATION));

        self::assertNull($this->reportsSent()[0]['claimed']);
        self::assertNull($this->reportsSent()[0]['executedAttempt']);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_runs_although_the_queue_refuses_its_announcement_too(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $report = $bench->iq->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertTrue($report->claimed);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_an_application_answer_is_aborted_as_announce_failed_and_leaves_its_run_untouched(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench);
        $started = $bench->iq->invoke($this->start('order'));
        $this->platformAndQueueRefuseTheAnnouncement($bench);

        $thrown = $this->thrownBy($this->answer('order', $started, ['amount' => 100]));

        self::assertSame(Code::AnnounceFailed, $thrown->reason);
        self::assertSame(Outcome::Aborted->value, $this->reportsSent()[1]['outcome']);
        self::assertSame($started->run, $this->reportsSent()[1]['run']);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_queued_announcement_the_platform_answers_401_throws_unauthorized(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $announce = $this->queuedAnnouncement($bench);
        $bench->platform->failNext(1, 401);

        self::assertSame(Code::Unauthorized, $this->thrownBy($announce)->reason);
    }

    #[DataProvider('backends')]
    public function test_a_queued_announcement_the_platform_answers_400_invalid_request_throws_invalid_request(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $announce = $this->queuedAnnouncement($bench);
        $bench->platform->failNext(1, 400);

        self::assertSame(Code::InvalidRequest, $this->thrownBy($announce)->reason);
    }

    #[DataProvider('backends')]
    public function test_a_queued_announcement_the_platform_answers_503_throws_report_undelivered(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $announce = $this->queuedAnnouncement($bench);
        $bench->platform->failNext(1, 503);

        self::assertSame(Code::ReportUndelivered, $this->thrownBy($announce)->reason);
    }

    #[DataProvider('backends')]
    public function test_the_exception_of_an_announcement_the_platform_did_not_record_carries_no_report(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $announce = $this->queuedAnnouncement($bench);
        $bench->platform->failNext(1, 503);

        self::assertNull($this->thrownBy($announce)->report, 'An announcement says that a segment starts: no report exists yet.');
    }

    /**
     * "order" waits for an event that only a signal or an answer delivers.
     */
    protected function registerOrder(TestBench $bench, int $leaseTimeout = 600): void
    {
        $executions = $bench->executions;

        $bench->iq->register(
            'order',
            static fn (): WaitingWorkflow => new WaitingWorkflow($executions),
            leaseTimeout: $leaseTimeout,
        );
    }

    /**
     * The platform is down for the next request, and so is the queue for good.
     */
    protected function platformAndQueueRefuseTheAnnouncement(TestBench $bench): void
    {
        $bench->platform->failNext();
        $bench->iq->setDispatcher(new UnreachableQueue());
    }

    /**
     * The announce a start left in the queue because the platform did not record its announcement.
     */
    protected function queuedAnnouncement(TestBench $bench): Invocation
    {
        $bench->platform->failNext();
        $bench->iq->invoke($this->start('greeting'));

        return $this->queue->dispatched()[0];
    }
}
