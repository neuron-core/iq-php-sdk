<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Sync;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\Testing\FakePlatform;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the fake platform sends back to the SDK must be what the real one
 * would send: every answer the protocol has a schema for matches it.
 */
class FakePlatformAnswersTest extends TestCase
{
    #[DataProvider('notices')]
    public function test_the_answer_to_an_announcement_or_a_report_matches_the_schema_of_recorded(string $path): void
    {
        $answer = (new FakePlatform())->request(HttpRequest::post('https://platform.test' . $path, ['workflowId' => 'order:123']));

        self::assertSame(200, $answer->statusCode);
        self::assertSame([], ProtocolSchema::violations('recorded', $answer->json()));
    }

    #[DataProvider('whatThePlatformOnlyTakesIn')]
    public function test_a_trace_and_an_evaluation_are_taken_in_with_a_200(string $path): void
    {
        $answer = (new FakePlatform())->request(HttpRequest::post('https://platform.test' . $path, ['resourceSpans' => []]));

        self::assertSame([200, []], [$answer->statusCode, $answer->json()]);
    }

    public function test_a_trace_can_be_refused_like_any_other_request(): void
    {
        $platform = (new FakePlatform())->failNext(1, 503);

        $answer = self::answerOf($platform, HttpRequest::post('https://platform.test/v1/traces', ['resourceSpans' => []]));

        self::assertSame(503, $answer->statusCode);
    }

    public function test_the_answer_to_a_sync_matches_the_schema_of_a_sync_answer(): void
    {
        $answer = (new FakePlatform())->request(self::sync());

        self::assertSame(200, $answer->statusCode);
        self::assertSame([], ProtocolSchema::violations('sync-answer', $answer->json()));
    }

    #[DataProvider('refusalsWithACode')]
    public function test_a_refusal_the_platform_gives_for_good_matches_the_schema_of_a_platform_error(int $status, string $code): void
    {
        $platform = (new FakePlatform())->failNext(1, $status);

        $answer = self::answerOf($platform, self::sync());

        self::assertSame(['code' => $code], $answer->json());
        self::assertSame([], ProtocolSchema::violations('platform-error', $answer->json()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function whatThePlatformOnlyTakesIn(): array
    {
        return [
            'a trace' => ['/v1/traces'],
            'the results of an evaluation' => ['/v1/evaluations'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notices(): array
    {
        return [
            'an announcement' => ['/v1/announcements'],
            'a report' => ['/v1/reports'],
        ];
    }

    /**
     * @return array<string, array{int, string}> a status, and the code the platform gives with it
     */
    public static function refusalsWithACode(): array
    {
        return [
            'a 400' => [400, 'invalid_request'],
            'a 401' => [401, 'unauthorized'],
            'a 403' => [403, 'unauthorized'],
            'a 422' => [422, 'endpoint_unverified'],
        ];
    }

    protected static function sync(): HttpRequest
    {
        return HttpRequest::post('https://platform.test/v1/sync', [
            'protocols' => [1],
            'sdk' => 'iq-php/1.0.0',
            'endpoint' => 'https://shop.example.com/iq',
            'definitions' => [
                ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 600],
                ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 600],
            ],
        ]);
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
}
