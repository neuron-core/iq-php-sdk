<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\Simulator;

use NeuronCore\Cloud\Tests\Support\EndToEnd\PlayedApplication;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Shown;
use NeuronCore\Cloud\Tests\Support\EndToEnd\SimulatorTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_key_exists;
use function time;

/**
 * Rules 1 to 5 of appendix B of the design, the five that correctness
 * depends on: how the platform simulator writes its own orders.
 */
class CorrectnessRulesTest extends SimulatorTestCase
{
    /** A second definition of the application, which a test publishes beside the one every test begins with. */
    protected const RETURNS = 'order-returns';

    // ----------------------------------------------------------------
    // Rule 1: never use a run ID twice; a start keeps one use-by time
    // ----------------------------------------------------------------

    public function test_two_firings_for_one_workflow_id_get_two_run_ids(): void
    {
        $first = $this->startByThePlatform();
        $second = $this->startByThePlatform();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^1-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/', $first);
    }

    public function test_a_start_carries_a_use_by_time_its_lifetime_ahead(): void
    {
        $this->platform->configure(['startLifetime' => 700]);

        $runId = $this->startByThePlatform();

        self::assertSame(700, $this->secondsAStartWasGivenWhenFirstSent($runId));
    }

    public function test_the_use_by_time_of_a_start_is_fixed_when_the_start_is_first_sent(): void
    {
        $runId = $this->platform->fire(self::DEFINITION, self::WORKFLOW_ID)['runId'];
        // The start cannot leave for a minute: the endpoint asked for time, or it stopped verifying the signature.
        $this->timePasses(60);

        $this->tick();

        self::assertSame(self::START_LIFETIME, $this->secondsAStartWasGivenWhenFirstSent($runId), 'The minute it waited is not taken from its lifetime.');
    }

    public function test_a_start_cannot_be_given_more_than_an_hour(): void
    {
        $this->expectExceptionMessage('A start lives 3600 seconds at most.');

        $this->platform->configure(['startLifetime' => 3601]);
    }

    public function test_a_start_cannot_be_given_less_than_ten_minutes(): void
    {
        $this->expectExceptionMessage('A start lives 600 seconds at least.');

        $this->platform->configure(['startLifetime' => 599]);
    }

    public function test_a_test_that_shrinks_the_lower_bound_can_see_a_start_expire_sooner(): void
    {
        $this->platform->configure(['shortestStartLifetime' => 2, 'startLifetime' => 2]);

        $runId = $this->startByThePlatform();

        self::assertSame(2, $this->secondsAStartWasGivenWhenFirstSent($runId));
    }

    public function test_a_start_sent_again_is_the_same_message_with_its_run_id_and_its_use_by_time(): void
    {
        $this->endpoint->answerNext('start', 500, ['code' => 'internal_error']);

        $this->startByThePlatform();
        $this->timePasses(self::RESEND_DELAY);
        $this->tick();

        [$first, $again] = $this->sent(WireLog::START);
        self::assertSame($first, $again);
    }

    // ----------------------------------------------------------------
    // Rule 2: a fence the application itself reported, never a guess
    // ----------------------------------------------------------------

