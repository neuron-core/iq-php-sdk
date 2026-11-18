<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests;

use DateTimeImmutable;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Ledger;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\DeliverNode;
use NeuronCore\IQ\Tests\Stub\FlakyNode;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * The orders of the platform, played through invoke() against the two fakes.
 */
class SmokeTest extends IQTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_runs_once_and_is_announced_and_reported(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);

        $report = $bench->iq->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $report->executedAttempt);
        self::assertTrue($report->claimed);
        self::assertTrue($report->snapshotRead);
        self::assertNull($report->run, 'A run that completes under the SDK is gone.');
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_the_same_start_delivered_again_is_rejected_as_already_claimed_and_runs_nothing(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);
        $bench->iq->invoke($this->start('greeting'));

        $report = $bench->iq->invoke($this->start('greeting'));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::AlreadyClaimed->value, $report->code);
        self::assertTrue($report->claimed);
        self::assertNull($report->executedAttempt);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Rejected, Code::AlreadyClaimed->value);
    }

    #[DataProvider('backends')]
    public function test_a_start_past_its_use_by_time_is_rejected_as_expired(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);

        $report = $bench->iq->invoke($this->start('greeting', expiresAt: time() - 1));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::Expired->value, $report->code);
        self::assertNull($report->claimed, 'The ledger was not read for a start that expired.');
        self::assertSame(0, $bench->executions->total());
        self::assertCount(1, $bench->platform->received(), 'An expired start is refused before it is announced.');
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Rejected, Code::Expired->value);

        self::assertFalse((new Ledger($bench->persistence))->has(self::WORKFLOW_ID, self::RUN_ID), 'The copy never reached the ledger.');
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_suspended_run_whose_timer_is_due_continues_it(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);

        $started = $bench->iq->invoke($this->start('order'));
        self::assertSame(Outcome::Suspended, $started->outcome);
        self::assertSame('wait_for_event', $started->run['wait']['type']);
        self::assertSame(WaitingWorkflow::EVENT, $started->run['wait']['eventName']);

        // The deadline of the event wait has passed: the node goes on, to its timer.
        $afterDeadline = $bench->iq->invoke($this->wake('order', $started));
        self::assertSame(Outcome::Suspended, $afterDeadline->outcome);
        self::assertSame(2, $afterDeadline->executedAttempt);
        self::assertSame('sleep_until', $afterDeadline->run['wait']['type']);
        self::assertSame(0, $afterDeadline->run['wait']['wakeAt']);

        // The timer is due: the run completes.
        $afterTimer = $bench->iq->invoke($this->wake('order', $afterDeadline));
        self::assertSame(Outcome::Completed, $afterTimer->outcome);
        self::assertSame(3, $afterTimer->executedAttempt);
        self::assertNull($afterTimer->run);

        self::assertSame(2, $bench->executions->count(AwaitPaymentNode::class), 'A resumed node runs again from the top.');
        self::assertSame(2, $bench->executions->count(DeliverNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_the_same_wake_delivered_again_is_refused_as_a_stale_attempt(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);
        $started = $bench->iq->invoke($this->start('order'));
        $afterDeadline = $bench->iq->invoke($this->wake('order', $started));
        $executions = $bench->executions->total();

        $copy = $bench->iq->invoke($this->wake('order', $started));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleAttempt->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertSame($afterDeadline->run, $copy->run, 'The refusal carries the snapshot of the run as it is now.');
        self::assertSame($executions, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::StaleAttempt->value);
    }

    #[DataProvider('backends')]
    public function test_a_wake_delivered_again_after_its_run_completed_is_refused_as_a_stale_run(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);
        $started = $bench->iq->invoke($this->start('order'));
        $afterDeadline = $bench->iq->invoke($this->wake('order', $started));
        $bench->iq->invoke($this->wake('order', $afterDeadline));
        $executions = $bench->executions->total();

        $copy = $bench->iq->invoke($this->wake('order', $afterDeadline));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleRun->value, $copy->code);
        self::assertNull($copy->run);
        self::assertSame($executions, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::StaleRun->value);
    }

    #[DataProvider('backends')]
    public function test_a_wake_after_a_failed_segment_retries_the_run(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);

        $failed = $bench->iq->invoke($this->start('flaky'));
        self::assertSame(Outcome::Failed, $failed->outcome);
        self::assertSame(NodeFailed::class, $failed->errorClass);
        self::assertSame(1, $failed->executedAttempt);
        self::assertSame('failed', $failed->run['status']);

        $retried = $bench->iq->invoke($this->wake('flaky', $failed));

        self::assertSame(Outcome::Completed, $retried->outcome);
        self::assertSame(2, $retried->executedAttempt);
        self::assertSame(2, $bench->executions->count(FlakyNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Failed);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_what_the_platform_did_not_record_waits_in_the_queue_and_is_delivered_later(string $backend): void
    {
        $bench = $this->benchWithStubs($backend);
        $bench->platform->failNext(2);

        $report = $bench->iq->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome, 'Nothing local waits for the platform.');
        $bench->dispatcher->assertDispatched(Verb::Announce, self::WORKFLOW_ID);
        $bench->dispatcher->assertDispatched(Verb::Observe, self::WORKFLOW_ID);

        $delivered = $bench->dispatcher->runAll($bench->iq);

        self::assertCount(2, $delivered);
        self::assertContainsOnlyInstancesOf(Report::class, $delivered);
        self::assertSame(Outcome::Completed, $delivered[1]->outcome, 'The observe still says how the segment ended.');
        self::assertTrue($delivered[1]->claimed);
        self::assertSame([], $bench->dispatcher->runAll($bench->iq), 'The queue is drained.');
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    /**
     * A bench with the stub workflows registered: "greeting" completes in one segment, "flaky" fails once,
     * "order" waits for an event until a deadline that has passed, then for a timer that is due.
     */
    protected function benchWithStubs(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->iq
            ->register('greeting', static fn (): CompletingWorkflow => new CompletingWorkflow($executions))
            ->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1))
            ->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions, new DateTimeImmutable('-1 minute')));

        return $bench;
    }

    /**
     * A start as the endpoint queues it for the platform.
     */
    protected function start(string $definition, ?int $expiresAt = null): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_start',
            'verb' => Verb::Start->value,
            'origin' => 'platform',
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'expiresAt' => $expiresAt ?? time() + 900,
        ]);
    }

    /**
     * A wake fenced as the platform does it: with the run ID and the attempt a report showed.
     */
    protected function wake(string $definition, Report $reported): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_wake_' . $reported->run['attempt'],
            'verb' => Verb::Wake->value,
            'origin' => 'platform',
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $reported->run['runId'],
            'attempt' => $reported->run['attempt'],
        ]);
    }
}
