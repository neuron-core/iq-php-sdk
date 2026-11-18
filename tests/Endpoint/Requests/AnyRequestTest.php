<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use Closure;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function array_fill;
use function array_values;
use function str_repeat;

/**
 * "Nothing leaves handle()": whatever a caller that does not hold the signing
 * key hands it, handle() returns an answer, and that answer is a refusal.
 */
class AnyRequestTest extends RequestTestCase
{
    /**
     * @param Closure(array<string, string>): array<array-key, mixed> $headers takes the headers of a signed ping
     */
    #[DataProvider('requestsOfAStranger')]
    public function test_a_request_no_platform_would_send_is_refused_with_a_status_of_the_table(string $backend, string $method, Closure $headers): void
    {
        $this->shop($backend);

        $answer = $this->post(self::PING, $headers($this->signed(self::PING)), $method);

        self::assertContains($answer->statusCode, [401, 405]);
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the method, what becomes of the headers of a signed ping
     */
    public static function requestsOfAStranger(): array
    {
        $asSigned = static fn (array $headers): array => $headers;

        return self::onEveryBackend([
            'a method that holds a NUL' => ["PO\u{0}ST", $asSigned],
            'a method of a megabyte' => [str_repeat('POST', 262_144), $asSigned],
            'a method that is not text' => ["\xFF\xFE", $asSigned],
            'headers under numbers' => ['POST', array_values(...)],
            'headers under an empty name' => ['POST', static fn (array $headers): array => ['' => $headers['webhook-signature']]],
            'headers that hold objects' => [
                'POST',
                static fn (array $headers): array => ['webhook-id' => new stdClass(), 'webhook-timestamp' => static fn (): string => 'now', 'webhook-signature' => [new stdClass()]],
            ],
            'a signature header of 100,000 spaces' => [
                'POST',
                static fn (array $headers): array => ['webhook-signature' => str_repeat(' ', 100_000)] + $headers,
            ],
            'a signature header of 10,000 values' => [
                'POST',
                static fn (array $headers): array => ['webhook-signature' => array_fill(0, 10_000, 'v1,forged')] + $headers,
            ],
            'a signature header of a megabyte' => [
                'POST',
                static fn (array $headers): array => ['webhook-signature' => 'v1,' . str_repeat('A', 1_048_576)] + $headers,
            ],
            'a signature that is not text' => [
                'POST',
                static fn (array $headers): array => ['webhook-signature' => "v1,\xFF\xFE\u{0}"] + $headers,
            ],
            'a webhook-id that holds line feeds and a NUL' => [
                'POST',
                static fn (array $headers): array => ['webhook-id' => "msg\r\nX-Injected: 1\u{0}"] + $headers,
            ],
        ]);
    }
}
