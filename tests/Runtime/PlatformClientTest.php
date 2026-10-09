<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_slice;

class PlatformClientTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const PLATFORM_URL = 'https://platform.test';

    protected const API_KEY = 'test-api-key';

    protected const RECORDED = '{"recorded":true}';

    protected const SYNCED = '{"protocol":1,"modified":false,"versions":[],"stranded":[]}';

    protected const MANIFEST = [
        'protocols' => [1],
        'sdk' => 'neuron-cloud-php/1.0.0',
        'endpoint' => 'https://shop.example.com/neuron-cloud.php',
        'definitions' => [['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 600]],
    ];

    protected const ANNOUNCEMENT = [
        'protocol' => 1,
        'invocationId' => 'inv_01JB7K4T9QW2',
        'verb' => 'wake',
        'origin' => 'platform',
        'definition' => 'order-fulfilment',
        'workflowId' => 'order:123',
        'runId' => '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
        'attempt' => 1,
        'leaseSeconds' => 600,
        'at' => 1791453603,
    ];

    #[DataProvider('platformUrls')]
    public function test_an_announcement_is_posted_to_the_announcements_path_of_the_platform_url(string $platformUrl, string $expected): void
    {
        $http = self::recording();

        (new PlatformClient($http, $platformUrl, self::API_KEY))->announce(self::ANNOUNCEMENT);

        self::assertSame(HttpMethod::POST, self::sent($http)->method);
        self::assertSame($expected . '/v1/announcements', self::sent($http)->uri);
    }

    #[DataProvider('platformUrls')]
    public function test_a_report_is_posted_to_the_reports_path_of_the_platform_url(string $platformUrl, string $expected): void
    {
        $http = self::recording();

        (new PlatformClient($http, $platformUrl, self::API_KEY))->report(self::report());

        self::assertSame(HttpMethod::POST, self::sent($http)->method);
        self::assertSame($expected . '/v1/reports', self::sent($http)->uri);
    }

    public function test_a_request_carries_the_api_key_as_a_bearer_token(): void
    {
        $http = self::recording();

        self::client($http)->announce(self::ANNOUNCEMENT);

        self::assertSame('Bearer ' . self::API_KEY, self::sent($http)->headers['Authorization']);
    }

    public function test_a_request_names_the_sdk_and_its_version_as_its_user_agent(): void
    {
        $http = self::recording();

        self::client($http)->announce(self::ANNOUNCEMENT);

        self::assertMatchesRegularExpression('~^neuron-cloud-php/\d+\.\d+\.\d+$~D', self::sent($http)->headers['User-Agent']);
    }

    public function test_a_request_is_given_three_seconds(): void
    {
        $http = self::recording();

        self::client($http)->announce(self::ANNOUNCEMENT);

        self::assertSame(3.0, self::sent($http)->timeout);
    }

    public function test_the_body_of_an_announcement_is_the_announcement(): void
    {
        $http = self::recording();

        self::client($http)->announce(self::ANNOUNCEMENT);

        self::assertSame(self::ANNOUNCEMENT, self::sent($http)->body);
    }

    public function test_the_body_of_a_report_is_its_array_form(): void
    {
        $http = self::recording();
        $report = self::report();

        self::client($http)->report($report);

        self::assertSame($report->toArray(), self::sent($http)->body);
    }

    public function test_a_200_that_says_recorded_is_a_delivery(): void
    {
        self::assertNull(self::refusalOf(new HttpResponse(200, self::RECORDED)));
    }

    #[DataProvider('unauthorizedAnswers')]
    public function test_a_401_or_a_403_is_final_as_unauthorized(HttpResponse $answer, bool $thrown): void
    {
        self::assertSame(Code::Unauthorized, self::refusalOf($answer, $thrown));
    }

    #[DataProvider('answersWithAFinalCode')]
    public function test_a_4xx_that_carries_invalid_request_or_unsupported_protocol_is_final(HttpResponse $answer, bool $thrown, Code $reason): void
    {
        self::assertSame($reason, self::refusalOf($answer, $thrown));
    }

    #[DataProvider('answersThatRecordNothing')]
    public function test_any_other_answer_counts_as_undelivered(HttpResponse $answer, bool $thrown): void
    {
        self::assertSame(Code::ReportUndelivered, self::refusalOf($answer, $thrown));
    }

    public function test_a_client_that_gets_no_answer_counts_as_undelivered(): void
    {
        $unreachable = HttpException::networkError(HttpRequest::post(self::PLATFORM_URL), 'Connection refused');

        $refused = self::refusalBy(new ScriptedHttpClient($unreachable));

        self::assertSame(Code::ReportUndelivered, $refused?->reason);
        self::assertSame($unreachable, $refused->getPrevious());
    }

    public function test_a_client_that_throws_something_else_than_an_http_exception_counts_as_undelivered(): void
    {
        $failure = new RuntimeException('The client of the application failed in its own way.');

        $refused = self::refusalBy(new ScriptedHttpClient($failure));

        self::assertSame(Code::ReportUndelivered, $refused?->reason);
        self::assertSame($failure, $refused->getPrevious());
    }

    public function test_a_report_the_platform_refuses_for_good_is_final_like_an_announcement(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(401, '{"code":"unauthorized"}'));

        $reason = self::reasonThrownBy(static function () use ($http): void {
            self::client($http)->report(self::report());
        });

        self::assertSame(Code::Unauthorized, $reason);
    }

    public function test_a_notice_the_platform_did_not_record_is_not_tried_again(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(503, ''));

        self::refusalBy($http);

        self::assertCount(1, $http->received());
    }

    #[DataProvider('platformUrls')]
    public function test_a_sync_is_posted_to_the_sync_path_of_the_platform_url(string $platformUrl, string $expected): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, self::SYNCED));

        (new PlatformClient($http, $platformUrl, self::API_KEY))->sync(self::MANIFEST);

        self::assertSame(HttpMethod::POST, self::sent($http)->method);
        self::assertSame($expected . '/v1/sync', self::sent($http)->uri);
        self::assertSame(self::MANIFEST, self::sent($http)->body);
    }

    public function test_a_sync_carries_the_api_key_and_is_given_fifteen_seconds(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, self::SYNCED));

        self::client($http)->sync(self::MANIFEST);

        self::assertSame('Bearer ' . self::API_KEY, self::sent($http)->headers['Authorization']);
        self::assertSame(15.0, self::sent($http)->timeout);
    }

    public function test_a_sync_returns_the_answer_of_the_platform(): void
    {
        $answer = self::client(new ScriptedHttpClient(new HttpResponse(200, self::SYNCED)))->sync(self::MANIFEST);

        self::assertSame(['protocol' => 1, 'modified' => false, 'versions' => [], 'stranded' => []], $answer);
    }

    #[DataProvider('refusedSyncs')]
    public function test_a_sync_the_platform_refuses_is_thrown_with_the_matching_code(HttpResponse $answer, Code $reason): void
    {
        self::assertSame($reason, self::syncRefusalBy(new ScriptedHttpClient($answer)));
        self::assertSame($reason, self::syncRefusalBy(new ScriptedHttpClient($answer, throwsStatusErrors: false)));
    }

    public function test_a_sync_that_gets_no_answer_counts_as_undelivered(): void
    {
        $unreachable = HttpException::networkError(HttpRequest::post(self::PLATFORM_URL), 'Connection refused');

        self::assertSame(Code::ReportUndelivered, self::syncRefusalBy(new ScriptedHttpClient($unreachable)));
    }

    #[DataProvider('answersThatAreNoSyncAnswer')]
    public function test_a_200_that_is_not_the_answer_to_a_sync_counts_as_undelivered(string $body): void
    {
        self::assertSame(Code::ReportUndelivered, self::syncRefusalBy(new ScriptedHttpClient(new HttpResponse(200, $body))));
    }

    /**
     * @return array<string, array{HttpResponse, Code}>
     */
    public static function refusedSyncs(): array
    {
        return [
            'a 422 endpoint_unverified' => [new HttpResponse(422, '{"code":"endpoint_unverified"}'), Code::EndpointUnverified],
            'a 401' => [new HttpResponse(401, '{"code":"unauthorized"}'), Code::Unauthorized],
            'a 403' => [new HttpResponse(403, ''), Code::Unauthorized],
            'a 400 invalid_request' => [new HttpResponse(400, '{"code":"invalid_request"}'), Code::InvalidRequest],
            'a 400 unsupported_protocol' => [new HttpResponse(400, '{"code":"unsupported_protocol"}'), Code::UnsupportedProtocol],
            'a 422 with no code' => [new HttpResponse(422, '{}'), Code::ReportUndelivered],
            'a 500 that carries endpoint_unverified' => [new HttpResponse(500, '{"code":"endpoint_unverified"}'), Code::ReportUndelivered],
            'a 503' => [new HttpResponse(503, ''), Code::ReportUndelivered],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function answersThatAreNoSyncAnswer(): array
    {
        return [
            'an empty body' => [''],
            'the page of a proxy' => ['<html><body>OK</body></html>'],
            'an empty object' => ['{}'],
            'the answer to an announcement' => [self::RECORDED],
            'a modified that is no boolean' => ['{"protocol":1,"modified":"true","versions":[],"stranded":[]}'],
            'versions that are no list' => ['{"protocol":1,"modified":true,"versions":{"order-fulfilment":1},"stranded":[]}'],
            'a version that is no integer' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":"1","openRuns":0}],"stranded":[]}'],
            'a stranded version without its open runs' => ['{"protocol":1,"modified":true,"versions":[],"stranded":[{"definition":"trial","version":3}]}'],
        ];
    }

    /**
     * @return array<string, array{string, string}> a platform URL as it is configured, and the address the paths are added to
     */
    public static function platformUrls(): array
    {
        return [
            'no trailing slash' => ['https://platform.test', 'https://platform.test'],
            'a trailing slash' => ['https://platform.test/', 'https://platform.test'],
            'a path' => ['https://example.com/neuron-cloud', 'https://example.com/neuron-cloud'],
            'a path and a trailing slash' => ['https://example.com/neuron-cloud/', 'https://example.com/neuron-cloud'],
            'a port' => ['http://127.0.0.1:8080', 'http://127.0.0.1:8080'],
        ];
    }

    /**
     * @return iterable<string, array{HttpResponse, bool}>
     */
    public static function unauthorizedAnswers(): iterable
    {
        return self::inBothShapes([
            'a 401 with the code' => [new HttpResponse(401, '{"code":"unauthorized"}')],
            'a 403 with the code' => [new HttpResponse(403, '{"code":"unauthorized"}')],
            'a 401 with another code' => [new HttpResponse(401, '{"code":"invalid_request"}')],
            'a 401 with no body' => [new HttpResponse(401, '')],
            'a 403 from a proxy, in HTML' => [new HttpResponse(403, '<html><body>Forbidden</body></html>')],
        ]);
    }

    /**
     * @return iterable<string, array{HttpResponse, bool, Code}>
     */
    public static function answersWithAFinalCode(): iterable
    {
        return self::inBothShapes([
            'a 400 invalid_request' => [new HttpResponse(400, '{"code":"invalid_request"}'), Code::InvalidRequest],
            'a 400 unsupported_protocol' => [new HttpResponse(400, '{"code":"unsupported_protocol"}'), Code::UnsupportedProtocol],
            'a 422 invalid_request' => [new HttpResponse(422, '{"code":"invalid_request"}'), Code::InvalidRequest],
            'a 426 unsupported_protocol' => [new HttpResponse(426, '{"code":"unsupported_protocol"}'), Code::UnsupportedProtocol],
        ]);
    }

    /**
     * @return iterable<string, array{HttpResponse, bool}>
     */
    public static function answersThatRecordNothing(): iterable
    {
        yield from self::inBothShapes([
            'a 400 with another code' => [new HttpResponse(400, '{"code":"endpoint_unverified"}')],
            'a 400 with no code' => [new HttpResponse(400, '{}')],
            'a 400 with a code that is not a string' => [new HttpResponse(400, '{"code":["invalid_request"]}')],
            'a 400 that is not JSON' => [new HttpResponse(400, 'Bad Request')],
            'a 404' => [new HttpResponse(404, '{"code":"unknown_definition"}')],
            'a 409' => [new HttpResponse(409, '{"code":"stale_run"}')],
            'a 429' => [new HttpResponse(429, '')],
            'a 500' => [new HttpResponse(500, '{"code":"internal_error"}')],
            'a 500 that carries invalid_request' => [new HttpResponse(500, '{"code":"invalid_request"}')],
            'a 502 from a proxy, in HTML' => [new HttpResponse(502, '<html><body>Bad Gateway</body></html>')],
            'a 503' => [new HttpResponse(503, '')],
        ]);

        // A client only ever returns these: none of Neuron's throws an answer below 400.
        $returned = [
            'a 200 with no body' => new HttpResponse(200, ''),
            'a 200 with an empty object' => new HttpResponse(200, '{}'),
            'a 200 that says not recorded' => new HttpResponse(200, '{"recorded":false}'),
            'a 200 that says recorded with a string' => new HttpResponse(200, '{"recorded":"true"}'),
            'a 200 that says recorded with a number' => new HttpResponse(200, '{"recorded":1}'),
            'a 200 that is JSON but no object' => new HttpResponse(200, 'true'),
            'a 200 that is not JSON' => new HttpResponse(200, 'OK'),
            'a 201 that says recorded' => new HttpResponse(201, self::RECORDED),
            'a 202 that says recorded' => new HttpResponse(202, self::RECORDED),
            'a 204' => new HttpResponse(204, ''),
            'a 302 that was not followed' => new HttpResponse(302, '', ['Location' => 'https://elsewhere.test/v1/announcements']),
            'a 307 that was not followed' => new HttpResponse(307, self::RECORDED, ['Location' => 'https://elsewhere.test/v1/announcements']),
        ];

        foreach ($returned as $name => $answer) {
            yield $name => [$answer, false];
        }
    }

    /**
     * An answer of 400 and above reaches the SDK thrown by Neuron's clients, or returned by a client with a policy of its own.
     *
     * @param array<string, array{0: HttpResponse, 1?: Code}> $answers
     * @return iterable<string, array{0: HttpResponse, 1: bool, 2?: Code}>
     */
    protected static function inBothShapes(array $answers): iterable
    {
        foreach ($answers as $name => $answer) {
            yield "{$name}, thrown by the client" => [$answer[0], true, ...array_slice($answer, 1)];
            yield "{$name}, returned by the client" => [$answer[0], false, ...array_slice($answer, 1)];
        }
    }

    /**
     * The reason an announcement was refused with after that answer, or null when the platform recorded it.
     */
    protected static function refusalOf(HttpResponse $answer, bool $thrown = true): ?Code
    {
        return self::refusalBy(new ScriptedHttpClient($answer, $thrown))?->reason;
    }

    /**
     * What announcing through that client threw, or null when the platform recorded the announcement.
     */
    protected static function refusalBy(ScriptedHttpClient $http): ?CloudException
    {
        return self::thrownBy(static function () use ($http): void {
            self::client($http)->announce(self::ANNOUNCEMENT);
        });
    }

    /**
     * The reason a sync through that client was refused with, or null when the platform accepted it.
     */
    protected static function syncRefusalBy(ScriptedHttpClient $http): ?Code
    {
        return self::reasonThrownBy(static function () use ($http): void {
            self::client($http)->sync(self::MANIFEST);
        });
    }

    protected static function recording(): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, self::RECORDED));
    }

    protected static function client(ScriptedHttpClient $http): PlatformClient
    {
        return new PlatformClient($http, self::PLATFORM_URL, self::API_KEY);
    }

    protected static function sent(ScriptedHttpClient $http): HttpRequest
    {
        return $http->received()[0];
    }

    protected static function report(): Report
    {
        return new Report(
            'inv_01JB7K4T9QW2',
            Verb::Wake,
            'platform',
            'order-fulfilment',
            'order:123',
            '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
            Outcome::Completed,
            null,
            null,
            2,
            null,
            null,
            true,
            1791453604,
        );
    }
}
