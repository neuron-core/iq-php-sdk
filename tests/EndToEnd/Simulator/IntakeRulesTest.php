<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\Simulator;

use NeuronCore\IQ\Tests\Support\EndToEnd\PlayedApplication;
use NeuronCore\IQ\Tests\Support\EndToEnd\ScriptedEndpoint;
use NeuronCore\IQ\Tests\Support\EndToEnd\Shown;
use NeuronCore\IQ\Tests\Support\EndToEnd\SimulatorTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_column;
use function json_encode;
use function str_replace;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Rules 15, 16 and 18 of appendix B of the design: what the platform
 * simulator accepts and keeps of what an application sends, how it takes a
 * sync, and what it tells a person.
 */
class IntakeRulesTest extends SimulatorTestCase
{
    /** Unlike anything the protocol carries: finding it in the memory of the platform is a leak. */
    protected const CONFIDENTIAL = 'CONFIDENTIAL-5c1d77-sent-in-a-field-the-protocol-does-not-know';

    // ----------------------------------------------------------------
    // Rule 15: what the platform accepts, and what it keeps
    // ----------------------------------------------------------------

    public function test_an_announcement_is_answered_200_recorded(): void
    {
        $answer = $this->application->post('/v1/announcements', $this->announcement());

        self::assertSame(['status' => 200, 'body' => ['recorded' => true]], $answer);
    }

