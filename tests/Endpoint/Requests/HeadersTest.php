<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use Closure;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_combine;
use function array_keys;
use function array_map;
use function strtoupper;
use function ucwords;

/**
 * The two shapes handle() takes its headers in: name => value, as
 * getallheaders() gives them, and name => list of values, as a framework
 * does. Names are read in any case.
 */
class HeadersTest extends RequestTestCase
{
    #[DataProvider('backends')]
    public function test_headers_given_as_strings_are_read_among_the_other_headers_of_the_request(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post(self::PING, ['Host' => 'shop.example.com', 'Content-Type' => 'application/json'] + $this->signed(self::PING) + ['Content-Length' => '28']);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_headers_given_as_lists_of_values_are_read(string $backend): void
    {
        $this->shop($backend);
        $lists = array_map(static fn (string $value): array => [$value], $this->signed(self::PING));

        $answer = $this->post(self::PING, ['host' => ['shop.example.com'], 'content-type' => ['application/json']] + $lists);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_headers_are_lists_of_values_is_queued_under_its_webhook_id(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, array_map(static fn (string $value): array => [$value], $this->signed($body, 'inv_start')));

        self::assertSame(202, $answer->statusCode);
        self::assertSame(['inv_start'], array_map(static fn (Invocation $queued): string => $queued->invocationId, $this->queue->dispatched()));
    }

    #[DataProvider('backends')]
    public function test_headers_given_in_both_shapes_at_once_are_read(string $backend): void
    {
        $this->shop($backend);
        $headers = $this->signed(self::PING);

        $answer = $this->post(self::PING, ['webhook-signature' => [$headers['webhook-signature']]] + $headers);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('casesOfAName')]
    public function test_the_names_of_the_headers_are_read_in_any_case(string $backend, Closure $rename): void
    {
        $this->shop($backend);
        $headers = $this->signed(self::PING);

        $answer = $this->post(self::PING, array_combine(array_map($rename, array_keys($headers)), $headers));

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('valuesThatAreNoHeader')]
    public function test_a_header_whose_value_holds_no_string_is_no_header(string $backend, string $name, mixed $value): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, [$name => $value] + $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_webhook_id_given_as_several_values_is_not_the_id_that_was_signed(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, ['webhook-id' => ['inv_start', 'inv_start']] + $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_valid_signature_folded_before_another_with_a_comma_is_not_recognised(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, 'inv_start');
        $another = self::signature('a key retired last month', 'inv_start', $headers['webhook-timestamp'], $body);

        $answer = $this->post($body, ['webhook-signature' => "{$headers['webhook-signature']}, {$another}"] + $headers);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function casesOfAName(): array
    {
        return self::onEveryBackend([
            'lower case, as HTTP/2 and Symfony give them' => [static fn (string $name): string => $name],
            'capitals after each hyphen, as getallheaders() gives them' => [static fn (string $name): string => ucwords($name, '-')],
            'upper case' => [strtoupper(...)],
            'the first letter in upper case' => [ucwords(...)],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function valuesThatAreNoHeader(): array
    {
        $cases = [];

        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $cases += [
                "a {$name} that is null" => [$name, null],
                "a {$name} that is a number" => [$name, 1791453603],
                "a {$name} that is true" => [$name, true],
                "a {$name} that is an empty list" => [$name, []],
                "a {$name} that is a list of lists" => [$name, [['v1,forged']]],
            ];
        }

        return self::onEveryBackend($cases);
    }
}
