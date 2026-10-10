<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\Simulator;

use NeuronCore\Cloud\Tests\Support\EndToEnd\Shown;
use NeuronCore\Cloud\Tests\Support\EndToEnd\SimulatorTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_diff_key;
use function array_map;
use function count;
use function time;

/**
 * Rules 8 to 12 and 14 of appendix B of the design: when the platform
 * simulator wakes a run, and when it asks instead.
 */
class TimerRulesTest extends SimulatorTestCase
{
    /** A lease deadline far enough ahead for no test to see it pass. */
    protected const LEASE = 4_000_000_000;

    // ----------------------------------------------------------------
    // Rule 8: one timer per wait
    // ----------------------------------------------------------------

    public function test_the_wake_of_a_timer_is_not_sent_before_the_timer_is_due(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() + 60));

        self::assertSame([], $this->tick());
    }

    public function test_the_wake_of_a_timer_is_sent_when_the_timer_is_due(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() + 60));

        $this->timePasses(60);

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_the_deadline_of_an_event_wait_is_a_timer_like_any_other(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() - 1, eventName: 'payment.received'));

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_one_wake_is_sent_for_a_wait_however_often_the_clock_ticks(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));

        $this->tick();
        $this->tick();
        $this->tick();

        self::assertCount(1, $this->sent(WireLog::WAKE));
    }

    public function test_a_wake_reported_with_the_same_wait_and_attempt_came_too_early_and_is_not_sent_again_at_once(): void
    {
        $this->aTimerWakeCameTooEarly();

        self::assertSame([], $this->tick());
    }

    public function test_a_wake_that_came_too_early_is_sent_again_after_the_early_retry_with_its_invocation_id(): void
    {
        $this->aTimerWakeCameTooEarly();

        $this->timePasses(self::EARLY_TIMER_RETRY);

        self::assertSame(['wake timer:1: 202'], $this->tick());
        [$first, $again] = $this->sent(WireLog::WAKE);
        self::assertSame($first, $again);
    }

    public function test_a_wake_whose_own_report_is_not_the_too_early_one_is_sent_again_with_the_back_off_of_a_re_send(): void
    {
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $runId = $this->runOfTheApplication(run: $run);
        $this->tick();
        // The platform still holds that wait and attempt, but the wake itself was reported aborted: not "too early".
        $this->reportOfTheWake($runId, 'aborted', $run, ['code' => 'unknown_version']);

        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['wake timer:1: 202'], $this->tick(), 'After the back-off of a re-send, which is shorter than the early retry.');
    }

    public function test_the_next_wait_of_a_run_gets_a_timer_of_its_own(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->reportOfTheWake($runId, 'suspended', Shown::suspended($runId, 3, time() - 1, 2), ['executedAttempt' => 3]);

        self::assertSame(['wake timer:2: 202'], $this->tick());
        self::assertSame([2, 3], array_column($this->sent(WireLog::WAKE), 'attempt'));
    }

    // ----------------------------------------------------------------
    // Rule 9: retries are wakes, under a policy
    // ----------------------------------------------------------------

    public function test_a_failed_run_is_not_woken_before_the_delay_of_the_retry_policy(): void
    {
        $this->runOfTheApplication(run: Shown::failed(self::runId(), 2));

        self::assertSame([], $this->tick());
    }

    public function test_a_failed_run_is_woken_after_the_delay_of_the_retry_policy_with_the_attempt_just_reported(): void
    {
        $this->runOfTheApplication(run: Shown::failed(self::runId(), 2));

        $this->timePasses(self::RETRY_DELAY);

        self::assertSame(['wake retry: 202'], $this->tick());
        self::assertSame(2, $this->sent(WireLog::WAKE)[0]['attempt']);
    }

    public function test_a_failure_is_counted_once_for_a_run_id_and_an_attempt(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 2));

        $this->snapshotArrives($runId, Shown::failed($runId, 2));

        self::assertSame([2], array_column($this->held($runId)['failures'], 'attempt'));
    }

    public function test_the_failure_of_every_attempt_is_counted(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 2));

        $this->snapshotArrives($runId, Shown::failed($runId, 3));

        self::assertSame([2, 3], array_column($this->held($runId)['failures'], 'attempt'));
    }

    public function test_the_count_of_the_policy_is_not_reset_by_a_segment_that_ends_without_failing(): void
    {
        $this->platform->configure(['retryAttempts' => 1, 'retryDelay' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));
        $this->tick();
        // The retry succeeded and the run went on to its next wait, where it fails for the second time in its life.
        $this->snapshotArrives($runId, Shown::suspended($runId, 2, null, 2));

        $this->snapshotArrives($runId, Shown::failed($runId, 3));

        self::assertSame([], $this->tick(), 'One failure per run ID and attempt, for the life of the run.');
        self::assertSame(['retries_exhausted'], array_column($this->platform->alerts(), 'kind'));
    }

    public function test_the_platform_remembers_the_class_of_the_exception_of_a_failure_and_nothing_else_of_it(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 2));

        self::assertSame(
            [['attempt' => 2, 'kind' => 'failed', 'errorClass' => 'App\\ShippingFailed']],
            array_map(static fn (array $failure): array => array_diff_key($failure, ['countedAt' => 0]), $this->held($runId)['failures']),
        );
    }

    public function test_the_policy_retries_as_many_failures_as_it_has_attempts(): void
    {
        $this->platform->configure(['retryAttempts' => 2, 'retryDelay' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));
        $this->tick();

        $this->snapshotArrives($runId, Shown::failed($runId, 2));

        self::assertSame(['wake retry: 202'], $this->tick());
    }

    public function test_a_policy_that_gave_up_leaves_the_run_failed_and_sends_no_wake(): void
    {
        $this->platform->configure(['retryAttempts' => 1, 'retryDelay' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));
        $this->tick();

        $this->snapshotArrives($runId, Shown::failed($runId, 2));

        self::assertSame([], $this->tick());
        self::assertSame(['open', 'failed'], [$this->held($runId)['state'], $this->held($runId)['run']['status']]);
    }

    public function test_a_policy_that_gave_up_raises_an_alert(): void
    {
        $this->platform->configure(['retryAttempts' => 1]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));

        $this->snapshotArrives($runId, Shown::failed($runId, 2));

        self::assertSame([['retries_exhausted', $runId]], $this->alerts());
    }

    // ----------------------------------------------------------------
    // Rule 10: silence means ask
    // ----------------------------------------------------------------

    public function test_an_announcement_no_report_follows_within_its_lease_is_asked_about(): void
    {
        $runId = $this->runOfTheApplication();
        $this->application->announce($runId, self::WORKFLOW_ID, ['verb' => 'signal', 'attempt' => 1, 'leaseSeconds' => 60]);

        $this->timePasses(60);

        self::assertSame(['inspect segment_silence: 200'], $this->tick());
    }

    public function test_a_snapshot_read_after_the_lease_of_a_silent_announcement_has_passed_answers_the_question(): void
    {
        $runId = self::runId();
        $this->application->announce($runId, self::WORKFLOW_ID, ['leaseSeconds' => 1, 'at' => time() - 1]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null));

        self::assertSame([], $this->tick());
    }

    public function test_a_snapshot_read_before_the_lease_of_a_silent_announcement_ran_out_does_not_answer_the_question_however_late_it_arrives(): void
    {
        $now = time();
        $runId = $this->runOfTheApplication();
        $this->application->announce($runId, self::WORKFLOW_ID, ['verb' => 'signal', 'attempt' => 1, 'leaseSeconds' => 60, 'at' => $now]);
        $this->timePasses(61);

        // The report of an earlier call arrives now: the application read its snapshot before the segment was announced.
        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null, eventName: 'payment.received'), ['at' => $now - 5]);

        self::assertSame(['inspect segment_silence: 200'], $this->tick());
    }

    public function test_an_announcement_is_not_asked_about_while_its_lease_runs(): void
    {
        $runId = $this->runOfTheApplication();
        $this->application->announce($runId, self::WORKFLOW_ID, ['verb' => 'signal', 'attempt' => 1, 'leaseSeconds' => 60]);

        self::assertSame([], $this->tick());
    }

    public function test_an_announcement_its_report_followed_is_not_asked_about(): void
    {
        $runId = $this->runOfTheApplication();
        $segment = ['invocationId' => 'inv_signal_of_the_shop', 'verb' => 'signal', 'at' => time() - 1];
        $this->application->announce($runId, self::WORKFLOW_ID, $segment + ['attempt' => 1, 'leaseSeconds' => 1]);

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 2, null, 2), $segment + ['executedAttempt' => 2]);

        self::assertSame([], $this->tick());
    }

    public function test_a_later_report_that_arrived_before_its_announcement_leaves_no_silence_to_ask_about(): void
    {
        $now = time();
        $runId = $this->runOfTheApplication();
        $segment = ['invocationId' => 'inv_signal_of_the_shop', 'verb' => 'signal'];
        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 2, null, 2), $segment + ['executedAttempt' => 2, 'at' => $now - 1]);

        // The announcement was built before the segment and waited in the queue of the application.
        $this->application->announce($runId, self::WORKFLOW_ID, $segment + ['attempt' => 1, 'leaseSeconds' => 1, 'at' => $now - 2]);

        self::assertSame([], $this->tick());
    }

    public function test_an_announcement_that_arrives_after_a_report_of_the_same_second_is_asked_about(): void
    {
        $now = time();
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, $now - 5));
        $this->tick();
        $this->platform->configure(['wakeSilence' => 86400, 'resendDelay' => 86400]);
        $wake = ['invocationId' => $this->sent(WireLog::WAKE)[0]['invocationId'], 'verb' => 'wake', 'origin' => 'platform', 'at' => $now];
        $this->application->announce($runId, self::WORKFLOW_ID, $wake + ['attempt' => 2, 'leaseSeconds' => 60]);
        $this->application->report($runId, self::WORKFLOW_ID, 'aborted', Shown::suspended($runId, 2, $now - 5), $wake + ['code' => 'not_executed']);

        // The queue delivers the wake a second time within that second: the copy is announced, and its worker dies.
        $this->application->announce($runId, self::WORKFLOW_ID, $wake + ['attempt' => 2, 'leaseSeconds' => 60]);
        $this->timePasses(60);

        self::assertSame(['inspect segment_silence: 200'], $this->tick(), 'Nothing tells that the report followed this announcement.');
    }

    public function test_a_wake_no_announcement_follows_is_not_asked_about_before_the_wake_silence_has_passed(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        self::assertSame([], $this->tick());
    }

    public function test_a_wake_no_announcement_follows_is_asked_about_after_the_wake_silence(): void
    {
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->timePasses(self::WAKE_SILENCE);

        self::assertSame(['inspect wake_silence: 200'], $this->tick());
    }

    public function test_the_silent_wake_is_sent_again_with_its_invocation_id_when_the_inspect_shows_the_attempt_unchanged(): void
    {
        $this->platform->configure(['wakeSilence' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->endpoint->showNext(Shown::suspended($runId, 2, time() - 1));
        $this->tick();
        $this->tick();

        self::assertSame(['wake timer:1: 202'], $this->tick());
        [$first, $again] = $this->sent(WireLog::WAKE);
        self::assertSame($first, $again);
    }

    public function test_the_silent_wake_is_not_sent_again_when_the_inspect_shows_another_attempt(): void
    {
        $this->platform->configure(['wakeSilence' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->endpoint->showNext(Shown::suspended($runId, 3, null, 2));
        $this->tick();
        $this->tick();

        self::assertSame([], $this->tick());
        self::assertCount(1, $this->sent(WireLog::WAKE));
    }

    public function test_a_wake_that_was_announced_is_not_asked_about_for_its_silence(): void
    {
        $this->platform->configure(['wakeSilence' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->application->announce($runId, self::WORKFLOW_ID, [
            'invocationId' => $this->sent(WireLog::WAKE)[0]['invocationId'],
            'verb' => 'wake',
            'origin' => 'platform',
            'attempt' => 2,
        ]);

        self::assertSame([], $this->tick());
    }

    // ----------------------------------------------------------------
    // Rule 11: a dead run is retried like a failed one
    // ----------------------------------------------------------------

    public function test_a_run_shown_running_past_its_lease_is_woken_with_the_attempt_shown(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = self::runId();

        $this->snapshotArrives($runId, Shown::running($runId, 3, time() - 1));

        self::assertSame(['wake retry: 202'], $this->tick());
        self::assertSame(3, $this->sent(WireLog::WAKE)[0]['attempt']);
    }

    public function test_a_dead_run_is_counted_under_the_same_policy_as_a_failed_one(): void
    {
        $this->platform->configure(['retryAttempts' => 1, 'retryDelay' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));
        $this->tick();

        $this->snapshotArrives($runId, Shown::running($runId, 2, time() - 1));

        self::assertSame(['failed', 'dead'], array_column($this->held($runId)['failures'], 'kind'));
        self::assertSame([], $this->tick());
    }

    public function test_a_run_shown_running_is_neither_woken_nor_asked_about_before_its_lease_deadline(): void
    {
        $runId = self::runId();

        $this->snapshotArrives($runId, Shown::running($runId, 3, time() + 60));

        self::assertSame([], $this->tick());
    }

    public function test_a_lease_that_has_passed_by_the_clock_of_the_platform_alone_is_followed_by_a_question_and_no_wake(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = self::runId();
        // The clock of the platform runs two minutes ahead of the application's, which wrote the lease and the snapshot.
        $this->timePasses(120);

        $this->snapshotArrives($runId, Shown::running($runId, 3, time() + 60));

        self::assertSame([], $this->tick(), 'The lease has not passed by the "at" of the snapshot that shows it.');
        self::assertSame(['lease_passed'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_a_run_shown_running_is_asked_about_when_its_lease_deadline_has_passed(): void
    {
        $runId = self::runId();
        $this->snapshotArrives($runId, Shown::running($runId, 3, time() + 60));

        $this->timePasses(60);

        self::assertSame(['inspect lease_passed: 200'], $this->tick());
    }

    public function test_the_run_is_woken_when_the_answer_at_its_lease_deadline_shows_the_lease_passed(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = self::runId();
        $leaseExpiresAt = time() + 60;
        $this->snapshotArrives($runId, Shown::running($runId, 3, $leaseExpiresAt));
        $this->endpoint->showNext(Shown::running($runId, 3, $leaseExpiresAt), at: $leaseExpiresAt);
        $this->timePasses(60);
        $this->tick();

        self::assertSame(['wake retry: 202'], $this->tick());
        self::assertSame(3, $this->sent(WireLog::WAKE)[0]['attempt']);
    }

    public function test_a_run_that_runs_with_no_lease_is_never_woken(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = self::runId();

        $this->snapshotArrives($runId, Shown::running($runId, 3, null));

        self::assertSame([], $this->tick());
        self::assertSame(['periodic_read'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_a_run_that_runs_with_no_lease_raises_an_alert(): void
    {
        $runId = self::runId();

        $this->snapshotArrives($runId, Shown::running($runId, 3, null));

        self::assertSame([['running_without_lease', $runId]], $this->alerts());
    }

    // ----------------------------------------------------------------
    // Rule 12: a start that stays open is asked about, not replaced
    // ----------------------------------------------------------------

    public function test_a_silent_start_is_not_asked_about_at_its_use_by_time(): void
    {
        $this->startByThePlatform();

        $this->theClockReaches($this->sent(WireLog::START)[0]['expiresAt']);

        self::assertSame([], $this->tick());
    }

    public function test_a_silent_start_is_asked_about_with_its_run_id_once_its_use_by_time_and_the_start_silence_have_passed(): void
    {
        $runId = $this->startByThePlatform();

        $this->theClockReaches($this->sent(WireLog::START)[0]['expiresAt'] + self::START_SILENCE);

        self::assertSame(['inspect start_silence: 200'], $this->tick());
        self::assertSame($runId, $this->sent(WireLog::INSPECT)[0]['runId']);
    }

    public function test_a_start_no_copy_claimed_is_closed_as_lost(): void
    {
        $runId = $this->anOpenStartIsAskedAbout(shows: null, claimed: false);

        self::assertSame('lost', $this->held($runId)['state']);
    }

    public function test_a_start_that_was_claimed_and_left_no_run_is_closed_as_ended_with_no_known_outcome(): void
    {
        $runId = $this->anOpenStartIsAskedAbout(shows: null, claimed: true);

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_an_unclaimed_start_asked_about_after_its_ledger_entry_may_be_gone_counts_as_unknown_too(): void
    {
        $this->platform->configure(['ledgerRetention' => 0]);

        $runId = $this->anOpenStartIsAskedAbout(shows: null, claimed: false);

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_a_start_whose_run_holds_the_workflow_id_is_planned_from_the_snapshot(): void
    {
        $runId = $this->anOpenStartIsAskedAbout(shows: static fn (string $runId): array => Shown::suspended($runId, 1, time() - 1), claimed: true);

        self::assertSame('open', $this->held($runId)['state']);
        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_the_platform_issues_no_replacement_for_a_lost_start(): void
    {
        $this->anOpenStartIsAskedAbout(shows: null, claimed: false);

        self::assertSame([], $this->tick());
        self::assertCount(1, $this->platform->runsOf(self::WORKFLOW_ID));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function refusalsOfAMalformedMessage(): array
    {
        return ['a 400' => [400], 'a 405' => [405]];
    }

    #[DataProvider('refusalsOfAMalformedMessage')]
    public function test_an_inspect_of_a_start_that_the_endpoint_refuses_raises_an_alert_and_leaves_the_start_open(int $status): void
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->answerNext('inspect', $status, ['code' => 'invalid_request']);

        self::assertSame(["inspect start_silence: {$status}"], $this->tick());

        self::assertSame('open', $this->held($runId)['state']);
        self::assertSame([['refused_by_endpoint', $runId]], $this->alerts());
    }

    public function test_a_start_whose_inspect_the_endpoint_refused_is_never_asked_about_again(): void
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->answerNext('inspect', 400, ['code' => 'invalid_request']);
        $this->tick();

        $this->timePasses(7 * self::PERIODIC_READ);

        self::assertSame([[], []], [$this->tick(), $this->held($runId)['timers']], 'A start has no periodic read: the alert is all that is left of it.');
    }

    // ----------------------------------------------------------------
    // Rule 14: read a wait that has no timer now and then
    // ----------------------------------------------------------------

    public function test_a_wait_with_no_timer_is_not_read_before_its_period_has_passed(): void
    {
        $this->runOfTheApplication();

        self::assertSame([], $this->tick());
    }

    public function test_a_wait_with_no_timer_is_read_when_its_period_has_passed(): void
    {
        $runId = $this->runOfTheApplication();

        $this->timePasses(self::PERIODIC_READ);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
        self::assertSame($runId, $this->sent(WireLog::INSPECT)[0]['runId']);
    }

    public function test_a_wait_with_no_timer_is_read_less_often_each_time_it_is_found_unchanged(): void
    {
        $this->aWaitWithNoTimerWasReadAndFoundUnchanged();

        $this->timePasses(self::PERIODIC_READ);
        self::assertSame([], $this->tick(), 'Not after one more day.');
        $this->timePasses(self::PERIODIC_READ);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    public function test_the_period_between_two_reads_never_exceeds_its_cap(): void
    {
        $this->platform->configure(['periodicReadCap' => self::PERIODIC_READ]);
        $this->aWaitWithNoTimerWasReadAndFoundUnchanged();

        $this->timePasses(self::PERIODIC_READ);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    public function test_a_read_that_finds_the_run_gone_closes_it(): void
    {
        $this->platform->configure(['periodicRead' => 0]);
        $runId = $this->runOfTheApplication();
        $this->endpoint->showNext(null, false, at: time() + 1);

        $this->tick();

        self::assertSame('ended_unknown', $this->held($runId)['state'], 'It was abandoned, reset or answered without a report.');
    }

    public function test_a_wait_with_a_timer_is_not_read_periodically(): void
    {
        $this->platform->configure(['periodicRead' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() + 3600));

        self::assertSame([], $this->tick());
        self::assertSame(['wake'], array_column($this->held($runId)['timers'], 'action'));
    }

    // ----------------------------------------------------------------
    // What the tests are written with
    // ----------------------------------------------------------------

    /**
     * A snapshot of the workflow ID reaches the platform in the report of a wake that Neuron refused.
     *
     * @param array<string, mixed>|null $run
     * @param array<string, mixed> $fields what the report says differently
     */
    protected function snapshotArrives(string $runId, ?array $run, array $fields = []): void
    {
        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, 'refused', $run, $fields + ['verb' => 'wake', 'code' => 'stale_attempt']));
    }

    /**
     * A worker reports the last wake the platform sent.
     *
     * @param array<string, mixed>|null $run
     * @param array<string, mixed> $fields what the report says beside its outcome
     */
    protected function reportOfTheWake(string $runId, string $outcome, ?array $run, array $fields = []): void
    {
        $wakes = $this->sent(WireLog::WAKE);

        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, $outcome, $run, $fields + [
            'invocationId' => $wakes[count($wakes) - 1]['invocationId'],
            'verb' => 'wake',
            'origin' => 'platform',
        ]));
    }

    /**
     * The platform woke a timer, and the application answered with the same wait and attempt: by its clock
     * the timer is not due yet.
     *
     * @return string the run ID
     */
    protected function aTimerWakeCameTooEarly(): string
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->reportOfTheWake($runId, 'suspended', Shown::suspended($runId, 2, time() - 1));

        return $runId;
    }

    /**
     * A start of the platform is still open when its use-by time and the start silence have passed, and the
     * platform asks about it.
     *
     * @param (callable(string): array<string, mixed>)|null $shows the run the answer shows holding the workflow ID, given the run ID of the start; null: none
     * @return string the run ID of the start
     */
    protected function anOpenStartIsAskedAbout(?callable $shows, bool $claimed): string
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->showNext($shows === null ? null : $shows($runId), $claimed);

        self::assertSame(['inspect start_silence: 200'], $this->tick());

        return $runId;
    }

    /**
     * A day has passed, and the platform read a run that waits with no timer and found it as it held it.
     */
    protected function aWaitWithNoTimerWasReadAndFoundUnchanged(): void
    {
        $runId = $this->runOfTheApplication();
        $this->endpoint->showNext($this->held($runId)['run']);
        $this->timePasses(self::PERIODIC_READ);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }
}