    public function test_a_wake_carries_the_run_id_and_the_attempt_of_the_snapshot_the_application_reported(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 7, time() - 1));

        $this->tick();

        [$wake] = $this->sent(WireLog::WAKE);
        self::assertSame([$runId, 7], [$wake['runId'], $wake['attempt']]);
    }

    public function test_a_wake_carries_the_attempt_an_inspect_returned(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = $this->startByThePlatform();
        $this->application->announce($runId, self::WORKFLOW_ID, ['origin' => 'platform', 'leaseSeconds' => 1, 'at' => time() - 1]);
        $this->endpoint->showNext(Shown::failed($runId, 4));

        $this->tick();
        $this->tick();

        self::assertSame(4, $this->sent(WireLog::WAKE)[0]['attempt']);
    }

    public function test_the_abandon_of_a_start_nothing_was_heard_of_carries_its_run_id_and_no_attempt(): void
    {
        $runId = $this->startByThePlatform();

        $this->platform->abandon($runId);
        $this->tick();

        [$abandon] = $this->sent(WireLog::ABANDON);
        self::assertSame($runId, $abandon['runId']);
        self::assertFalse(array_key_exists('attempt', $abandon));
    }

    public function test_the_abandon_of_a_run_a_snapshot_named_carries_the_attempt_shown(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 3, null));

        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame(3, $this->sent(WireLog::ABANDON)[0]['attempt']);
    }

    public function test_the_abandon_of_a_run_of_the_application_that_no_snapshot_named_is_refused_for_want_of_an_attempt(): void
    {
        $runId = self::runId();
        $this->application->announce($runId, self::WORKFLOW_ID, ['leaseSeconds' => 600]);

        $this->expectExceptionMessage('The platform holds no attempt for this run and did not send its start');

        $this->platform->abandon($runId);
    }

    // ----------------------------------------------------------------
    // Rule 3: send a run its own definition
    // ----------------------------------------------------------------

    public function test_a_run_keeps_the_definition_of_the_first_message_whose_run_id_it_is(): void
    {
        $runId = self::runId();
        $this->application->announce($runId, self::WORKFLOW_ID);

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), [
            'definition' => 'another-definition',
            'executedAttempt' => 1,
        ]);

        self::assertSame(self::DEFINITION, $this->held($runId)['definition']);
    }

    public function test_a_run_seen_only_in_a_message_about_another_run_is_adopted_under_the_definition_its_snapshot_shows(): void
    {
        $this->theManifestAlsoHolds(self::RETURNS);
        $holder = Shown::suspended(self::runId(), 2, time() + 60);
        $refused = self::runId();

        // A start of another definition met the run that holds the workflow ID: the report is about the start.
        $this->application->report($refused, self::WORKFLOW_ID, 'refused', $holder, ['definition' => self::RETURNS, 'code' => 'run_in_flight']);

        self::assertSame([$refused, $holder['runId']], array_column($this->platform->runsOf(self::WORKFLOW_ID), 'runId'));
        $adopted = $this->held($holder['runId']);
        self::assertSame([self::DEFINITION, 'application', 'open', $holder], [$adopted['definition'], $adopted['origin'], $adopted['state'], $adopted['run']]);
    }

    public function test_a_run_adopted_from_a_snapshot_is_woken_like_any_run_under_the_definition_shown(): void
    {
        $this->theManifestAlsoHolds(self::RETURNS);
        $holder = self::runId();

        $this->application->report(self::runId(), self::WORKFLOW_ID, 'refused', Shown::suspended($holder, 2, time() - 1), [
            'definition' => self::RETURNS,
            'code' => 'run_in_flight',
        ]);

        self::assertSame(['wake timer:1: 202'], $this->tick());
        [$wake] = $this->sent(WireLog::WAKE);
        self::assertSame([self::DEFINITION, $holder, 2], [$wake['definition'], $wake['runId'], $wake['attempt']]);
    }

    public function test_a_run_seen_only_in_the_answer_to_an_inspect_is_adopted_and_woken_too(): void
    {
        $asked = $this->runOfTheApplication();
        $holder = self::runId();
        // The run the platform asks about has left its workflow ID, and a run it never heard of holds it.
        $this->endpoint->showNext(Shown::suspended($holder, 4, time() - 1), at: time() + 1);
        $this->timePasses(self::PERIODIC_READ);

        self::assertSame([['inspect periodic_read: 200'], ['wake timer:1: 202']], [$this->tick(), $this->tick()]);
        self::assertSame(['ended_unknown', 'open'], [$this->held($asked)['state'], $this->held($holder)['state']]);
        self::assertSame([self::DEFINITION, $holder, 4], [$this->sent(WireLog::WAKE)[0]['definition'], $this->sent(WireLog::WAKE)[0]['runId'], $this->sent(WireLog::WAKE)[0]['attempt']]);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function definitionsNoRunIsAdoptedUnder(): array
    {
        return [
            'none: the run carries no tag, or a label of code outside the SDK' => [null],
            'a definition the manifest does not hold' => ['never-published'],
        ];
    }

    #[DataProvider('definitionsNoRunIsAdoptedUnder')]
    public function test_a_run_seen_only_in_a_snapshot_is_not_adopted_when_the_snapshot_shows(?string $definition): void
    {
        $refused = self::runId();

        $this->application->report($refused, self::WORKFLOW_ID, 'refused', Shown::suspended(self::runId(), 2, time() - 1, definition: $definition), ['code' => 'run_in_flight']);

        self::assertSame([$refused], array_column($this->platform->runsOf(self::WORKFLOW_ID), 'runId'));
        self::assertSame([], $this->tick(), 'Nothing tells the platform a definition it could send the run that holds the workflow ID.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messagesThatNameADefinition(): array
    {
        return ['a wake' => ['wake'], 'an inspect' => ['inspect'], 'an abandon' => ['abandon']];
    }

    #[DataProvider('messagesThatNameADefinition')]
    public function test_the_platform_alerts_instead_of_sending_when_the_last_snapshot_shows_another_definition_than_it_would_name(string $due): void
    {
        $runId = $this->runThatIsDue($due, shownUnder: self::RETURNS);

        self::assertSame([[], []], [$this->tick(), $this->tick()]);
        self::assertSame([['another_definition', $runId]], $this->alerts());
        self::assertSame([[], [], []], [$this->sent(WireLog::WAKE), $this->sent(WireLog::INSPECT), $this->sent(WireLog::ABANDON)]);
    }

    #[DataProvider('messagesThatNameADefinition')]
    public function test_a_run_whose_snapshot_shows_no_definition_is_sent_the_definition_of_its_first_message(string $due): void
    {
        $this->runThatIsDue($due, shownUnder: null);

        self::assertCount(1, $this->tick());
        self::assertSame(self::DEFINITION, $this->sent($due)[0]['definition']);
        self::assertSame([], $this->alerts());
    }

    public function test_the_platform_holds_the_definition_the_last_snapshot_showed_for_a_run(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 1, null, definition: null));
        self::assertNull($this->held($runId)['run']['definition']);

        $this->runOfTheApplication(run: Shown::suspended($runId, 1, null));

        self::assertSame(self::DEFINITION, $this->held($runId)['run']['definition'], 'Also from a snapshot that replaces nothing else of what the platform holds.');
    }

    public function test_every_order_and_question_about_a_run_names_the_definition_the_platform_holds_for_it(): void
    {
        $this->platform->configure(['retryDelay' => 0]);
        $runId = $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));

        $this->tick();
        $this->platform->abandon($runId);
        $this->tick();

        self::assertSame(self::DEFINITION, $this->sent(WireLog::WAKE)[0]['definition']);
        self::assertSame(self::DEFINITION, $this->sent(WireLog::ABANDON)[0]['definition']);
    }

    // ----------------------------------------------------------------
    // Rule 4: one start per firing of a trigger
    // ----------------------------------------------------------------

    public function test_a_firing_named_twice_issues_one_start(): void
    {
        $first = $this->platform->fire(self::DEFINITION, self::WORKFLOW_ID, 'nightly-2026-10-09');
        $again = $this->platform->fire(self::DEFINITION, self::WORKFLOW_ID, 'nightly-2026-10-09');

        $this->tick();
        $this->tick();

        self::assertSame($first['runId'], $again['runId']);
        self::assertCount(1, $this->sent(WireLog::START));
    }

    public function test_two_firings_issue_two_starts(): void
    {
        $this->platform->fire(self::DEFINITION, self::WORKFLOW_ID, 'nightly-2026-10-09');
        $this->platform->fire(self::DEFINITION, self::WORKFLOW_ID, 'nightly-2026-10-10');

        self::assertSame(['start trigger: 202', 'start trigger: 202'], $this->tick());
    }

    public function test_a_start_that_was_accepted_is_not_sent_at_the_next_tick(): void
    {
        $this->startByThePlatform();

        self::assertSame([], $this->tick());
    }

    // ----------------------------------------------------------------
    // Rule 5: abandon only when an operator asks
    // ----------------------------------------------------------------

    public function test_no_abandon_is_sent_for_a_run_the_retry_policy_gave_up_on(): void
    {
        $this->platform->configure(['retryAttempts' => 0]);
        $this->runOfTheApplication(run: Shown::failed(self::runId(), 1));

        self::assertSame([], $this->tick());
        self::assertSame([], $this->sent(WireLog::ABANDON));
    }

    public function test_an_abandon_is_sent_once_an_operator_asked_for_it(): void
    {
        $runId = $this->runOfTheApplication();

        $this->platform->abandon($runId);

        self::assertSame(['abandon operator: 200'], $this->tick());
        self::assertSame([], $this->tick());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function answersThatPutAQuestionOff(): array
    {
        return ['a 400, which puts the next question off for a day' => [400], 'a 500, which is followed by a back-off' => [500]];
    }

    #[DataProvider('answersThatPutAQuestionOff')]
    public function test_an_abandon_leaves_with_the_next_tick_whatever_the_platform_asked_about_the_run_before(int $status): void
    {
        $runId = $this->startByThePlatform();
        $this->timePasses(self::START_LIFETIME + self::START_SILENCE);
        $this->endpoint->answerNext('inspect', $status, ['code' => 'invalid_request']);
        $this->tick();

        $this->platform->abandon($runId);

        self::assertSame(['abandon operator: 200'], $this->tick());
    }

    // ----------------------------------------------------------------
    // What the tests are written with
    // ----------------------------------------------------------------

    /**
     * The application publishes a second definition beside the one every test begins with.
     */
    protected function theManifestAlsoHolds(string $definition): void
    {
        $answer = $this->application->post('/v1/sync', [
            'protocols' => [1],
            'sdk' => PlayedApplication::SDK,
            'endpoint' => PlayedApplication::ENDPOINT,
            'definitions' => [
                ['definition' => self::DEFINITION, 'version' => 1, 'leaseSeconds' => 60],
                ['definition' => $definition, 'version' => 1, 'leaseSeconds' => 60],
            ],
        ]);

        self::assertSame(200, $answer['status']);
    }

    /**
     * The application reports a run under the definition every test begins with, and a message of the
     * platform about that run is due.
     *
     * @param string $due wake, inspect or abandon
     * @param string|null $shownUnder the definition the snapshot of that report shows for the run
     * @return string the run ID
     */
    protected function runThatIsDue(string $due, ?string $shownUnder): string
    {
        $timer = $due === 'wake' ? time() - 1 : null;
        $runId = $this->runOfTheApplication(run: Shown::suspended(self::runId(), 3, $timer, definition: $shownUnder));

        if ($due === 'inspect') {
            $this->timePasses(self::PERIODIC_READ);
        } elseif ($due === 'abandon') {
            $this->platform->abandon($runId);
        }

        return $runId;
    }

    /**
     * @return int the seconds between the moment the platform first sent a start, by its own clock, and the
     *             use-by time the start carries
     */
    protected function secondsAStartWasGivenWhenFirstSent(string $runId): int
    {
        [$start] = $this->held($runId)['orders'];

        return $this->sent(WireLog::START)[0]['expiresAt'] - (int) $start['acceptedAt'];
    }
}
