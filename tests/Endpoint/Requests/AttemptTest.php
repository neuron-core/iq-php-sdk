<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function str_replace;

use const PHP_INT_MAX;

/**
 * The attempt that fences a wake, and an abandon when the run exists: an
 * integer from 1. A wake always carries one.
 */
class AttemptTest extends RequestTestCase
{
    #[DataProvider('attemptsOfARun')]
    public function test_a_wake_is_queued_with_the_attempt_it_carries(string $backend, int $attempt): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->wake($attempt));

        self::assertSame(202, $answer->statusCode);
        self::assertSame([$attempt], array_map(static fn (Invocation $queued): ?int => $queued->attempt, $this->queue->dispatched()));
    }

    #[DataProvider('backends')]
    public function test_a_wake_without_an_attempt_is_answered_400_invalid_request_and_does_nothing(string $backend): void
    {
        $this->shop($backend);
        $wake = $this->wake(attempt: 1);
        unset($wake['attempt']);

        $answer = $this->deliver($wake);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('attemptsThatAreNoAttempt')]
    public function test_a_wake_whose_attempt_is_no_integer_from_1_is_answered_400_invalid_request_and_does_nothing(string $backend, string $json): void
    {
        $this->shop($backend);
        $body = str_replace('"attempt":1', '"attempt":' . $json, self::encode($this->wake(attempt: 1)));

        $answer = $this->post($body, $this->signed($body, 'inv_wake_1'));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('attemptsOfARun')]
    public function test_an_abandon_with_an_attempt_is_obeyed_when_no_run_holds_the_workflow_id(string $backend, int $attempt): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->abandon(attempt: $attempt));

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('attemptsThatAreNoAttempt')]
    public function test_an_abandon_whose_attempt_is_no_integer_from_1_is_answered_400_invalid_request_and_does_nothing(string $backend, string $json): void
    {
        $this->shop($backend);
        $body = str_replace('"attempt":1', '"attempt":' . $json, self::encode($this->abandon(attempt: 1)));

        $answer = $this->post($body);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function attemptsOfARun(): array
    {
        return self::onEveryBackend([
            'attempt 1' => [1],
            'attempt 2' => [2],
            'the largest integer' => [PHP_INT_MAX],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the JSON of the value
     */
    public static function attemptsThatAreNoAttempt(): array
    {
        return self::onEveryBackend([
            'attempt 0' => ['0'],
            'a negative attempt' => ['-1'],
            'a whole decimal' => ['1.0'],
            'a decimal' => ['1.5'],
            'an exponent' => ['1e0'],
            'an integer too large for 64 bits' => ['9223372036854775808'],
            'a number in a string' => ['"1"'],
            'an empty string' => ['""'],
            'true' => ['true'],
            'null' => ['null'],
            'a list' => ['[1]'],
            'an object' => ['{"attempt":1}'],
        ]);
    }
}
