<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Contract;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\Matrix\FirstCopy;
use NeuronCore\Cloud\Tests\Support\Matrix\Kind;
use NeuronCore\Cloud\Tests\Support\Matrix\Redelivery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The redelivery matrix of appendix C, replayed through invoke(): a second,
 * identical copy of one invocation, delivered in one state of the first copy.
 * In no cell may the second copy execute a node body.
 *
 * Each cell: what the second copy reports, then the run the store holds once
 * it has reported.
 *
 *                                 state of the first copy when the second one arrives
 *                               |           |           | completed, | completed, | completed,  |
 *                               |           |           | report     | report     | platform    |
 *     second copy of            | executing | suspended | recorded   | lost       | unreachable | failed
 *     --------------------------+-----------+-----------+------------+------------+-------------+-------
 *     a start the platform sent | AC  R1    | AC  S1    | AC  -      | AC  -      | AC  -       | AC  F1
 *     a dispatched start        | AC  R1    | AC  S1    | AC  -      | AC  -      | AC  -       | AC  F1
 *     an answer                 | SA  R2    | SA  S2    | SR  -      | SR  -      | SR  -       | SA  F2
 *     a signal                  | SA  R2    | SA  S2    | SR  -      | SR  -      | SR  -       | SA  F2
 *     a wake for a due timer    | SA  R2    | SA  S2    | SR  -      | SR  -      | SR  -       | SA  F2
 *     a wake for a failed run   | SA  R2    | SA  S2    | SR  -      | SR  -      | SR  -       | SA  F2
 *     a wake for a dead run     | SA  R2    | SA  S2    | SR  -      | SR  -      | SR  -       | SA  F2
 *
 *     AC          rejected: already_claimed. The ledger holds the run ID, whatever
 *                 became of the run (9.2; section 7, rows 1 and 2).
 *     SA          refused: stale_attempt. The first copy raised the attempt when
 *                 Neuron admitted it, and the second carries the old one (section 7, row 4).
 *     SR          refused: stale_run. The first copy completed the run and its
 *                 records are gone (section 7, row 4).
 *     R1, S1, F1  The run is running, suspended or failed, at attempt 1: the one
 *                 the start opened.
 *     R2, S2, F2  The same at attempt 2: the first copy continued a run that had
 *                 stopped at attempt 1.
 *     -           No run holds the workflow ID.
 *
 * In every cell the ledger holds the run ID once, the second copy leaves the
 * run and the ledger as it found them, opens no segment, and its report shows
 * the run as the store holds it. A start reports "claimed: true", every other
 * kind "claimed: null".
 *
 * How Redelivery stages a cell:
 *
 * - A start is delivered as it is. An answer, a signal and a wake need a run:
 *   the platform starts one, and its first node waits for an event, sleeps
 *   until a time that has passed, or throws. The invocation is fenced with the
 *   run ID and the attempt read from the store at that point (9.7, step 4).
 * - Executing: the second copy is delivered from inside a node body of the
 *   first, through the same invoke() and in the same process, the only one
 *   that sees an InMemoryPersistence store. The first copy then completes.
 * - Suspended: the first copy stops at a wait that an answer, a signal and a
 *   wake could each continue, an event wait whose deadline has passed.
 * - Report lost: the platform fails the report of the first copy, and an
 *   observe waits in the queue.
 * - Platform unreachable: the platform fails the announcement and the report
 *   of both copies. Four notices wait in the queue and are delivered once it
 *   answers again, which is when the platform hears of the second copy.
 *
 * One kind cannot exist as appendix C words it, and is replaced by the nearest
 * one that can. A dead run is running under a lease that has passed: its
 * worker stopped without a last write. Neuron commits "failed" for whatever a
 * node throws, so a worker leaves that record only when it is killed, or when
 * its last write does not arrive. A test cannot kill its own process and keep
 * an InMemoryPersistence store, which lives in it. So the worker of the start
 * is cut off from its store while its node is in flight: its failure is never
 * written, and the store holds the records a killed worker leaves. The lease
 * passes one real second later.
 */
class RedeliveryMatrixTest extends CloudTestCase
{
    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_while_it_executes_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_after_it_suspended_the_run_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_after_it_completed_the_run_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_after_it_completed_the_run_and_lost_its_report_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_redelivered_after_it_failed_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::PlatformStart, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_while_it_executes_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_after_it_suspended_the_run_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_after_it_completed_the_run_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_after_it_completed_the_run_and_lost_its_report_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_redelivered_after_it_failed_is_rejected_as_already_claimed(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::DispatchedStart, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Rejected, Code::AlreadyClaimed->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 1);
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_while_it_executes_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_after_it_suspended_the_run_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_after_it_completed_the_run_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_after_it_completed_the_run_and_lost_its_report_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_an_answer_redelivered_after_it_failed_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Answer, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_while_it_executes_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_after_it_suspended_the_run_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_after_it_completed_the_run_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_after_it_completed_the_run_and_lost_its_report_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_signal_redelivered_after_it_failed_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::Signal, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_while_it_executes_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_after_it_suspended_the_run_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_after_it_completed_the_run_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_after_it_completed_the_run_and_lost_its_report_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_due_timer_redelivered_after_it_failed_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDueTimer, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_while_it_executes_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_after_it_suspended_the_run_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_after_it_completed_the_run_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_after_it_completed_the_run_and_lost_its_report_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_failed_run_redelivered_after_it_failed_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfFailedRun, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_while_it_executes_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::Executing);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Running, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_after_it_suspended_the_run_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::Suspended);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Suspended, attempt: 2);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_after_it_completed_the_run_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::Completed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_after_it_completed_the_run_and_lost_its_report_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::CompletedReportLost);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_after_it_completed_the_run_with_the_platform_unreachable_is_refused_as_stale_run(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::CompletedPlatformUnreachable);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleRun->value);
        $redelivery->assertStoreHoldsNoRun();
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_dead_run_redelivered_after_it_failed_is_refused_as_stale_attempt(string $backend): void
    {
        $redelivery = $this->replay($backend, Kind::WakeOfDeadRun, FirstCopy::Failed);

        $redelivery->assertSecondCopyRanNothing();
        $redelivery->assertSecondCopyReported(Outcome::Refused, RefusalReason::StaleAttempt->value);
        $redelivery->assertStoreHolds(WorkflowStatus::Failed, attempt: 2);
    }

    protected function replay(string $backend, Kind $kind, FirstCopy $state): Redelivery
    {
        return Redelivery::replay($this->benchOn($backend), $kind, $state);
    }
}
