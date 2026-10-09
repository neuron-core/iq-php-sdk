<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Sync;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\SyncTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * What NeuronCloud::sync() makes of each answer of the platform: a result,
 * or a CloudException whose reason tells the deploy what went wrong.
 */
class SyncAnswerTest extends SyncTestCase
{
    #[DataProvider('whetherAnythingChanged')]
    public function test_a_200_returns_whether_the_platform_changed_anything(bool $modified): void
    {
        $result = self::shop(self::platformAccepting(modified: $modified))->sync(self::ENDPOINT);

        self::assertSame($modified, $result->modified);
    }

    #[DataProvider('acceptedBodies')]
    public function test_a_200_is_the_answer_to_a_sync_also_without_its_lists_or_with_another_protocol(string $body): void
    {
        $result = self::shop(self::platformAnswering(200, $body))->sync(self::ENDPOINT);

        self::assertSame([[], []], [$result->retirable, $result->stranded]);
    }

    public function test_a_422_endpoint_unverified_says_the_platform_could_not_ping_the_endpoint(): void
    {
        $platform = self::platformAnswering(422, ['code' => 'endpoint_unverified']);

        self::assertSame(Code::EndpointUnverified, self::reasonOfTheSync($platform));
    }

    #[DataProvider('unauthorizedAnswers')]
    public function test_a_401_or_a_403_is_unauthorized_whatever_its_body(int $status, string $body): void
    {
        self::assertSame(Code::Unauthorized, self::reasonOfTheSync(self::platformAnswering($status, $body)));
    }

    public function test_a_400_invalid_request_is_final_as_invalid_request(): void
    {
        $platform = self::platformAnswering(400, ['code' => 'invalid_request']);

        self::assertSame(Code::InvalidRequest, self::reasonOfTheSync($platform));
    }

    #[DataProvider('clientErrors')]
    public function test_a_4xx_unsupported_protocol_is_final_as_unsupported_protocol(int $status): void
    {
        $platform = self::platformAnswering($status, ['code' => 'unsupported_protocol']);

        self::assertSame(Code::UnsupportedProtocol, self::reasonOfTheSync($platform));
    }

    #[DataProvider('answersThatPublishedNothing')]
    public function test_any_other_answer_is_report_undelivered(int $status, string $body): void
    {
        self::assertSame(Code::ReportUndelivered, self::reasonOfTheSync(self::platformAnswering($status, $body)));
    }

    #[DataProvider('bodiesThatAreNotASyncAnswer')]
    public function test_a_200_that_is_not_the_answer_to_a_sync_is_report_undelivered(string $body): void
    {
        self::assertSame(Code::ReportUndelivered, self::reasonOfTheSync(self::platformAnswering(200, $body)));
    }

    public function test_a_timeout_is_report_undelivered_and_carries_what_the_client_threw(): void
    {
        $timeout = HttpException::networkError(HttpRequest::post(self::PLATFORM_URL . '/v1/sync'), 'Operation timed out after 15000 milliseconds');

        $refusal = self::thrownBy(static fn (): mixed => self::shop(new ScriptedHttpClient($timeout))->sync(self::ENDPOINT));

        self::assertSame(Code::ReportUndelivered, $refusal?->reason);
        self::assertSame($timeout, $refusal->getPrevious());
    }

    public function test_a_client_that_throws_something_of_its_own_is_report_undelivered(): void
    {
        $platform = new ScriptedHttpClient(new RuntimeException('Could not resolve host: platform.test'));

        self::assertSame(Code::ReportUndelivered, self::reasonOfTheSync($platform));
    }

    #[DataProvider('refusals')]
    public function test_a_client_that_returns_a_refusal_instead_of_throwing_it_gets_the_same_reason(int $status, string $body, Code $expected): void
    {
        $platform = new ScriptedHttpClient(new HttpResponse($status, $body), throwsStatusErrors: false);

        self::assertSame($expected, self::reasonOfTheSync($platform));
    }

