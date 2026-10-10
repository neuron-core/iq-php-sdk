<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\Simulator;

use NeuronCore\Cloud\Tests\Support\EndToEnd\Shown;
use NeuronCore\Cloud\Tests\Support\EndToEnd\SimulatorTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_intersect_key;
use function array_map;
use function count;
use function range;
use function time;

/**
 * Rules 13 and 17 of appendix B of the design, and the last column of the
 * status table of appendix A: what the platform simulator does with each
 * answer of the endpoint, what it sends again, and how it avoids flooding.
 */
class DeliveryRulesTest extends SimulatorTestCase
{
    // ----------------------------------------------------------------
    // The status table of appendix A
    // ----------------------------------------------------------------

    public function test_a_202_makes_the_platform_assume_nothing_of_the_run(): void
    {
        $runId = $this->startByThePlatform();

        self::assertSame(['open', null], [$this->held($runId)['state'], $this->held($runId)['run']]);
    }

    public function test_a_503_is_followed_by_the_same_message_once_its_retry_after_has_passed(): void
    {
        $this->endpoint->answerNext('start', 503, ['code' => 'dispatch_failed'], ['Retry-After' => '5']);
        $this->startByThePlatform();

        self::assertSame([], $this->tick());
        $this->timePasses(5);

        self::assertSame(['start resend: 202'], $this->tick());
        [$first, $again] = $this->sent(WireLog::START);
        self::assertSame($first, $again);
    }

