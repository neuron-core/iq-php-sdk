<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\Simulator;

use NeuronCore\Cloud\Tests\Support\EndToEnd\Shown;
use NeuronCore\Cloud\Tests\Support\EndToEnd\SimulatorTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_fill;
use function time;

/**
 * Rules 6 and 7 of appendix B of the design: the platform simulator plans
 * from a snapshot that was read, and knows when a run has ended.
 */
class SnapshotRulesTest extends SimulatorTestCase
{
    /** A lease deadline far enough ahead for no test to see it pass. */
    protected const LEASE = 4_000_000_000;

    // ----------------------------------------------------------------
    // Rule 6: which snapshot replaces what the platform holds
    // ----------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function snapshots(): array
    {
        $runId = '1-0192f3a8';

        return [
            'a higher attempt replaces' => [Shown::suspended($runId, 1, null), Shown::suspended($runId, 2, null, 2), true],
            'a lower attempt does not' => [Shown::suspended($runId, 2, null, 2), Shown::suspended($runId, 1, null), false],
            'the same attempt with a later lease deadline replaces' => [Shown::running($runId, 2, self::LEASE), Shown::running($runId, 2, self::LEASE + 10), true],
            'the same attempt with an earlier lease deadline does not' => [Shown::running($runId, 2, self::LEASE), Shown::running($runId, 2, self::LEASE - 10), false],
            'the same attempt with a status that left running replaces' => [Shown::running($runId, 2, self::LEASE), Shown::failed($runId, 2), true],
            'the same attempt back to running does not' => [Shown::failed($runId, 2), Shown::running($runId, 2, self::LEASE), false],
            'the same attempt with another status that never ran does not' => [Shown::suspended($runId, 2, null), Shown::failed($runId, 2), false],
        ];
    }

    /**
     * @param array<string, mixed> $held
     * @param array<string, mixed> $shown
     */
    #[DataProvider('snapshots')]
    public function test_a_snapshot_that_names_a_run_replaces_what_the_platform_holds_only_when_it_is_newer(array $held, array $shown, bool $replaces): void
    {
        $this->snapshotArrives($held['runId'], $held);

        $this->snapshotArrives($held['runId'], $shown);

        self::assertSame($replaces ? $shown : $held, $this->held($held['runId'])['run']);
    }

    public function test_a_report_whose_snapshot_was_not_read_changes_nothing_of_what_the_platform_holds(): void
    {
        $runId = $this->runOfTheApplication();
        $held = $this->held($runId)['run'];

        $this->snapshotArrives($runId, null, ['snapshotRead' => false]);

        self::assertSame(['open', $held], [$this->held($runId)['state'], $this->held($runId)['run']]);
    }

    public function test_a_report_whose_snapshot_was_not_read_is_followed_by_an_inspect_after_the_follow_up_delay(): void
    {
        $runId = $this->runOfTheApplication();
        $this->snapshotArrives($runId, null, ['snapshotRead' => false]);

        self::assertSame([], $this->tick());
        $this->timePasses(self::FOLLOW_UP);

        self::assertSame(['inspect unread_snapshot: 200'], $this->tick());
    }

    public function test_the_follow_up_doubles_when_the_inspect_cannot_read_the_store_either(): void
    {
        $this->anInspectCouldNotReadTheStoreEither();

        $this->timePasses(self::FOLLOW_UP);
        self::assertSame([], $this->tick(), 'Not after the first delay once more.');
        $this->timePasses(self::FOLLOW_UP);

        self::assertSame(['inspect unread_snapshot: 200'], $this->tick());
    }

    public function test_the_follow_up_stops_doubling_at_its_cap(): void
    {
        $this->platform->configure(['unreadFollowUpCap' => self::FOLLOW_UP]);
        $this->anInspectCouldNotReadTheStoreEither();

        $this->timePasses(self::FOLLOW_UP);

        self::assertSame(['inspect unread_snapshot: 200'], $this->tick());
    }