    public function test_the_refusal_tells_the_status_the_platform_answered(): void
    {
        $platform = self::platformAnswering(422, ['code' => 'endpoint_unverified']);

        $refusal = self::thrownBy(static fn (): mixed => self::shop($platform)->sync(self::ENDPOINT));

        self::assertInstanceOf(CloudException::class, $refusal);
        self::assertStringContainsString('422', $refusal->getMessage());
        self::assertStringContainsString('/v1/sync', $refusal->getMessage());
    }

    public function test_the_refusal_names_the_code_the_sync_was_refused_with(): void
    {
        $platform = self::platformAnswering(422, ['code' => 'endpoint_unverified']);

        $refusal = self::thrownBy(static fn (): mixed => self::shop($platform)->sync(self::ENDPOINT));

        self::assertStringContainsString('(endpoint_unverified)', $refusal->getMessage());
    }

    #[DataProvider('unusableAnswers')]
    public function test_no_unusable_answer_is_said_to_have_published_nothing(int $status, string $body): void
    {
        $refusal = self::thrownBy(static fn (): mixed => self::shop(self::platformAnswering($status, $body))->sync(self::ENDPOINT));

        self::assertNotNull($refusal);
        self::assertStringNotContainsString('published nothing', $refusal->getMessage());
    }

    public function test_a_200_that_is_not_the_answer_to_a_sync_says_the_list_may_have_been_published(): void
    {
        $platform = self::platformAnswering(200, ['protocol' => 1, 'modified' => true, 'versions' => 'none', 'stranded' => []]);

        $refusal = self::thrownBy(static fn (): mixed => self::shop($platform)->sync(self::ENDPOINT));

        self::assertStringContainsString('it may have published the list', $refusal->getMessage());
    }

