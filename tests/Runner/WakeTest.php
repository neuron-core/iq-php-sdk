<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use DateTimeImmutable;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 6 of the runner for a wake: the guard that keeps it off a run started
 * outside the SDK, and a wake that comes before its time.
 */
class WakeTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_started_outside_the_sdk_and_running_with_no_lease_is_refused_as_executing(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $refusal = $this->wakeWhileARunStartedOutsideTheSdkExecutes($bench);

        self::assertSame(Outcome::Refused, $refusal->outcome);
        self::assertSame(RefusalReason::Executing->value, $refusal->code);
        self::assertNull($refusal->executedAttempt);
        self::assertSame('running', $refusal->run['status']);
        self::assertNull($refusal->run['leaseExpiresAt']);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::Executing->value);
    }

    #[DataProvider('backends')]
    public function test_a_wake_does_not_take_over_a_run_started_outside_the_sdk_that_is_running_with_no_lease(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $refusal = $this->wakeWhileARunStartedOutsideTheSdkExecutes($bench);

        self::assertSame(1, $refusal->run['attempt'], 'Taking a run over raises its attempt.');
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_fenced_with_another_run_is_refused_as_stale_run_while_a_run_with_no_lease_executes(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $refusal = $this->wakeWhileARunStartedOutsideTheSdkExecutes($bench, fencedRunId: 'run-that-ended');

        self::assertSame(Outcome::Refused, $refusal->outcome);
        self::assertSame(RefusalReason::StaleRun->value, $refusal->code, 'The guard speaks for the fenced run only.');
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_fenced_with_a_stale_attempt_is_refused_as_stale_attempt_while_a_run_with_no_lease_executes(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $refusal = $this->wakeWhileARunStartedOutsideTheSdkExecutes($bench, fencedAttempt: 5);

        self::assertSame(Outcome::Refused, $refusal->outcome);
        self::assertSame(RefusalReason::StaleAttempt->value, $refusal->code, 'The guard speaks for the fenced attempt only.');
        self::assertSame(1, $refusal->run['attempt']);
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_takes_over_a_run_left_running_under_a_lease_that_has_passed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $takeover = null;
        $outlivesItsLease = function () use ($bench, &$takeover): void {
            // The wake executes the node again, in this process: only the first execution is the slow worker.
            if ($bench->executions->count(BusyNode::class) > 1) {
                return;
            }

            $running = $bench->cloud->invoke($this->observeOf($this->start('slow')));
            $this->holdUntil($running->run['leaseExpiresAt']);
            $takeover = $bench->cloud->invoke($this->wake('slow', $running));
        };
        $bench->cloud->register(
            'slow',
            static fn (): BusyWorkflow => new BusyWorkflow($executions, $outlivesItsLease),
            leaseTimeout: 1,
        );

        $bench->cloud->invoke($this->start('slow'));

        self::assertInstanceOf(Report::class, $takeover);
        self::assertSame(Outcome::Completed, $takeover->outcome, 'The guard stops a wake only when the run has no lease.');
        self::assertSame(2, $takeover->executedAttempt);
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_suspended_run_whose_timer_is_not_due_returns_the_same_wait_and_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench, paymentDeadline: new DateTimeImmutable('-1 minute'), deliveryAt: new DateTimeImmutable('+1 hour'));
        $started = $bench->cloud->invoke($this->start('order'));
        $sleeping = $bench->cloud->invoke($this->wake('order', $started));

        $early = $bench->cloud->invoke($this->wake('order', $sleeping));

        self::assertSame(Outcome::Suspended, $early->outcome);
        self::assertNull($early->executedAttempt, 'A wake that comes too early opens no segment.');
        self::assertSame('sleep_until', $early->run['wait']['type']);
        self::assertSame($sleeping->run, $early->run);
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_whose_event_deadline_has_not_passed_returns_the_same_wait_and_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench, paymentDeadline: new DateTimeImmutable('+1 hour'));
        $started = $bench->cloud->invoke($this->start('order'));

        $early = $bench->cloud->invoke($this->wake('order', $started));

        self::assertSame(Outcome::Suspended, $early->outcome);
        self::assertNull($early->executedAttempt);
        self::assertSame('wait_for_event', $early->run['wait']['type']);
        self::assertSame($started->run, $early->run);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_platform_wake_that_cannot_read_the_store_is_aborted_as_not_executed_and_returned(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench, paymentDeadline: new DateTimeImmutable('-1 minute'));
        $started = $bench->cloud->invoke($this->start('order'));
        $this->failStoreOn('get', self::WORKFLOW_ID);

        $report = $bench->cloud->invoke($this->wake('order', $started));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertSame(StoreUnavailable::class, $report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->claimed);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_an_application_wake_that_cannot_read_the_store_is_reported_then_thrown_as_not_executed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench, paymentDeadline: new DateTimeImmutable('-1 minute'));
        $started = $bench->cloud->invoke($this->start('order'));
        $this->failStoreOn('get', self::WORKFLOW_ID);

        $thrown = $this->thrownBy($this->wake('order', $started, self::APPLICATION));

        self::assertSame(Code::NotExecuted, $thrown->reason);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    protected function registerOrder(TestBench $bench, ?DateTimeImmutable $paymentDeadline, ?DateTimeImmutable $deliveryAt = null): void
    {
        $executions = $bench->executions;
        $deliveryAt ??= new DateTimeImmutable('@0');

        $bench->cloud->register(
            'order',
            static fn (): WaitingWorkflow => new WaitingWorkflow($executions, $paymentDeadline, $deliveryAt),
        );
    }

    /**
     * A run is started through Neuron alone, as before the migration to the SDK: a plain workflow takes no
     * lease. While its node executes, a wake fenced with its snapshot comes in through the SDK.
     *
     * @param string|null $fencedRunId the run ID the wake names instead of the one in the snapshot
     * @param int|null $fencedAttempt the attempt the wake names instead of the one in the snapshot
     * @return Report what the wake reported
     */
    protected function wakeWhileARunStartedOutsideTheSdkExecutes(
        TestBench $bench,
        ?string $fencedRunId = null,
        ?int $fencedAttempt = null,
    ): Report {
        $executions = $bench->executions;
        $bench->cloud->register('legacy', static fn (): BusyWorkflow => new BusyWorkflow($executions, static function (): void {
        }));

        $report = null;
        $outside = (new BusyWorkflow($executions, function () use ($bench, $fencedRunId, $fencedAttempt, &$outside, &$report): void {
            $report = $bench->cloud->invoke($this->wakeFencedWith($outside->inspect(), $fencedRunId, $fencedAttempt));
        }))->for(self::WORKFLOW_ID)->setPersistence(($bench->persistence)());

        self::assertSame(WorkflowStatus::Completed, $outside->run()->getStatus(), 'The run was not disturbed.');
        self::assertInstanceOf(Report::class, $report);

        return $report;
    }

    protected function wakeFencedWith(WorkflowRunSnapshot $run, ?string $runId = null, ?int $attempt = null): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_wake',
            'verb' => Verb::Wake->value,
            'origin' => self::PLATFORM,
            'definition' => 'legacy',
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $runId ?? $run->runId,
            'attempt' => $attempt ?? $run->executionAttempt,
        ]);
    }
}