    public function test_a_report_for_a_run_the_platform_never_started_is_recorded_and_the_run_is_held(): void
    {
        $runId = self::runId();

        $status = $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), ['executedAttempt' => 1]);

        self::assertSame(200, $status);
        self::assertSame(['application', 'open', 'suspended'], [$this->held($runId)['origin'], $this->held($runId)['state'], $this->held($runId)['run']['status']]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsOfThePlatform(): array
    {
        return ['a sync' => ['/v1/sync'], 'an announcement' => ['/v1/announcements'], 'a report' => ['/v1/reports']];
    }

    #[DataProvider('pathsOfThePlatform')]
    public function test_a_message_under_another_api_key_is_answered_401_unauthorized(string $path): void
    {
        $stranger = new PlayedApplication($this->platform, 'the-key-of-somebody-else');

        self::assertSame(['status' => 401, 'body' => ['code' => 'unauthorized']], $stranger->post($path, $this->announcement()));
    }

    public function test_nothing_is_recorded_of_a_message_under_another_api_key(): void
    {
        $stranger = new PlayedApplication($this->platform, 'the-key-of-somebody-else');

        $stranger->announce(self::runId(), self::WORKFLOW_ID);

        self::assertSame([], $this->platform->runsOf(self::WORKFLOW_ID));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodies(): array
    {
        $announcement = '{"protocol":1,"invocationId":"inv_1","verb":"wake","origin":"platform","definition":"order-fulfilment",'
            . '"workflowId":"order:42","runId":"1-abc","attempt":2,"leaseSeconds":600,"at":1791453603}';

        return [
            'no JSON' => ['not json'],
            'a list' => ['[]'],
            'null' => ['null'],
            'a field is missing' => [str_replace('"leaseSeconds":600,', '', $announcement)],
            'a wake with no attempt' => [str_replace('"attempt":2', '"attempt":null', $announcement)],
            'an identifier of another format' => [str_replace('order-fulfilment', 'Order Fulfilment', $announcement)],
            'an integer written with a fraction' => [str_replace('1791453603', '1791453603.0', $announcement)],
            'an integer written with an exponent' => [str_replace('"attempt":2', '"attempt":2e0', $announcement)],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function test_a_malformed_announcement_is_answered_400_invalid_request_and_nothing_is_recorded(string $body): void
    {
        $answer = $this->application->post('/v1/announcements', $body);

        self::assertSame(['status' => 400, 'body' => ['code' => 'invalid_request']], $answer);
        self::assertSame([], $this->platform->runsOf(self::WORKFLOW_ID));
    }

    public function test_a_message_of_another_protocol_is_answered_400_unsupported_protocol(): void
    {
        $answer = $this->application->post('/v1/announcements', ['protocol' => 2] + $this->announcement());

        self::assertSame(['status' => 400, 'body' => ['code' => 'unsupported_protocol']], $answer);
    }

    public function test_a_sync_that_does_not_speak_protocol_1_is_answered_400_unsupported_protocol(): void
    {
        $answer = $this->application->post('/v1/sync', [
            'protocols' => [2],
            'sdk' => PlayedApplication::SDK,
            'endpoint' => PlayedApplication::ENDPOINT,
            'definitions' => [],
        ]);

        self::assertSame(['status' => 400, 'body' => ['code' => 'unsupported_protocol']], $answer);
    }

    public function test_a_field_the_protocol_does_not_know_is_ignored_and_the_message_recorded(): void
    {
        $answer = $this->application->post('/v1/announcements', $this->announcement() + ['state' => ['card' => self::CONFIDENTIAL]]);

        self::assertSame(200, $answer['status']);
    }

    public function test_the_memory_of_the_platform_keeps_nothing_of_a_field_the_protocol_does_not_know(): void
    {
        $runId = self::runId();
        $this->application->report($runId, self::WORKFLOW_ID, 'failed', Shown::failed($runId, 1) + ['state' => self::CONFIDENTIAL], [
            'errorClass' => 'App\\ShippingFailed',
            'executedAttempt' => 1,
            'message' => self::CONFIDENTIAL,
        ]);

        self::assertStringNotContainsString(self::CONFIDENTIAL, $this->platform->memory());
        self::assertStringNotContainsString(self::CONFIDENTIAL, json_encode($this->held($runId), JSON_THROW_ON_ERROR));
        self::assertStringContainsString(self::CONFIDENTIAL, $this->platform->wireTap(), 'The tap of the wire is how a test sees a leak.');
    }

    // ----------------------------------------------------------------
    // Rule 16: sync
    // ----------------------------------------------------------------

    public function test_the_endpoint_is_pinged_with_a_signed_message_before_the_sync_is_accepted(): void
    {
        $wire = $this->platform->wire();

        self::assertSame([WireLog::PING, WireLog::SYNC], array_column($wire->exchanges, 'message'));
        $wire->assertSignedWith($this->platform->signingKey);
    }

    public function test_a_sync_whose_ping_is_refused_is_answered_422_and_leaves_the_manifest_as_it_was(): void
    {
        $this->endpoint->answerNext('ping', 401, ['code' => 'invalid_signature']);

        $answer = $this->application->sync([1, 2]);

        self::assertSame(['status' => 422, 'body' => ['code' => 'endpoint_unverified']], $answer);
        self::assertSame([1], array_column($this->platform->manifest()['definitions'], 'version'));
    }

    public function test_a_ping_answered_with_something_else_than_the_protocols_of_the_endpoint_fails_the_sync(): void
    {
        $this->endpoint->answerNext('ping', 200, ['pong' => true]);

        self::assertSame(422, $this->application->sync()['status']);
    }

    public function test_a_redirect_of_the_ping_is_not_followed_and_fails_the_sync(): void
    {
        $this->endpoint->answerNext('ping', 302, [], ['Location' => $this->endpoint->url() . '/iq']);

        self::assertSame(422, $this->application->sync()['status']);
        self::assertSame([200, 302], array_column($this->platform->wire()->of(WireLog::PING), 'status'), 'One ping for the first sync, one for this.');
    }

    public function test_an_endpoint_that_is_not_https_is_refused_as_malformed(): void
    {
        $answer = $this->application->sync(endpoint: 'http://application.example.com/iq');

        self::assertSame(['status' => 400, 'body' => ['code' => 'invalid_request']], $answer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpointsThatAreNotPubliclyRoutable(): array
    {
        return [
            'a private address' => ['https://10.0.0.5/iq'],
            'the loopback address' => ['https://127.0.0.1/iq'],
            'a link-local address' => ['https://169.254.169.254/iq'],
            'localhost' => ['https://localhost/iq'],
            'a name nothing leads to' => ['https://nowhere.example.com/iq'],
        ];
    }

    #[DataProvider('endpointsThatAreNotPubliclyRoutable')]
    public function test_an_endpoint_that_is_not_publicly_routable_is_refused_without_a_ping(string $endpoint): void
    {
        $answer = $this->application->sync(endpoint: $endpoint);

        self::assertSame(['status' => 422, 'body' => ['code' => 'endpoint_unverified']], $answer);
        self::assertCount(1, $this->platform->wire()->of(WireLog::PING), 'Only the first sync was pinged.');
    }

    public function test_a_sync_that_moves_the_endpoint_to_another_host_is_refused_until_an_owner_confirms(): void
    {
        $this->platform->tunnel('https://elsewhere.example.com', $this->endpoint->url());

        $answer = $this->application->sync(endpoint: 'https://elsewhere.example.com/iq');

        self::assertSame(['status' => 422, 'body' => ['code' => 'endpoint_unverified']], $answer, 'The protocol has no answer of its own for a move that waits.');
        self::assertSame(PlayedApplication::ENDPOINT, $this->platform->manifest()['endpoint']);
        self::assertSame(['endpoint_move'], array_column($this->platform->alerts(), 'kind'));
    }

    public function test_the_endpoint_moves_to_another_host_once_an_owner_confirmed_it(): void
    {
        $this->platform->tunnel('https://elsewhere.example.com', $this->endpoint->url());
        $this->platform->confirmEndpointMove('elsewhere.example.com');

        $answer = $this->application->sync(endpoint: 'https://elsewhere.example.com/iq');

        self::assertSame([200, true], [$answer['status'], $answer['body']['modified']]);
        self::assertSame('https://elsewhere.example.com/iq', $this->platform->manifest()['endpoint']);
    }

    public function test_another_path_on_the_same_host_needs_no_consent(): void
    {
        $answer = $this->application->sync(endpoint: ScriptedEndpoint::PUBLIC_URL . '/another-path');

        self::assertSame([200, true], [$answer['status'], $answer['body']['modified']]);
    }

    public function test_a_sync_that_changes_nothing_says_so(): void
    {
        self::assertFalse($this->application->sync()['body']['modified']);
    }

    public function test_the_list_of_a_sync_replaces_the_one_the_platform_holds(): void
    {
        $this->application->sync([3, 2]);

        self::assertSame([3, 2], array_column($this->platform->manifest()['definitions'], 'version'));
    }

    public function test_the_answer_counts_the_open_runs_of_every_published_version(): void
    {
        $this->runOfTheApplication('1-first', workflowId: 'order:1');
        $this->runOfTheApplication('1-second', workflowId: 'order:2');
        $this->runOfTheApplication('2-third', workflowId: 'order:3');

        $answer = $this->application->sync([2, 1]);

        self::assertSame(
            [
                ['definition' => self::DEFINITION, 'version' => 2, 'openRuns' => 1],
                ['definition' => self::DEFINITION, 'version' => 1, 'openRuns' => 2],
            ],
            $answer['body']['versions'],
        );
        self::assertSame([], $answer['body']['stranded']);
    }

    public function test_a_run_that_has_ended_is_not_counted_as_open(): void
    {
        $runId = $this->runOfTheApplication();
        $this->application->report($runId, self::WORKFLOW_ID, 'completed', null, ['verb' => 'signal', 'executedAttempt' => 2]);

        self::assertSame(0, $this->application->sync()['body']['versions'][0]['openRuns']);
    }

    public function test_a_version_that_left_the_code_while_runs_remain_is_listed_as_stranded_and_the_sync_accepted(): void
    {
        $this->runOfTheApplication('1-left-behind');

        $answer = $this->application->sync([2]);

        self::assertSame(200, $answer['status']);
        self::assertSame([['definition' => self::DEFINITION, 'version' => 1, 'openRuns' => 1]], $answer['body']['stranded']);
    }

    public function test_a_stranded_version_is_never_invoked(): void
    {
        $runId = $this->runOfTheApplication(run: Shown::suspended('1-left-behind', 1, time() - 1));
        $this->application->sync([2]);

        self::assertSame([], $this->tick());
        self::assertSame(['stranded_version'], array_column($this->held($runId)['timers'], 'held'));
    }

    public function test_a_run_is_invoked_again_once_a_sync_publishes_its_version_again(): void
    {
        $this->runOfTheApplication(run: Shown::suspended('1-left-behind', 1, time() - 1));
        $this->application->sync([2]);

        $this->application->sync([2, 1]);

        self::assertSame(['wake timer:1: 202'], $this->tick());
    }

    public function test_a_start_is_minted_with_the_highest_published_version(): void
    {
        $this->application->sync([1, 3, 2]);

        self::assertStringStartsWith('3-', $this->startByThePlatform());
    }

    public function test_no_start_is_issued_for_a_definition_no_sync_published(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No sync published a definition named 'unknown'");

        $this->platform->fire('unknown', self::WORKFLOW_ID);
    }

    // ----------------------------------------------------------------
    // Rule 18: alert
    // ----------------------------------------------------------------

    public function test_an_announcement_of_a_platform_start_the_platform_never_issued_raises_an_alert(): void
    {
        $runId = self::runId();

        $this->application->announce($runId, self::WORKFLOW_ID, ['origin' => 'platform']);

        self::assertSame([['unissued_order', $runId]], $this->alerts());
    }

    public function test_an_announcement_of_a_platform_wake_the_platform_never_issued_raises_an_alert(): void
    {
        $runId = $this->runOfTheApplication();

        $this->application->announce($runId, self::WORKFLOW_ID, ['verb' => 'wake', 'origin' => 'platform', 'attempt' => 1]);

        self::assertSame([['unissued_order', $runId]], $this->alerts());
    }

    public function test_a_report_of_a_platform_start_the_platform_never_issued_raises_an_alert_with_no_announcement_before_it(): void
    {
        $runId = self::runId();

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), ['origin' => 'platform', 'executedAttempt' => 1, 'claimed' => true]);

        self::assertSame([['unissued_order', $runId]], $this->alerts());
    }

    public function test_the_run_of_a_start_the_platform_never_issued_is_held_all_the_same(): void
    {
        $runId = self::runId();
        $this->application->announce($runId, self::WORKFLOW_ID, ['origin' => 'platform']);

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, time() - 1), ['origin' => 'platform', 'executedAttempt' => 1, 'claimed' => true]);

        self::assertSame(['wake timer:1: 202'], $this->tick(), 'The application wins.');
    }

    public function test_the_announcement_of_a_start_the_platform_issued_raises_no_alert(): void
    {
        $runId = $this->startByThePlatform();

        $this->application->announce($runId, self::WORKFLOW_ID, [
            'origin' => 'platform',
            'invocationId' => $this->sent(WireLog::START)[0]['invocationId'],
        ]);

        self::assertSame([], $this->alerts());
    }

    public function test_a_wake_reported_as_rejected_for_a_wrong_definition_raises_an_alert_and_is_never_sent_again(): void
    {
        $this->platform->configure(['earlyTimerRetry' => 0, 'resendDelay' => 0]);
        $run = Shown::suspended(self::runId(), 2, time() - 1);
        $runId = $this->runOfTheApplication(run: $run);
        $this->tick();

        $this->application->report($runId, self::WORKFLOW_ID, 'rejected', $run, [
            'invocationId' => $this->sent(WireLog::WAKE)[0]['invocationId'],
            'verb' => 'wake',
            'origin' => 'platform',
            'code' => 'wrong_definition',
        ]);

        self::assertSame([['wrong_definition', $runId]], $this->alerts());
        self::assertSame([], $this->tick());
        self::assertSame('open', $this->held($runId)['state'], 'It closes nothing.');
    }

    public function test_a_400_to_an_abandon_raises_an_alert_and_the_abandon_is_never_sent_again(): void
    {
        $runId = $this->runOfTheApplication();
        $this->endpoint->answerNext('abandon', 400, ['code' => 'invalid_request']);

        $this->platform->abandon($runId);

        self::assertSame(['abandon operator: 400'], $this->tick());
        self::assertSame([['refused_by_endpoint', $runId]], $this->alerts());
        self::assertSame([], $this->tick());
    }

    public function test_a_report_whose_clock_is_more_than_the_tolerance_from_the_platforms_raises_an_alert(): void
    {
        $runId = self::runId();

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), ['executedAttempt' => 1, 'at' => time() - 302]);

        self::assertSame([['report_clock', $runId]], $this->alerts());
    }

    public function test_a_report_whose_clock_is_within_the_tolerance_raises_no_alert(): void
    {
        $runId = self::runId();

        $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), ['executedAttempt' => 1, 'at' => time() - 295]);

        self::assertSame([], $this->alerts());
    }

    public function test_a_report_far_from_the_clock_of_the_platform_is_recorded_all_the_same(): void
    {
        $runId = self::runId();

        $status = $this->application->report($runId, self::WORKFLOW_ID, 'suspended', Shown::suspended($runId, 1, null), ['executedAttempt' => 1, 'at' => time() + 900]);

        self::assertSame([200, 'suspended'], [$status, $this->held($runId)['run']['status']]);
    }

    // ----------------------------------------------------------------
    // What the tests are written with
    // ----------------------------------------------------------------

    /**
     * @return array<string, mixed> the announcement of a start of the application's own
     */
    protected function announcement(): array
    {
        return [
            'protocol' => 1,
            'invocationId' => PlayedApplication::invocationId(),
            'verb' => 'start',
            'origin' => 'application',
            'definition' => self::DEFINITION,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::runId(),
            'attempt' => null,
            'leaseSeconds' => 600,
            'at' => time(),
        ];
    }
}
