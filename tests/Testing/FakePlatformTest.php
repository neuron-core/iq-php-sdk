<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function strlen;
use function substr;

class FakePlatformTest extends TestCase
{
    protected const WORKFLOW_ID = 'order:123';

    #[DataProvider('paths')]
    public function test_an_announcement_or_a_report_is_answered_as_recorded(string $path): void
    {
        $answer = (new FakePlatform())->request(self::post($path));

        self::assertSame(200, $answer->statusCode);
        self::assertSame(['recorded' => true], $answer->json());
    }

    public function test_an_unknown_path_is_answered_404_and_thrown_as_the_clients_of_neuron_do(): void
    {
        $answer = self::answerOf(new FakePlatform(), self::post('/v1/sync'));

        self::assertSame(404, $answer->statusCode);
    }

    public function test_a_request_to_an_unknown_path_is_not_recorded(): void
    {
        $platform = new FakePlatform();
        self::answerOf($platform, self::post('/v2/announcements'));

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_fail_next_answers_the_next_request_with_503(): void
    {
        $platform = (new FakePlatform())->failNext();

        self::assertSame(503, self::answerOf($platform, self::announcement())->statusCode);
    }

    #[DataProvider('statuses')]
    public function test_fail_next_answers_with_the_status_it_is_given_and_the_code_that_goes_with_it(int $status, ?string $code): void
    {
        $platform = (new FakePlatform())->failNext(1, $status);

        $answer = self::answerOf($platform, self::announcement());

        self::assertSame($status, $answer->statusCode);
        self::assertSame(['code' => $code], $answer->json());
    }

    public function test_a_failure_of_400_and_above_is_thrown_with_its_answer_attached(): void
    {
        $platform = (new FakePlatform())->failNext(1, 500);

        try {
            $platform->request(self::announcement());
            self::fail('The answer should have been thrown.');
        } catch (HttpException $thrown) {
            self::assertSame(500, $thrown->response?->statusCode);
        }
    }

    public function test_a_failure_below_400_is_returned(): void
    {
        $platform = (new FakePlatform())->failNext(1, 302);

        self::assertSame(302, $platform->request(self::announcement())->statusCode);
    }

    #[DataProvider('thrownAndReturnedFailures')]
    public function test_a_request_fail_next_answered_is_not_recorded(int $status): void
    {
        $platform = (new FakePlatform())->failNext(1, $status);
        self::answerOf($platform, self::announcement());

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_fail_next_fails_as_many_requests_as_it_is_told_then_records_again(): void
    {
        $platform = (new FakePlatform())->failNext(2);

        $statuses = [
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::announcement())->statusCode,
        ];

        self::assertSame([503, 503, 200], $statuses);
    }

    public function test_failures_asked_for_one_after_the_other_are_answered_in_that_order(): void
    {
        $platform = (new FakePlatform())->failNext(1, 500)->failNext(1, 401);

        $statuses = [
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::report())->statusCode,
            self::answerOf($platform, self::report())->statusCode,
        ];

        self::assertSame([500, 401, 200], $statuses);
    }

    public function test_assert_announced_passes_for_a_recorded_announcement_of_the_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement());

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_announced_passes_for_the_verb_that_was_announced(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement(verb: 'wake'));

        $platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
    }

    public function test_assert_announced_fails_when_nothing_was_announced(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement for workflow ID 'order:123'.");

        (new FakePlatform())->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_announced_fails_for_another_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement for workflow ID 'order:124'.");

        $platform->assertAnnounced('order:124');
    }

    public function test_assert_announced_fails_for_another_verb(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement(verb: 'start'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement of a 'wake' for workflow ID 'order:123'.");

        $platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
    }

    public function test_assert_announced_does_not_count_a_report(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report());

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_reported_passes_for_a_recorded_report_with_the_outcome(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused);
    }

    public function test_assert_reported_passes_for_the_outcome_and_the_code_that_were_reported(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, 'stale_attempt');
    }

    public function test_assert_reported_fails_when_nothing_was_reported(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:123'.");

        (new FakePlatform())->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_assert_reported_fails_for_another_outcome(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('suspended'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:123'.");

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_assert_reported_fails_for_another_code(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'refused' report with the code 'stale_run' for workflow ID 'order:123'.");

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, 'stale_run');
    }

    public function test_assert_reported_fails_for_another_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:124'.");

        $platform->assertReported('order:124', Outcome::Completed);
    }

    public function test_assert_reported_does_not_count_a_report_the_platform_did_not_record(): void
    {
        $platform = (new FakePlatform())->failNext();
        self::answerOf($platform, self::report());

        $this->expectException(AssertionFailedError::class);

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_the_signing_key_is_a_standard_webhooks_secret(): void
    {
        $signingKey = (new FakePlatform())->signingKey;

        self::assertStringStartsWith('whsec_', $signingKey);
        self::assertGreaterThanOrEqual(24, strlen((string) base64_decode(substr($signingKey, strlen('whsec_')), true)));
    }

    public function test_every_fake_platform_signs_with_a_key_of_its_own(): void
    {
        self::assertNotSame((new FakePlatform())->signingKey, (new FakePlatform())->signingKey);
    }

    public function test_the_platform_has_nothing_to_stream(): void
    {
        $this->expectException(HttpException::class);

        (new FakePlatform())->stream(self::announcement());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            'announcements' => ['/v1/announcements'],
            'reports' => ['/v1/reports'],
        ];
    }

    /**
     * @return array<string, array{int, string|null}> a status, and the code the platform gives with it
     */
    public static function statuses(): array
    {
        return [
            'a 400' => [400, 'invalid_request'],
            'a 401' => [401, 'unauthorized'],
            'a 403' => [403, 'unauthorized'],
            'a 404' => [404, null],
            'a 429' => [429, null],
            'a 500' => [500, null],
            'a 503' => [503, null],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function thrownAndReturnedFailures(): array
    {
        return [
            'a 503, which is thrown' => [503],
            'a 302, which is returned' => [302],
        ];
    }

    /**
     * What the platform answered, whether its client returned the answer or threw it.
     */
    protected static function answerOf(FakePlatform $platform, HttpRequest $request): HttpResponse
    {
        try {
            return $platform->request($request);
        } catch (HttpException $thrown) {
            return $thrown->response ?? throw $thrown;
        }
    }

    protected static function announcement(string $verb = 'start'): HttpRequest
    {
        return self::post('/v1/announcements', ['workflowId' => self::WORKFLOW_ID, 'verb' => $verb]);
    }

    protected static function report(string $outcome = 'completed', ?string $code = null): HttpRequest
    {
        return self::post('/v1/reports', ['workflowId' => self::WORKFLOW_ID, 'outcome' => $outcome, 'code' => $code]);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected static function post(string $path, array $body = []): HttpRequest
    {
        return HttpRequest::post('https://platform.test' . $path, $body);
    }
}
