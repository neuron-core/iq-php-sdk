<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_replace;
use function time;

use const PHP_INT_MAX;

/**
 * The use-by time of a start: an integer of Unix seconds, no more than 3600
 * seconds plus the signature window ahead of the endpoint's clock. A time that
 * has passed is the runner's to refuse, with a report.
 */
class UseByTimeTest extends RequestTestCase
{
    /** The longest life of a start, plus the seconds the two clocks may differ by. */
    protected const FARTHEST = 3600 + 300;

    #[DataProvider('useByTimesTheEndpointTakes')]
    public function test_a_start_to_be_used_within_the_hour_is_queued(string $backend, int $secondsFromNow): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->start(['expiresAt' => time() + $secondsFromNow]));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('useByTimesTooFarAhead')]
    public function test_a_start_to_be_used_later_than_the_hour_and_the_signature_window_is_answered_400_invalid_request_and_does_nothing(
        string $backend,
        int $secondsFromNow,
    ): void {
        $answer = $this->atOneInstant(function (int $now) use ($backend, $secondsFromNow): HttpResponse {
            $this->shop($backend);

            return $this->deliver($this->start(['expiresAt' => $now + $secondsFromNow]));
        });

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_use_by_time_has_passed_is_queued(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->start(['expiresAt' => time() - 1]));

        self::assertSame([202, 1], [$answer->statusCode, $this->done()['messages queued']]);
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_use_by_time_has_passed_is_rejected_as_expired_by_the_worker_and_runs_nothing(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start(['expiresAt' => time() - 1]));

        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::Expired->value, null], [$report->outcome, $report->code, $report->claimed]);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_use_by_time_is_the_epoch_is_queued(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->start(['expiresAt' => 0]));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('useByTimesThatAreNoInstant')]
    public function test_a_start_whose_use_by_time_is_no_integer_of_unix_seconds_is_answered_400_invalid_request_and_does_nothing(string $backend, string $json): void
    {
        $this->shop($backend);
        $body = str_replace('"expiresAt":0', '"expiresAt":' . $json, self::encode($this->start(['expiresAt' => 0])));

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function useByTimesTheEndpointTakes(): array
    {
        return self::onEveryBackend([
            'now' => [0],
            'in 600 seconds, the least the platform gives' => [600],
            'in 3600 seconds, the most the platform gives' => [3600],
            'in 3601 seconds, by a clock that runs a second ahead' => [3601],
            'in 3600 seconds, by a clock that runs 300 seconds ahead' => [self::FARTHEST],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function useByTimesTooFarAhead(): array
    {
        return self::onEveryBackend([
            'one second past the bound' => [self::FARTHEST + 1],
            'in two hours' => [7200],
            'in a day' => [86400],
            'at the end of time' => [PHP_INT_MAX - 2_000_000_000],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the JSON of the value
     */
    public static function useByTimesThatAreNoInstant(): array
    {
        return self::onEveryBackend([
            'a whole decimal' => ['1791368100.0'],
            'a decimal' => ['1791368100.5'],
            'an exponent' => ['1.7913681e9'],
            'a negative integer' => ['-1'],
            'a negative zero' => ['-0.0'],
            'an integer too large for 64 bits' => ['9223372036854775808'],
            'a number in a string' => ['"1791368100"'],
            'a date in a string' => ['"2026-10-07T12:00:00Z"'],
            'an empty string' => ['""'],
            'true' => ['true'],
            'a list' => ['[1791368100]'],
            'an object' => ['{"at":1791368100}'],
        ]);
    }
}