    public function test_a_refusal_does_not_say_the_list_may_have_been_published(): void
    {
        $platform = self::platformAnswering(422, ['code' => 'endpoint_unverified']);

        $refusal = self::thrownBy(static fn (): mixed => self::shop($platform)->sync(self::ENDPOINT));

        self::assertNotNull($refusal);
        self::assertStringNotContainsString('may have published', $refusal->getMessage());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function whetherAnythingChanged(): array
    {
        return ['the list or the endpoint changed' => [true], 'nothing changed' => [false]];
    }

    /**
     * @return array<string, array{string}> bodies of a 200 that sync() accepts, although each lacks something
     *                                      the schema of the answer requires: a list it leaves out counts as empty
     */
    public static function acceptedBodies(): array
    {
        return [
            'no list of versions and none of stranded ones' => ['{"protocol":1,"modified":true}'],
            'null lists' => ['{"protocol":1,"modified":true,"versions":null,"stranded":null}'],
            'no protocol' => ['{"modified":false,"versions":[],"stranded":[]}'],
            'another protocol' => ['{"protocol":2,"modified":true,"versions":[],"stranded":[]}'],
            'a field the SDK does not know' => ['{"protocol":1,"modified":true,"versions":[],"stranded":[],"manifestId":"mf_01"}'],
        ];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function unauthorizedAnswers(): array
    {
        return [
            'a 401 with its code' => [401, '{"code":"unauthorized"}'],
            'a 403 with its code' => [403, '{"code":"unauthorized"}'],
            'a 401 of a proxy' => [401, '<html><body>401 Authorization Required</body></html>'],
            'a 403 with no body' => [403, ''],
            'a 401 that carries another code' => [401, '{"code":"endpoint_unverified"}'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function clientErrors(): array
    {
        return ['a 400' => [400], 'a 426' => [426]];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function answersThatPublishedNothing(): array
    {
        return [
            'a 500' => [500, '{"code":"internal_error"}'],
            'a 503' => [503, ''],
            'a 502 of a proxy' => [502, '<html><body>Bad Gateway</body></html>'],
            'a 429' => [429, '{"code":"rate_limited"}'],
            'a 404' => [404, '{"code":null}'],
            'a 302, which the default client does not follow' => [302, ''],
            'a 422 with no code' => [422, '{}'],
            'a 422 with a code the SDK does not know' => [422, '{"code":"endpoint_unreachable"}'],
            'a 400 with no code' => [400, 'Bad Request'],
            'a 500 that carries a final code' => [500, '{"code":"invalid_request"}'],
            'a 202' => [202, '{"protocol":1,"modified":true,"versions":[],"stranded":[]}'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNotASyncAnswer(): array
    {
        return [
            'the answer to an announcement' => ['{"recorded":true}'],
            'no body' => [''],
            'text' => ['OK'],
            'the page of a proxy' => ['<html><body>Welcome</body></html>'],
            'a JSON list' => ['[]'],
            'a JSON null' => ['null'],
            'no "modified"' => ['{"protocol":1,"versions":[],"stranded":[]}'],
            '"modified" as a string' => ['{"protocol":1,"modified":"true","versions":[],"stranded":[]}'],
            '"modified" as a number' => ['{"protocol":1,"modified":1,"versions":[],"stranded":[]}'],
            '"modified" as null' => ['{"protocol":1,"modified":null,"versions":[],"stranded":[]}'],
            'a refusal sent with a 200' => ['{"code":"invalid_request"}'],
            '"versions" as a string' => ['{"protocol":1,"modified":true,"versions":"none","stranded":[]}'],
            '"stranded" as an object of entries' => ['{"protocol":1,"modified":true,"versions":[],"stranded":{"first":{"definition":"trial","version":3,"openRuns":2}}}'],
            'an entry that is not an object' => ['{"protocol":1,"modified":true,"versions":["order-fulfilment"],"stranded":[]}'],
            'an entry without a definition' => ['{"protocol":1,"modified":true,"versions":[{"version":1,"openRuns":0}],"stranded":[]}'],
            'an entry whose definition is no name of a definition' => ['{"protocol":1,"modified":true,"versions":[],"stranded":[{"definition":"Trial run","version":3,"openRuns":2}]}'],
            'an entry without a version' => ['{"protocol":1,"modified":true,"versions":[],"stranded":[{"definition":"trial","openRuns":2}]}'],
            'a version as a string' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":"1","openRuns":0}],"stranded":[]}'],
            'a version written with a fraction' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":1.0,"openRuns":0}],"stranded":[]}'],
            'the version 0' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":0,"openRuns":0}],"stranded":[]}'],
            'an entry without its open runs' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":1}],"stranded":[]}'],
            'open runs written with a fraction' => ['{"protocol":1,"modified":true,"versions":[{"definition":"order-fulfilment","version":1,"openRuns":0.0}],"stranded":[]}'],
            'open runs below 0' => ['{"protocol":1,"modified":true,"versions":[],"stranded":[{"definition":"trial","version":3,"openRuns":-2}]}'],
        ];
    }

    /**
     * @return array<string, array{int, string}> answers that are neither a refusal for good nor the answer to a sync
     */
    public static function unusableAnswers(): array
    {
        return [
            'a 200 with another body' => [200, '{"recorded":true}'],
            'a 200 whose lists cannot be read' => [200, '{"protocol":1,"modified":true,"versions":"none","stranded":[]}'],
            'a 202' => [202, '{"protocol":1,"modified":true,"versions":[],"stranded":[]}'],
            'a 503' => [503, ''],
        ];
    }

    /**
     * @return array<string, array{int, string, Code}>
     */
    public static function refusals(): array
    {
        return [
            'a 422 endpoint_unverified' => [422, '{"code":"endpoint_unverified"}', Code::EndpointUnverified],
            'a 401' => [401, '{"code":"unauthorized"}', Code::Unauthorized],
            'a 400 invalid_request' => [400, '{"code":"invalid_request"}', Code::InvalidRequest],
            'a 400 unsupported_protocol' => [400, '{"code":"unsupported_protocol"}', Code::UnsupportedProtocol],
            'a 500' => [500, '', Code::ReportUndelivered],
        ];
    }

    /**
     * @return Code|null the reason sync() threw; null when it returned a result
     */
    protected static function reasonOfTheSync(ScriptedHttpClient $platform): ?Code
    {
        try {
            self::shop($platform)->sync(self::ENDPOINT);
        } catch (CloudException $refusal) {
            return $refusal->reason;
        } catch (Throwable $other) {
            self::fail('sync() let out something else than a CloudException: ' . $other::class);
        }

        return null;
    }
}