    public function test_a_500_is_followed_by_the_same_message_after_a_back_off(): void
    {
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        $this->startByThePlatform();

        self::assertSame([], $this->tick());
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['start resend: 202'], $this->tick());
    }

    public function test_the_back_off_doubles_with_every_answer_of_no_use(): void
    {
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        $this->startByThePlatform();
        $this->timePasses(self::RESEND_DELAY);
        $this->tick();

        $this->timePasses(self::RESEND_DELAY);
        self::assertSame([], $this->tick(), 'Not after the first delay once more.');
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['start resend: 202'], $this->tick());
    }

    public function test_the_delivery_values_are_those_of_the_durations_table_of_appendix_b(): void
    {
        $settings = $this->platform->configure([]);

        self::assertSame(
            ['resendDelay' => 5, 'resendDelayCap' => 300, 'uncertainResends' => 5, 'pingRetry' => 60],
            array_intersect_key($settings, ['resendDelay' => 0, 'resendDelayCap' => 0, 'uncertainResends' => 0, 'pingRetry' => 0]),
        );
    }

    public function test_a_message_that_keeps_getting_a_500_is_sent_a_bounded_number_of_times_and_then_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0, 'uncertainResends' => 2]);
        foreach ([1, 2, 3, 4] as $ignored) {
            $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        }
        $runId = $this->startByThePlatform();

        self::assertSame([['start resend: 500'], ['start resend: 500'], []], [$this->tick(), $this->tick(), $this->tick()]);
        self::assertSame([['unanswered', $runId]], $this->alerts());
    }

    public function test_a_message_that_got_a_500_is_sent_again_although_its_announcement_shows_that_it_was_queued(): void
    {
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        $runId = $this->startByThePlatform();
        $this->application->announce($runId, self::WORKFLOW_ID, ['invocationId' => $this->sent(WireLog::START)[0]['invocationId'], 'origin' => 'platform']);

        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['start resend: 202'], $this->tick(), 'Rule 13 allows the platform not to: the ledger refuses the copy.');
    }

    // ----------------------------------------------------------------
    // Rule 13: after the last bounded re-send, accepted and silent
    // ----------------------------------------------------------------

    public function test_an_order_whose_re_sends_ran_out_is_not_ended_and_counts_as_accepted(): void
    {
        $runId = $this->aStartRanOutOfReSends();

        [$start] = $this->held($runId)['orders'];
        self::assertSame(['open', null, 500], [$this->held($runId)['state'], $start['ended'], $start['status']]);
        self::assertNotNull($start['acceptedAt']);
        self::assertSame([], $this->tick(), 'It is not sent again by itself.');
    }

    public function test_a_wake_whose_re_sends_ran_out_is_asked_about_after_the_wake_silence_and_sent_again_when_the_attempt_is_unchanged(): void
    {
        $this->platform->configure(['uncertainResends' => 1]);
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $runId = $this->aWakeIsDueThatTheEndpointAnswersWith($run, [500, 500]);
        $this->tick();
        $this->timePasses(self::RESEND_DELAY);
        self::assertSame([['wake timer:1: 500'], []], [$this->tick(), $this->tick()], 'The one re-send, and nothing after it.');

        // The endpoint answers again. The timer came due while it did not.
        $this->endpoint->showNext($run);
        $this->timePasses(self::WAKE_SILENCE);

        self::assertSame([['inspect wake_silence: 200'], ['wake timer:1: 202'], []], [$this->tick(), $this->tick(), $this->tick()]);
        [$first, $second, $third] = $this->platform->wire()->of(WireLog::WAKE);
        self::assertSame([500, 500, 202], [$first['status'], $second['status'], $third['status']], 'The timer fired once.');
        self::assertSame([$first['fields'], $first['fields']], [$second['fields'], $third['fields']], 'The same wake, with the same fence.');
        self::assertSame([['unanswered', $runId]], $this->alerts());
    }

    public function test_a_wake_whose_re_sends_ran_out_is_not_asked_about_before_the_wake_silence_has_passed(): void
    {
        $this->platform->configure(['uncertainResends' => 0]);
        $runId = $this->aWakeIsDueThatTheEndpointAnswersWith(Shown::suspended(self::runId(), 2, time() - 1), [500]);
        $this->tick();

        $this->timePasses(self::WAKE_SILENCE - 60);

        self::assertSame([], $this->tick());
        self::assertSame(['wake_silence'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_a_timer_whose_wake_ran_out_of_re_sends_is_still_planned_days_later_and_fires_once_the_endpoint_answers(): void
    {
        $this->platform->configure(['uncertainResends' => 0]);
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $runId = $this->aWakeIsDueThatTheEndpointAnswersWith($run, [500]);
        $this->tick();

        foreach (['the first day', 'the second day', 'the third day'] as $day) {
            $this->endpoint->answerNext('inspect', 500, ['code' => 'internal_error']);
            $this->timePasses(self::PERIODIC_READ);
            self::assertSame(['inspect wake_silence: 500'], $this->tick(), "On {$day} the endpoint still answers nothing of use.");
        }
        self::assertSame(['open', ['wake_silence']], [$this->held($runId)['state'], array_column($this->held($runId)['timers'], 'reason')]);

        $this->endpoint->showNext($run);
        $this->timePasses(self::RESEND_DELAY_CAP);

        self::assertSame([['inspect wake_silence: 200'], ['wake timer:1: 202'], []], [$this->tick(), $this->tick(), $this->tick()]);
        self::assertSame([['unanswered', $runId], ['unanswered', $runId]], $this->alerts(), 'One for the wake and one for the inspect.');
    }

    public function test_a_wake_whose_re_sends_ran_out_is_not_sent_again_sooner_than_the_back_off_allows(): void
    {
        $this->platform->configure(['uncertainResends' => 0, 'wakeSilence' => 0]);
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $this->aWakeIsDueThatTheEndpointAnswersWith($run, [500]);
        $this->tick();
        $this->endpoint->showNext($run);

        self::assertSame([['inspect wake_silence: 200'], []], [$this->tick(), $this->tick()], 'The silence was shrunk to nothing: the back-off still holds the wake.');
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_a_start_whose_re_sends_ran_out_is_asked_about_after_its_use_by_time_like_any_silent_start(): void
    {
        $runId = $this->aStartRanOutOfReSends();
        self::assertSame(['start_silence'], array_column($this->held($runId)['timers'], 'reason'));
        $this->timePasses(self::WAKE_SILENCE);
        self::assertSame([], $this->tick(), 'The wake silence ends after its use-by time: no copy could be sent any more.');

        $this->timePasses(self::START_SILENCE);

        self::assertSame(['inspect start_silence: 200'], $this->tick());
        self::assertSame('lost', $this->held($runId)['state']);
        self::assertCount(1, $this->sent(WireLog::START));
    }

    public function test_a_start_whose_re_sends_ran_out_is_asked_about_after_the_wake_silence_while_its_use_by_time_allows_and_sent_again_when_unclaimed(): void
    {
        $this->platform->configure(['wakeSilence' => 60]);
        $runId = $this->aStartRanOutOfReSends();
        $this->timePasses(59);
        self::assertSame([], $this->tick());
        $this->timePasses(1);

        self::assertSame([['inspect unanswered_start: 200'], []], [$this->tick(), $this->tick()], 'No copy claimed it. It is sent again after a back-off.');
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame([['start resend: 202'], []], [$this->tick(), $this->tick()]);
        [$first, $again] = $this->platform->wire()->of(WireLog::START);
        self::assertSame([500, 202, $first['fields']], [$first['status'], $again['status'], $again['fields']], 'The same start, with its run ID and its use-by time.');
        self::assertSame($runId, $this->sent(WireLog::INSPECT)[0]['runId']);
        self::assertSame([['unanswered', $runId]], $this->alerts());
    }

    public function test_a_start_whose_re_sends_ran_out_is_not_sent_again_when_the_answer_shows_that_a_copy_claimed_it(): void
    {
        $this->platform->configure(['wakeSilence' => 60]);
        $runId = $this->aStartRanOutOfReSends();
        $this->endpoint->showNext(null, true);
        $this->timePasses(60);
        $this->tick();

        $this->timePasses(self::RESEND_DELAY);

        self::assertSame([], $this->tick());
        self::assertSame(['start_silence'], array_column($this->held($runId)['timers'], 'reason'), 'Only the question of rule 12 is left.');
    }

    public function test_a_start_whose_re_sends_ran_out_is_not_asked_about_after_the_wake_silence_once_a_snapshot_named_its_run(): void
    {
        $this->platform->configure(['wakeSilence' => 60]);
        $runId = $this->aStartRanOutOfReSends();
        // A copy had reached the queue all the same: a worker ran it to its first wait.
        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), [
            'invocationId' => $this->sent(WireLog::START)[0]['invocationId'],
            'origin' => 'platform',
            'executedAttempt' => 1,
            'claimed' => true,
        ]);

        $this->timePasses(60);

        self::assertSame([], $this->tick());
        self::assertSame(['periodic_read'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_a_start_the_endpoint_answered_202_is_not_asked_about_after_the_wake_silence(): void
    {
        $this->platform->configure(['wakeSilence' => 60]);
        $runId = $this->startByThePlatform();

        $this->timePasses(60);

        self::assertSame([], $this->tick());
        self::assertSame(['start_silence'], array_column($this->held($runId)['timers'], 'reason'));
    }

    public function test_a_redirect_is_not_followed_and_the_message_is_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->endpoint->answerNext('start', 302, [], ['Location' => $this->endpoint->url() . '/elsewhere']);
        $this->startByThePlatform();

        self::assertSame(['start resend: 202'], $this->tick());
        self::assertSame([302, 202], array_column($this->platform->wire()->of(WireLog::START), 'status'));
    }

    public function test_an_endpoint_that_takes_longer_than_the_platform_allows_is_sent_the_message_again(): void
    {
        $this->platform->configure(['resendDelay' => 0, 'endpointTimeout' => 0.2]);
        $this->endpoint->answerNext('start', 202, [], [], delay: 0.4);
        $this->startByThePlatform();
        // The endpoint serves one request at a time and may still be busy with the answer that came too late:
        // the copy is given all the time it needs.
        $this->platform->configure(['endpointTimeout' => 10]);

        self::assertSame(['start resend: 202'], $this->tick());
        self::assertSame([0, 202], array_column($this->platform->wire()->of(WireLog::START), 'status'));
    }

    public function test_a_404_is_followed_by_the_same_message_while_the_version_is_in_the_manifest(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->endpoint->answerNext('wake', 404, ['code' => 'unknown_version']);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));

        self::assertSame([['wake timer:1: 404'], ['wake timer:1: 202']], [$this->tick(), $this->tick()]);
    }

    public function test_a_404_is_not_followed_by_the_message_once_a_sync_removed_the_version(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->endpoint->answerNext('wake', 404, ['code' => 'unknown_version']);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->application->sync([2]);

        self::assertSame([], $this->tick());
    }

    public function test_a_400_is_never_followed_by_the_message_and_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->endpoint->answerNext('start', 400, ['code' => 'invalid_request']);
        $this->startByThePlatform();

        self::assertSame([], $this->tick());
        self::assertSame(['refused_by_endpoint'], array_column($this->platform->alerts(), 'kind'));
    }

    public function test_a_401_stops_every_delivery_keeps_the_timers_and_raises_an_alert(): void
    {
        $this->platform->configure(['pingRetry' => 30]);
        $this->endpoint->answerNext('wake', 401, ['code' => 'invalid_signature']);
        $first = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 2), workflowId: 'order:1');
        $second = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1), workflowId: 'order:2');

        self::assertSame([['wake timer:1: 401'], []], [$this->tick(), $this->tick()]);
        self::assertTrue($this->platform->manifest()['deliveriesStopped']);
        self::assertSame(['wake', 'wake'], [$this->held($first)['timers'][0]['action'], $this->held($second)['timers'][0]['action']]);
        self::assertSame(['invalid_signature'], array_column($this->platform->alerts(), 'kind'));
    }

    public function test_deliveries_resume_once_a_ping_of_the_platform_succeeds(): void
    {
        $this->platform->configure(['pingRetry' => 0]);
        $this->endpoint->answerNext('wake', 401, ['code' => 'invalid_signature']);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        self::assertSame(['wake timer:1: 202'], $this->tick());
        self::assertFalse($this->platform->manifest()['deliveriesStopped']);
    }

    public function test_deliveries_stay_stopped_while_the_ping_is_refused(): void
    {
        $this->platform->configure(['pingRetry' => 0]);
        $this->endpoint->answerNext('wake', 401, ['code' => 'invalid_signature']);
        $this->endpoint->answerNext('ping', 401, ['code' => 'invalid_signature']);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        self::assertSame([], $this->tick());
        self::assertTrue($this->platform->manifest()['deliveriesStopped']);
    }

    public function test_the_ping_of_a_sync_resumes_deliveries_too(): void
    {
        $this->platform->configure(['pingRetry' => 30]);
        $this->endpoint->answerNext('wake', 401, ['code' => 'invalid_signature']);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1));
        $this->tick();

        $this->application->sync();

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    // ----------------------------------------------------------------
    // The status table of appendix A, for an inspect and an abandon
    // ----------------------------------------------------------------

    public function test_a_503_to_an_inspect_puts_the_question_off_until_its_retry_after_has_passed(): void
    {
        $this->platform->configure(['periodicRead' => 0]);
        $this->runOfTheApplication();
        $this->endpoint->answerNext('inspect', 503, ['code' => 'unavailable'], ['Retry-After' => '5']);

        self::assertSame([['inspect periodic_read: 503'], []], [$this->tick(), $this->tick()]);
        $this->timePasses(5);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    public function test_a_500_to_an_inspect_is_followed_by_the_question_after_a_back_off(): void
    {
        $this->platform->configure(['periodicRead' => 0]);
        $this->runOfTheApplication();
        $this->endpoint->answerNext('inspect', 500, ['code' => 'internal_error']);

        self::assertSame([['inspect periodic_read: 500'], []], [$this->tick(), $this->tick()]);
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function answersThatSayNothing(): array
    {
        return ['a 500' => [500], 'a redirect' => [302]];
    }

    #[DataProvider('answersThatSayNothing')]
    public function test_an_inspect_that_keeps_getting_an_answer_that_says_nothing_is_asked_a_bounded_number_of_times_and_then_raises_an_alert(int $status): void
    {
        $this->platform->configure(['resendDelay' => 0, 'uncertainResends' => 2]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [$status, $status, $status, $status]);

        self::assertSame(
            [["inspect periodic_read: {$status}"], ["inspect periodic_read: {$status}"], ["inspect periodic_read: {$status}"], []],
            [$this->tick(), $this->tick(), $this->tick(), $this->tick()],
        );
        self::assertSame([['unanswered', $runId]], $this->alerts());
    }

    public function test_an_inspect_that_keeps_timing_out_is_asked_a_bounded_number_of_times_and_then_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0, 'uncertainResends' => 1, 'endpointTimeout' => 0.2]);
        $runId = $this->runOfTheApplication();
        $this->endpoint->showNext(null, delay: 0.4);
        $this->endpoint->showNext(null, delay: 0.4);
        $this->timePasses(self::PERIODIC_READ);

        self::assertSame([['inspect periodic_read: 0'], ['inspect periodic_read: 0'], []], [$this->tick(), $this->tick(), $this->tick()]);
        self::assertSame([['unanswered', $runId]], $this->alerts());
    }

    public function test_an_inspect_whose_re_sends_ran_out_is_asked_again_at_the_cap_of_the_back_off_until_an_answer_is_read(): void
    {
        $this->platform->configure(['uncertainResends' => 0]);
        $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [500, 500]);
        $this->tick();

        $this->timePasses(self::RESEND_DELAY_CAP - 1);
        self::assertSame([], $this->tick(), 'Not sooner than the cap of the back-off.');
        $this->timePasses(1);
        self::assertSame(['inspect periodic_read: 500'], $this->tick());
        $this->timePasses(self::RESEND_DELAY_CAP);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    public function test_a_run_whose_inspect_the_endpoint_refused_is_read_again_when_its_next_periodic_read_is_due(): void
    {
        $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [400]);

        self::assertSame([['inspect periodic_read: 400'], []], [$this->tick(), $this->tick()]);
        $this->timePasses(self::PERIODIC_READ);

        self::assertSame(['inspect periodic_read: 200'], $this->tick());
    }

    public function test_a_405_to_an_inspect_is_never_followed_by_the_question_and_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [405]);

        self::assertSame([['inspect periodic_read: 405'], []], [$this->tick(), $this->tick()]);
        self::assertSame([['refused_by_endpoint', $runId]], $this->alerts());
    }

    public function test_a_404_to_an_inspect_is_followed_by_the_question_while_the_version_is_in_the_manifest(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [404, 404, 404, 404, 404, 404, 404]);

        $asked = 0;
        foreach (range(1, 8) as $ignored) {
            $asked += count($this->tick());
        }

        self::assertSame(8, $asked, 'Seven times in vain, with no bound, and once with an answer.');
    }

    public function test_a_404_to_an_inspect_is_not_followed_by_the_question_once_a_sync_removed_the_version(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', [404]);
        $this->tick();

        $this->application->sync([2]);

        self::assertSame([], $this->tick());
        self::assertSame(['stranded_version'], array_column($this->held($runId)['timers'], 'held'));
    }

    public function test_an_inspect_of_a_run_of_a_stranded_version_is_sent_all_the_same(): void
    {
        $this->aQuestionIsDueThatTheEndpointAnswersWith('inspect', []);
        $this->application->sync([2]);

        self::assertSame(['inspect periodic_read: 200'], $this->tick(), 'An inspect invokes nothing.');
    }

    public function test_an_abandon_that_keeps_getting_a_500_is_sent_a_bounded_number_of_times_and_then_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0, 'uncertainResends' => 2]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('abandon', [500, 500, 500, 500]);

        self::assertSame(
            [['abandon operator: 500'], ['abandon operator: 500'], ['abandon operator: 500'], []],
            [$this->tick(), $this->tick(), $this->tick(), $this->tick()],
        );
        self::assertSame([['unanswered', $runId]], $this->alerts());
        self::assertSame(['open', 'requested'], [$this->held($runId)['state'], $this->held($runId)['abandon']], 'The abandon is not forgotten.');
    }

    public function test_an_abandon_whose_re_sends_ran_out_is_sent_again_at_the_cap_of_the_back_off_until_it_is_answered(): void
    {
        $this->platform->configure(['uncertainResends' => 0]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('abandon', [500, 500]);
        $this->tick();

        $this->timePasses(self::RESEND_DELAY_CAP - 1);
        self::assertSame([], $this->tick(), 'Not sooner than the cap of the back-off.');
        $this->timePasses(1);
        self::assertSame(['abandon operator: 500'], $this->tick());
        $this->timePasses(self::RESEND_DELAY_CAP);

        self::assertSame(['abandon operator: 200'], $this->tick());
        self::assertSame('abandoned', $this->held($runId)['state']);
    }

    public function test_a_500_to_an_abandon_is_followed_by_the_abandon_after_a_back_off(): void
    {
        $this->aQuestionIsDueThatTheEndpointAnswersWith('abandon', [500]);

        self::assertSame([['abandon operator: 500'], []], [$this->tick(), $this->tick()]);
        $this->timePasses(self::RESEND_DELAY);

        self::assertSame(['abandon operator: 200'], $this->tick());
    }

    public function test_a_405_to_an_abandon_is_never_followed_by_the_abandon_and_raises_an_alert(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->aQuestionIsDueThatTheEndpointAnswersWith('abandon', [405]);

        self::assertSame([['abandon operator: 405'], []], [$this->tick(), $this->tick()]);
        self::assertSame([['refused_by_endpoint', $runId]], $this->alerts());
    }

    public function test_a_404_to_an_abandon_is_not_followed_by_the_abandon_once_a_sync_removed_the_version(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->aQuestionIsDueThatTheEndpointAnswersWith('abandon', [404, 404]);
        self::assertSame([['abandon operator: 404'], ['abandon operator: 404']], [$this->tick(), $this->tick()]);

        $this->application->sync([2]);

        self::assertSame([], $this->tick());
    }

    public function test_an_answer_that_breaks_its_schema_is_no_answer_and_raises_an_alert(): void
    {
        $this->platform->configure(['periodicRead' => 0, 'resendDelay' => 30]);
        $runId = $this->runOfTheApplication();
        $held = $this->held($runId)['run'];
        $this->endpoint->answerNext('inspect', 200, ['run' => ['status' => 'exploded'], 'claimed' => null, 'snapshotRead' => true, 'at' => time()]);

        self::assertSame(['inspect periodic_read: 0'], $this->tick());
        self::assertSame($held, $this->held($runId)['run']);
        self::assertSame(['protocol_violation'], array_column($this->platform->alerts(), 'kind'));
    }

    // ----------------------------------------------------------------
    // Rule 13: re-send only what can be re-sent
    // ----------------------------------------------------------------

    public function test_a_start_reported_as_aborted_before_any_claim_is_sent_again_as_the_same_message(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'aborted', ['code' => 'unknown_version']);

        self::assertSame(['start resend: 202'], $this->tick());
        [$first, $again] = $this->sent(WireLog::START);
        self::assertSame($first, $again);
    }

    public function test_an_aborted_start_is_sent_again_only_after_a_back_off(): void
    {
        $this->platform->configure(['resendDelay' => 30]);
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'aborted', ['code' => 'unknown_version']);

        self::assertSame([], $this->tick());
        self::assertSame('resend', $this->held($runId)['timers'][0]['reason']);
    }

    public function test_a_start_an_observed_report_shows_unclaimed_and_without_a_run_is_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'observed', ['claimed' => false]);

        self::assertSame(['start resend: 202'], $this->tick());
    }

    public function test_a_start_an_observed_report_shows_claimed_is_not_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->startByThePlatform();

        $this->reportOfThePlatformStart($runId, 'observed', ['claimed' => true]);

        self::assertSame([], $this->tick());
    }

    public function test_a_start_an_inspect_shows_unclaimed_and_without_a_run_is_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->aStartOfThePlatformWasAnnouncedAndStayedSilent();
        $this->endpoint->showNext(null, false);

        self::assertSame([['inspect segment_silence: 200'], ['start resend: 202']], [$this->tick(), $this->tick()]);
    }

    public function test_a_start_an_inspect_shows_claimed_and_without_a_run_is_not_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $this->aStartOfThePlatformWasAnnouncedAndStayedSilent();
        $this->endpoint->showNext(null, true);

        self::assertSame([['inspect segment_silence: 200'], []], [$this->tick(), $this->tick()]);
        self::assertCount(1, $this->sent(WireLog::START), 'A copy claimed the start: after the claim nothing sends it again.');
    }

    public function test_a_start_is_never_sent_again_after_its_use_by_time(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = $this->startByThePlatform();
        $this->theClockReaches($this->sent(WireLog::START)[0]['expiresAt']);

        $this->reportOfThePlatformStart($runId, 'aborted', ['code' => 'unknown_version']);

        self::assertSame([], $this->tick());
        self::assertCount(1, $this->sent(WireLog::START));
    }

    public function test_a_wake_reported_as_aborted_is_sent_again(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $runId = $this->runOfTheApplication(run: $run);
        $this->tick();

        $this->application->report($runId, self::WORKFLOW_ID, 'aborted', $run, [
            'invocationId' => $this->sent(WireLog::WAKE)[0]['invocationId'],
            'verb' => 'wake',
            'origin' => 'platform',
            'code' => 'unknown_version',
        ]);

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_the_platform_never_sends_a_segment_the_application_issued(): void
    {
        $this->platform->configure(['resendDelay' => 0]);
        $runId = self::runId();
        $segment = ['invocationId' => 'inv_start_of_the_shop'];
        $this->application->announce($runId, self::WORKFLOW_ID, $segment);

        $this->application->report($runId, self::WORKFLOW_ID, 'aborted', null, $segment + ['code' => 'unknown_version']);

        self::assertSame([], $this->tick());
        self::assertSame([], $this->sent(WireLog::START));
    }

    // ----------------------------------------------------------------
    // Rule 17: do not flood
    // ----------------------------------------------------------------

    public function test_a_retry_after_holds_back_every_message_to_the_endpoint(): void
    {
        $this->endpoint->answerNext('wake', 503, ['code' => 'dispatch_failed'], ['Retry-After' => '1']);
        $first = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 2), workflowId: 'order:1');
        $second = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 2, time() - 1), workflowId: 'order:2');

        self::assertSame([['wake timer:1: 503'], []], [$this->tick(), $this->tick()]);
        self::assertSame(['wake', 'wake'], [$this->held($first)['timers'][0]['action'], $this->held($second)['timers'][0]['action']]);
    }

    public function test_timers_that_come_due_together_are_spread_over_several_ticks(): void
    {
        $this->platform->configure(['deliveriesPerTick' => 2]);
        foreach ([1, 2, 3, 4, 5] as $order) {
            $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() - 1), workflowId: "order:{$order}");
        }

        self::assertSame([2, 2, 1, 0], array_map(count(...), [$this->tick(), $this->tick(), $this->tick(), $this->tick()]));
    }

    public function test_what_has_been_due_the_longest_is_sent_first(): void
    {
        $this->platform->configure(['deliveriesPerTick' => 1]);
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() - 10), workflowId: 'order:later');
        $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, time() - 60), workflowId: 'order:sooner');

        $this->tick();

        self::assertSame(['order:sooner'], array_column($this->sent(WireLog::WAKE), 'workflowId'));
    }

    // ----------------------------------------------------------------
    // What the tests are written with
    // ----------------------------------------------------------------

    /**
     * A run of the application waits with no timer, and a question about it is due: the periodic read, or the
     * abandon an operator asked for.
     *
     * @param string $question inspect or abandon
     * @param int[] $statuses what the endpoint answers the next questions of that kind, in order
     * @return string the run ID
     */
    protected function aQuestionIsDueThatTheEndpointAnswersWith(string $question, array $statuses): string
    {
        $runId = $this->runOfTheApplication();

        foreach ($statuses as $status) {
            $this->endpoint->answerNext($question, $status, ['code' => $status === 404 ? 'unknown_definition' : 'internal_error']);
        }

        if ($question === 'abandon') {
            $this->platform->abandon($runId);
        } else {
            $this->timePasses(self::PERIODIC_READ);
        }

        return $runId;
    }

    /**
     * The timer of a run of the application is due, and the endpoint answers the wakes the platform sends for it.
     *
     * @param array<string, mixed> $run as Shown writes it: suspended on a timer that is due
     * @param int[] $statuses what the endpoint answers the next wakes, in order
     * @return string the run ID
     */
    protected function aWakeIsDueThatTheEndpointAnswersWith(array $run, array $statuses): string
    {
        foreach ($statuses as $status) {
            $this->endpoint->answerNext('wake', $status, ['code' => 'internal_error']);
        }

        return $this->runOfTheApplication(run: $run);
    }

    /**
     * The endpoint gave no usable answer to a start of the platform, which allows itself no re-send here: the
     * first copy is the last, and the alert is raised.
     *
     * @return string the run ID of the start
     */
    protected function aStartRanOutOfReSends(): string
    {
        $this->platform->configure(['uncertainResends' => 0]);
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);
        $runId = $this->startByThePlatform();

        self::assertSame([['unanswered', $runId]], $this->alerts());

        return $runId;
    }

    /**
     * A worker announced the start the platform sent, and the lease it announced ran out with no report.
     */
    protected function aStartOfThePlatformWasAnnouncedAndStayedSilent(): void
    {
        $runId = $this->startByThePlatform();

        self::assertSame(200, $this->application->announce($runId, self::WORKFLOW_ID, [
            'invocationId' => $this->sent(WireLog::START)[0]['invocationId'],
            'origin' => 'platform',
            'leaseSeconds' => 1,
            'at' => time() - 1,
        ]));
    }

    /**
     * A worker reports the start the platform sent, with a snapshot that shows no run.
     *
     * @param array<string, mixed> $fields the code and the claim the report carries
     */
    protected function reportOfThePlatformStart(string $runId, string $outcome, array $fields): void
    {
        self::assertSame(200, $this->application->report($runId, self::WORKFLOW_ID, $outcome, null, $fields + [
            'invocationId' => $this->sent(WireLog::START)[0]['invocationId'],
            'origin' => 'platform',
        ]));
    }
}