    public function test_an_unread_answer_to_the_question_about_a_silent_segment_is_asked_for_again_after_the_follow_up_delay(): void
    {
        $this->announcedAndSilentSince(time() - 1);

        $this->assertAnUnreadAnswerIsFollowedUp('segment_silence');
    }

    public function test_an_unread_answer_to_the_question_about_a_silent_start_is_asked_for_again_after_the_follow_up_delay(): void
    {
        $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);

        $this->assertAnUnreadAnswerIsFollowedUp('start_silence');
    }

    public function test_an_unread_answer_to_the_question_after_an_abandon_is_asked_for_again_after_the_follow_up_delay(): void
    {
        $runId = $this->startByThePlatform();
        $this->abandonIsAnswered409('stale_run', null);
        $this->platform->abandon($runId);
        $this->tick();

        $this->assertAnUnreadAnswerIsFollowedUp('abandon_left_open');
    }

    public function test_an_unread_answer_to_the_question_about_a_closed_run_a_snapshot_named_is_asked_for_again_after_the_follow_up_delay(): void
    {
        $this->aSnapshotOfTheSecondThatClosedARunNamesIt();
        $this->timePasses(1);

        $this->assertAnUnreadAnswerIsFollowedUp('same_second');
    }

    public function test_a_snapshot_that_was_read_ends_the_follow_up(): void
    {
        $runId = $this->runOfTheApplication();
        $this->snapshotArrives($runId, null, ['snapshotRead' => false]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null));

        self::assertNotContains('unread_snapshot', array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_the_outcome_of_a_report_whose_snapshot_was_not_read_still_closes_its_run(): void
    {
        $runId = $this->runOfTheApplication();

        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2, 'snapshotRead' => false]);

        self::assertSame('completed', $this->held($runId)['state']);
    }

    public function test_a_run_that_a_question_of_the_same_tick_showed_gone_is_not_woken_in_that_tick(): void
    {
        $now = time();
        $gone = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, $now - 5));
        $successor = self::runId();
        $this->application->announce($successor, self::WORKFLOW_ID, ['leaseSeconds' => 1, 'at' => $now - 10]);
        $this->endpoint->showNext(Shown::suspended($successor, 1, null, eventName: 'payment.received'), at: time() + 60);

        self::assertSame(['inspect segment_silence: 200'], $this->tick(), 'The wake that was due when the tick began is not sent from a plan the answer made stale.');
        self::assertSame('ended_unknown', $this->held($gone)['state']);
    }

    // ----------------------------------------------------------------
    // Rule 7: a completed report, and a snapshot that no longer shows the run
    // ----------------------------------------------------------------

    public function test_a_completed_report_closes_the_run_it_names_and_nothing_more_is_planned(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() - 1));

        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2]);

        self::assertSame(['completed', null, []], [$this->held($runId)['state'], $this->held($runId)['run'], $this->held($runId)['timers']]);
        self::assertSame([], $this->tick());
    }

    public function test_a_snapshot_that_shows_no_run_closes_a_run_an_earlier_snapshot_named_as_ended_with_no_known_outcome(): void
    {
        $runId = $this->runOfTheApplication();

        $this->snapshotArrives($runId, null, ['at' => time() + 1]);

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_a_snapshot_that_shows_another_run_closes_a_run_an_earlier_snapshot_named(): void
    {
        $runId = $this->runOfTheApplication();
        $successor = self::runId();

        $this->snapshotArrives($successor, Shown::suspended($successor, 1, null), ['at' => time() + 1]);

        self::assertSame(['ended_unknown', 'open'], [$this->held($runId)['state'], $this->held($successor)['state']]);
    }

    public function test_a_completed_report_that_arrives_late_says_how_a_run_closed_as_unknown_ended(): void
    {
        $runId = $this->runOfTheApplication();
        $this->snapshotArrives($runId, null, ['at' => time() + 1]);

        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2]);

        self::assertSame('completed', $this->held($runId)['state']);
    }

    public function test_a_snapshot_that_shows_no_run_closes_nothing_of_a_run_no_snapshot_ever_named(): void
    {
        $runId = $this->startByThePlatform();

        $this->snapshotArrives(self::runId(), null, ['at' => time() + 1]);

        self::assertSame('open', $this->held($runId)['state']);
    }

    public function test_a_snapshot_older_than_the_one_that_named_a_run_does_not_close_it(): void
    {
        $runId = $this->runOfTheApplication();

        $this->snapshotArrives($runId, null, ['at' => time() - 5]);

        self::assertSame('open', $this->held($runId)['state']);
    }

    public function test_a_snapshot_that_shows_no_run_closes_a_run_an_earlier_snapshot_named_also_when_that_one_arrives_after_it(): void
    {
        $now = time();
        $runId = self::runId();
        $this->snapshotArrives($runId, null, ['at' => $now]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null), ['at' => $now - 5]);

        self::assertSame('ended_unknown', $this->held($runId)['state'], 'Earlier means a smaller "at", whatever the order of arrival.');
    }

    public function test_a_snapshot_of_the_very_second_that_named_a_run_does_not_close_it(): void
    {
        $now = time();
        $runId = self::runId();
        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null), ['at' => $now]);

        $this->snapshotArrives($runId, null, ['at' => $now]);

        self::assertSame('open', $this->held($runId)['state'], 'Which of the two was read first cannot be told.');
    }

    public function test_no_wake_is_planned_from_a_snapshot_the_platform_cannot_place_and_the_store_is_asked_a_second_later(): void
    {
        $held = $this->held($this->twoSnapshotsOfOneSecondDisagreeOnARunThatSleeps());

        self::assertSame(['same_second'], array_column($held['timers'], 'reason'), 'Not even the wake that is due.');
        self::assertEqualsWithDelta($held['readAt'] + 1, $held['timers'][0]['at'], 0.001, 'One second after the second snapshot arrived.');
    }

    public function test_the_store_is_asked_again_once_the_second_two_snapshots_share_has_passed(): void
    {
        $this->twoSnapshotsOfOneSecondDisagreeOnARunThatSleeps();

        $this->timePasses(1);

        self::assertSame(['inspect same_second: 200'], $this->tick());
    }

    public function test_the_run_is_closed_when_the_answer_after_that_second_shows_no_run(): void
    {
        $runId = $this->twoSnapshotsOfOneSecondDisagreeOnARunThatSleeps();
        $this->timePasses(1);
        $this->endpoint->showNext(null, at: time() + 1);

        $this->tick();

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_the_run_is_woken_as_planned_when_the_answer_after_that_second_shows_it(): void
    {
        $runId = $this->twoSnapshotsOfOneSecondDisagreeOnARunThatSleeps();
        $this->timePasses(1);
        $this->endpoint->showNext(Shown::suspended($runId, 1, time() - 1), at: time() + 1);

        $this->tick();

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_a_snapshot_that_names_it_later_opens_again_a_run_that_was_closed_as_unknown(): void
    {
        $runId = $this->runOfTheApplication();
        $this->snapshotArrives($runId, null, ['at' => time() + 1]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 2, null, 2), ['at' => time() + 2]);

        self::assertSame(['open', 2], [$this->held($runId)['state'], $this->held($runId)['run']['attempt']]);
    }

    public function test_a_snapshot_that_names_it_later_opens_again_a_start_that_was_closed_as_lost(): void
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->showNext(null, false);
        $this->tick();
        $closedAs = $this->held($runId)['state'];

        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null), ['at' => time() + 1]);

        self::assertSame(['lost', 'open'], [$closedAs, $this->held($runId)['state']]);
    }

    public function test_a_snapshot_that_names_it_later_opens_again_a_start_that_was_spent(): void
    {
        $runId = $this->startByThePlatform();
        $this->reportOfThePlatformStart($runId, 'refused', ['code' => 'run_in_flight', 'claimed' => true]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null), ['at' => time() + 1]);

        self::assertSame(['open', null], [$this->held($runId)['state'], $this->held($runId)['code']]);
    }

    public function test_a_snapshot_that_names_it_later_does_not_open_again_a_run_that_completed(): void
    {
        $runId = $this->runOfTheApplication();
        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2]);

        $this->snapshotArrives($runId, Shown::suspended($runId, 2, null, 2), ['at' => time() + 5]);

        self::assertSame('completed', $this->held($runId)['state']);
    }

    public function test_a_snapshot_that_names_it_later_does_not_open_again_a_run_that_was_abandoned(): void
    {
        $runId = $this->runOfTheApplication();
        $this->platform->abandon($runId);
        $this->tick();

        $this->snapshotArrives($runId, Shown::suspended($runId, 2, null, 2), ['at' => time() + 5]);

        self::assertSame('abandoned', $this->held($runId)['state']);
    }

    public function test_a_snapshot_of_the_very_second_that_closed_a_run_does_not_open_it_again(): void
    {
        $runId = $this->aSnapshotOfTheSecondThatClosedARunNamesIt();

        self::assertSame('ended_unknown', $this->held($runId)['state'], 'Whether the run was shown before or after it was closed cannot be told.');
    }

    public function test_the_store_is_asked_once_the_second_that_closed_a_run_and_named_it_has_passed(): void
    {
        $this->aSnapshotOfTheSecondThatClosedARunNamesIt();

        $this->timePasses(1);

        self::assertSame(['inspect same_second: 200'], $this->tick());
    }

    public function test_the_closed_run_is_open_again_when_the_answer_after_that_second_shows_it(): void
    {
        $runId = $this->aSnapshotOfTheSecondThatClosedARunNamesIt();
        $this->timePasses(1);
        $this->endpoint->showNext(Shown::suspended($runId, 2, null, 2), at: time() + 1);

        $this->tick();

        self::assertSame(['open', 2], [$this->held($runId)['state'], $this->held($runId)['run']['attempt']]);
    }

    public function test_the_closed_run_stays_closed_and_is_not_asked_about_again_when_the_answer_after_that_second_shows_no_run(): void
    {
        $runId = $this->aSnapshotOfTheSecondThatClosedARunNamesIt();
        $this->timePasses(1);
        $this->endpoint->showNext(null, at: time() + 1);

        $this->tick();

        self::assertSame(['ended_unknown', []], [$this->held($runId)['state'], $this->held($runId)['timers']]);
    }

    // ----------------------------------------------------------------
    // Rule 7: a run the application started, known from its announcement alone
    // ----------------------------------------------------------------

    public function test_a_run_known_from_its_announcement_alone_is_asked_about_once_its_lease_has_passed(): void
    {
        $runId = self::runId();
        $this->application->announce($runId, self::WORKFLOW_ID, ['leaseSeconds' => 60]);

        $this->timePasses(60);

        self::assertSame(['inspect segment_silence: 200'], $this->tick());
        self::assertSame($runId, $this->sent(WireLog::INSPECT)[0]['runId']);
    }

    public function test_a_run_known_from_its_announcement_alone_is_not_asked_about_before_its_lease_has_passed(): void
    {
        $this->application->announce(self::runId(), self::WORKFLOW_ID, ['leaseSeconds' => 60]);

        self::assertSame([], $this->tick());
    }

    public function test_it_is_closed_as_ended_with_no_known_outcome_when_the_answer_shows_no_run(): void
    {
        $runId = $this->announcedAndSilentSince(time() - 1);
        $this->endpoint->showNext(null, false);

        $this->tick();

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_it_stays_open_and_is_planned_from_the_answer_when_the_answer_shows_it(): void
    {
        $runId = $this->announcedAndSilentSince(time() - 1);
        $this->endpoint->showNext(Shown::suspended($runId, 1, null));

        $this->tick();

        self::assertSame(['open', 'suspended'], [$this->held($runId)['state'], $this->held($runId)['run']['status']]);
    }

    public function test_it_is_asked_about_although_a_report_said_its_start_was_aborted(): void
    {
        $runId = self::runId();
        $segment = ['invocationId' => 'inv_start_of_the_shop', 'at' => time() - 1];
        $this->application->announce($runId, self::WORKFLOW_ID, $segment + ['leaseSeconds' => 1]);
        $this->application->report($runId, self::WORKFLOW_ID, 'aborted', null, $segment + ['code' => 'not_executed', 'errorClass' => 'RuntimeException']);

        self::assertSame(['inspect never_seen: 200'], $this->tick());
        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_a_start_of_the_application_known_from_a_report_alone_is_not_asked_about_before_the_start_silence_has_passed(): void
    {
        $this->application->report(self::runId(), self::WORKFLOW_ID, 'rejected', null, ['code' => 'expired']);

        self::assertSame([], $this->tick());
    }

    public function test_a_start_of_the_application_known_from_a_report_alone_is_asked_about_once_the_start_silence_has_passed(): void
    {
        $runId = self::runId();
        $this->application->report($runId, self::WORKFLOW_ID, 'rejected', null, ['code' => 'expired']);

        $this->timePasses(self::START_SILENCE);

        self::assertSame(['inspect never_seen: 200'], $this->tick());
        self::assertSame($runId, $this->sent(WireLog::INSPECT)[0]['runId']);
    }

    public function test_a_start_of_the_application_that_expired_in_its_queue_is_closed_as_lost_when_no_copy_claimed_it(): void
    {
        $runId = $this->startOfTheApplicationRejectedAsExpired();
        $this->endpoint->showNext(null, false);

        $this->tick();

        self::assertSame('lost', $this->held($runId)['state']);
        self::assertSame([], $this->tick(), 'It is asked about once.');
    }

    public function test_a_start_of_the_application_that_expired_in_its_queue_ends_with_no_known_outcome_when_a_copy_claimed_it(): void
    {
        $runId = $this->startOfTheApplicationRejectedAsExpired();
        $this->endpoint->showNext(null, true);

        $this->tick();

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_an_announcement_with_a_later_at_opens_it_again(): void
    {
        $runId = $this->announcedAndSilentSince(time() - 1);
        $this->tick();

        $this->application->announce($runId, self::WORKFLOW_ID, ['at' => time() + 1]);

        self::assertSame('open', $this->held($runId)['state']);
    }

    public function test_an_announcement_that_is_not_later_leaves_it_closed(): void
    {
        $runId = $this->announcedAndSilentSince(time() - 1);
        $this->tick();

        $this->application->announce($runId, self::WORKFLOW_ID, ['at' => time() - 1]);

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_an_announcement_never_reopens_a_run_closed_as_completed(): void
    {
        $runId = $this->runOfTheApplication();
        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2]);

        $this->application->announce($runId, self::WORKFLOW_ID, ['verb' => 'wake', 'attempt' => 2, 'at' => time() + 5]);

        self::assertSame('completed', $this->held($runId)['state']);
    }

    public function test_an_announcement_does_not_reopen_a_start_of_the_platform_that_was_closed_as_unknown(): void
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->showNext(null, true);
        $this->tick();

        // The queue delivers the start once more: the copy posts its announcement before it is rejected.
        $this->application->announce($runId, self::WORKFLOW_ID, [
            'invocationId' => $this->sent(WireLog::START)[0]['invocationId'],
            'origin' => 'platform',
            'at' => time() + 5,
        ]);

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    // ----------------------------------------------------------------
    // Rule 7: the reports of a start that close nothing, or spend it
    // ----------------------------------------------------------------

    public function test_a_start_rejected_as_already_claimed_closes_nothing_and_is_not_sent_again(): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'rejected', ['code' => 'already_claimed', 'claimed' => true]);

        self::assertSame('open', $this->held($runId)['state']);
        self::assertSame([], $this->tick());
    }

    public function test_the_snapshot_of_a_start_rejected_as_already_claimed_is_read_like_any_other(): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'rejected', ['code' => 'already_claimed', 'claimed' => true, 'run' => Shown::running($runId, 1, self::LEASE)]);

        self::assertSame('running', $this->held($runId)['run']['status']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function reportsThatSpendAStart(): array
    {
        return [
            'refused' => ['refused', ['code' => 'run_in_flight', 'claimed' => true]],
            'rejected as an invalid start' => ['rejected', ['code' => 'invalid_start']],
            'aborted after its claim' => ['aborted', ['code' => 'not_executed', 'errorClass' => 'RuntimeException', 'claimed' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('reportsThatSpendAStart')]
    public function test_a_start_that_will_never_run_under_its_run_id_is_spent_and_its_code_is_shown(string $outcome, array $fields): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, $outcome, $fields);

        self::assertSame(['spent', $fields['code']], [$this->held($runId)['state'], $this->held($runId)['code']]);
        self::assertSame([], $this->tick(), 'It is never sent again.');
    }

    public function test_a_refused_start_of_the_application_is_spent_and_its_code_is_shown_to_nobody(): void
    {
        $runId = self::runId();
        $holder = self::runId();

        $this->application->report($runId, self::WORKFLOW_ID, 'refused', Shown::suspended($holder, 1, null), ['code' => 'run_in_flight', 'claimed' => false]);

        self::assertSame(['spent', null], [$this->held($runId)['state'], $this->held($runId)['code']], 'A start the application issued has no operator.');
    }

    public function test_a_start_whose_report_names_its_own_run_is_not_spent(): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'refused', ['code' => 'conflict', 'claimed' => true, 'run' => Shown::running($runId, 1, self::LEASE)]);

        self::assertSame(['open', 'running'], [$this->held($runId)['state'], $this->held($runId)['run']['status']]);
    }

    public function test_a_start_rejected_as_expired_stays_open_until_it_is_asked_about(): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'rejected', ['code' => 'expired']);

        self::assertSame('open', $this->held($runId)['state'], 'It says only that this copy did not run.');
        self::assertSame(['start_silence'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_the_claim_of_an_aborted_start_is_not_believed_when_its_snapshot_was_not_read(): void
    {
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'aborted', ['code' => 'not_executed', 'errorClass' => 'RuntimeException', 'claimed' => true, 'snapshotRead' => false]);

        self::assertSame('open', $this->held($runId)['state']);
    }

    // ----------------------------------------------------------------
    // Rule 7: the answers to an abandon
    // ----------------------------------------------------------------

    public function test_a_200_to_an_abandon_closes_a_run_as_abandoned(): void
    {
        $runId = $this->runOfTheApplication();

        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame(['abandoned', []], [$this->held($runId)['state'], $this->held($runId)['timers']]);
    }

    public function test_a_200_to_an_abandon_closes_as_abandoned_a_start_no_snapshot_ever_named(): void
    {
        $runId = $this->startByThePlatform();

        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame('abandoned', $this->held($runId)['state']);
    }

    public function test_a_409_that_shows_the_run_at_another_attempt_is_followed_by_an_abandon_with_the_attempt_shown(): void
    {
        $runId = $this->runOfTheApplication();
        $this->abandonIsAnswered409('stale_attempt', Shown::suspended($runId, 5, null, 3));

        $this->platform->abandon($runId);
        $this->tick();
        $this->tick();

        self::assertSame([1, 5], array_column($this->sent(WireLog::ABANDON), 'attempt'));
        self::assertSame('abandoned', $this->held($runId)['state']);
    }

    public function test_an_abandon_that_keeps_meeting_another_attempt_is_given_up_after_five_tries(): void
    {
        $runId = $this->runOfTheApplication();
        foreach ([2, 3, 4, 5, 6] as $attempt) {
            $this->abandonIsAnswered409('stale_attempt', Shown::suspended($runId, $attempt, null, $attempt));
        }

        $this->platform->abandon($runId);
        $sent = [$this->tick(), $this->tick(), $this->tick(), $this->tick(), $this->tick(), $this->tick()];

        self::assertSame([...array_fill(0, 5, ['abandon operator: 409']), []], $sent);
        self::assertSame(['open', 'refused'], [$this->held($runId)['state'], $this->held($runId)['abandon']]);
    }

    public function test_a_409_from_the_guard_of_an_agent_leaves_the_run_open_and_is_not_followed_by_another_abandon(): void
    {
        $runId = $this->runOfTheApplication();
        $this->abandonIsAnswered409('abandon_refused', Shown::suspended($runId, 1, null));

        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame(['open', 'refused'], [$this->held($runId)['state'], $this->held($runId)['abandon']]);
        self::assertSame([], $this->tick());
    }

    public function test_a_409_that_shows_no_run_for_a_start_never_heard_of_is_followed_by_an_inspect_at_the_next_tick(): void
    {
        $runId = $this->startByThePlatform();
        $this->abandonIsAnswered409('stale_run', null);

        $this->platform->abandon($runId);

        self::assertSame(['abandon operator: 409'], $this->tick());
        self::assertSame(['inspect abandon_left_open: 200'], $this->tick(), 'At once, as appendix A says, and not at the use-by time plus the start silence.');
    }

    public function test_the_abandoned_start_is_closed_as_ended_with_no_known_outcome_when_that_inspect_shows_no_run(): void
    {
        $runId = $this->anAbandonedStartWasClosedBecauseNoRunWasShown();

        self::assertSame('ended_unknown', $this->held($runId)['state']);
    }

    public function test_a_run_that_appears_after_its_abandon_was_closed_is_open_again_and_the_abandon_is_forgotten(): void
    {
        $runId = $this->anAbandonedStartWasClosedBecauseNoRunWasShown();

        $this->reportOfThePlatformStart($runId, 'suspended', ['executedAttempt' => 1, 'claimed' => true, 'run' => Shown::suspended($runId, 1, null), 'at' => time() + 1]);

        self::assertSame(['open', null], [$this->held($runId)['state'], $this->held($runId)['abandon']]);
        self::assertSame([], $this->tick(), 'The operator has to ask again.');
    }

    public function test_the_abandoned_start_is_abandoned_again_with_the_attempt_shown_when_that_inspect_shows_its_run(): void
    {
        $runId = $this->startByThePlatform();
        $this->abandonIsAnswered409('stale_run', null);
        $this->endpoint->showNext(Shown::running($runId, 1, self::LEASE), true);

        $this->platform->abandon($runId);
        $this->tick();
        $this->tick();

        self::assertSame(['abandon operator: 200'], $this->tick());
        self::assertSame(1, $this->sent(WireLog::ABANDON)[1]['attempt']);
    }

    public function test_a_409_is_read_like_any_other_snapshot(): void
    {
        $runId = $this->runOfTheApplication();
        $this->abandonIsAnswered409('stale_run', null, time() + 1);

        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame('ended_unknown', $this->held($runId)['state'], 'The run an earlier snapshot named has left its workflow ID.');
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
        $refusedWake = ['verb' => 'wake', 'code' => 'stale_attempt', 'attempt' => 1];

        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, 'refused', $run, $fields + $refusedWake));
    }

    /**
     * A report could not read the store, and neither could the inspect that followed it up.
     */
    protected function anInspectCouldNotReadTheStoreEither(): void
    {
        $runId = $this->runOfTheApplication();
        $this->snapshotArrives($runId, null, ['snapshotRead' => false]);
        $this->endpoint->showNext(null, null, snapshotRead: false);
        $this->timePasses(self::FOLLOW_UP);

        self::assertSame(['inspect unread_snapshot: 200'], $this->tick());
    }

    /**
     * The question that is due now is answered with a snapshot that was not read: it is not repeated at the
     * next tick, whatever caused it, and is asked again when the follow-up delay has passed.
     *
     * @param string $question why the platform asks
     */
    protected function assertAnUnreadAnswerIsFollowedUp(string $question): void
    {
        $this->endpoint->showNext(null, null, snapshotRead: false);

        self::assertSame([["inspect {$question}: 200"], []], [$this->tick(), $this->tick()]);
        $this->timePasses(self::FOLLOW_UP);
        self::assertSame(['inspect unread_snapshot: 200'], $this->tick());
    }

    /**
     * Two snapshots read within one second: the first names a run that sleeps on a timer that is due, the second
     * shows no run.
     */
    protected function twoSnapshotsOfOneSecondDisagreeOnARunThatSleeps(): string
    {
        $now = time();
        $runId = self::runId();
        $this->snapshotArrives($runId, Shown::suspended($runId, 1, $now - 1), ['at' => $now]);
        $this->snapshotArrives($runId, null, ['at' => $now]);

        return $runId;
    }

    /**
     * A snapshot that shows no run closes a run, and a snapshot read in that very second names it.
     */
    protected function aSnapshotOfTheSecondThatClosedARunNamesIt(): string
    {
        $now = time();
        $runId = self::runId();
        $this->snapshotArrives($runId, Shown::suspended($runId, 1, null), ['at' => $now - 1]);
        $this->snapshotArrives($runId, null, ['at' => $now]);
        $this->snapshotArrives($runId, Shown::suspended($runId, 2, null, 2), ['at' => $now]);

        return $runId;
    }

    /**
     * The application announces the start of a run of its own and says nothing more.
     *
     * @param int $leaseEnds when the lease of one second it announced runs out
     */
    protected function announcedAndSilentSince(int $leaseEnds): string
    {
        $runId = self::runId();
        self::assertSame(200, $this->application->announce($runId, self::WORKFLOW_ID, ['leaseSeconds' => 1, 'at' => $leaseEnds - 1]));

        return $runId;
    }

    /**
     * A start the application dispatched waited in its queue past its use-by time: a worker rejects it before
     * it announces anything, and the start silence of the platform has passed since.
     */
    protected function startOfTheApplicationRejectedAsExpired(): string
    {
        $runId = self::runId();
        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, 'rejected', null, ['code' => 'expired']));
        $this->timePasses(self::START_SILENCE);

        return $runId;
    }

    /**
     * An operator abandons a start nothing was heard of. The endpoint finds its run ID in the ledger and no
     * run, the platform asks, and the answer shows no run either.
     */
    protected function anAbandonedStartWasClosedBecauseNoRunWasShown(): string
    {
        $runId = $this->startByThePlatform();
        $this->abandonIsAnswered409('stale_run', null);
        $this->endpoint->showNext(null, true);

        $this->platform->abandon($runId);
        $this->tick();
        $this->tick();

        return $runId;
    }

    /**
     * A worker reports the start the platform sent.
     *
     * @param array<string, mixed> $fields the code, and what else the report says; "run" is the run it shows
     */
    protected function reportOfThePlatformStart(string $runId, string $outcome, array $fields): void
    {
        $start = $this->sent(WireLog::START)[0];
        $run = $fields['run'] ?? null;
        unset($fields['run']);

        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, $outcome, $run, $fields + [
            'invocationId' => $start['invocationId'],
            'origin' => 'platform',
        ]));
    }

    /**
     * @param array<string, mixed>|null $run the run the answer attaches
     */
    protected function abandonIsAnswered409(string $code, ?array $run, ?int $at = null): void
    {
        $this->endpoint->answerNext('abandon', 409, ['code' => $code, 'run' => $run, 'claimed' => true, 'snapshotRead' => true, 'at' => $at ?? time()]);
    }
}
