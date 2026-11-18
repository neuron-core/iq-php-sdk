<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function json_encode;
use function str_repeat;
use function strlen;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Step 3 of the endpoint, before any field is read: what a signed body must
 * be to be taken for a message. Every body here carries a valid signature.
 */
class BodyTest extends RequestTestCase
{
    protected const FIVE_MEGABYTES = 5 * 1024 * 1024;

    /** Bytes: the longest body the endpoint decodes. The largest valid message of protocol 1 is under 4 KB. */
    protected const LONGEST_BODY = 65536;

    #[DataProvider('bodiesThatAreNoJsonObject')]
    public function test_a_signed_body_that_is_no_json_object_is_answered_400_invalid_request_and_does_nothing(string $backend, string $body): void
    {
        $this->shop($backend);

        $answer = $this->post($body);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_an_empty_object_is_answered_400_unsupported_protocol(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post('{}');

        self::assertSame(self::UNSUPPORTED_PROTOCOL, self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_white_space_around_the_object_is_part_of_the_json(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post(" \r\n\t" . self::PING . "\n");

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_field_whose_name_begins_with_a_nul_is_ignored_like_any_field_the_protocol_does_not_know(string $backend): void
    {
        $this->shop($backend);
        $body = '{"protocol":1,"verb":"ping","\u0000trace":1,"trigger":[{"\u0000":null}]}';

        // Handed over beside post(): the schema check that follows every exchange needs the message as a PHP
        // object, and PHP has no object with such a property.
        $answer = $this->iq->handle('POST', $this->signed($body), $body);

        self::assertSame([200, '{"protocols":[1]}'], self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_field_the_protocol_does_not_know_is_ignored(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->start(['traceId' => '4bf92f3577b34da6', 'trigger' => ['schedule' => 'nightly']]));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_an_unknown_field_nested_a_hundred_levels_deep_is_ignored(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post('{"protocol":1,"verb":"ping","trigger":' . str_repeat('[', 100) . str_repeat(']', 100) . '}');

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('bodiesNestedTooDeepToDecode')]
    public function test_a_body_nested_deeper_than_php_decodes_is_answered_400_invalid_request(string $backend, string $body): void
    {
        $this->shop($backend);

        $answer = $this->post($body);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('bodiesThatAreNotUtf8')]
    public function test_a_body_that_is_not_utf8_is_answered_400_invalid_request_and_does_nothing(string $backend, string $body): void
    {
        $this->shop($backend);

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('validMessagesLongerThanTheEndpointDecodes')]
    public function test_a_valid_message_longer_than_65536_bytes_is_answered_400_invalid_request_and_does_nothing(
        string $backend,
        string $verb,
        int $length,
    ): void {
        $this->shop($backend);
        $body = $this->messageOf($verb, $length);

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_body_longer_than_65536_bytes_is_refused_for_its_length_before_it_is_decoded(string $backend): void
    {
        $this->shop($backend);
        $body = '{"protocol":2,"verb":"ping","padding":"' . str_repeat('a', 65_536) . '"}';

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer), 'Decoded, this body would be unsupported_protocol.');
    }

    #[DataProvider('backends')]
    public function test_a_ping_of_65536_bytes_is_answered(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post($this->messageOf('ping', self::LONGEST_BODY));

        self::assertSame([200, '{"protocols":[1]}'], self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_start_of_65536_bytes_is_queued(string $backend): void
    {
        $this->shop($backend);
        $body = $this->messageOf('start', self::LONGEST_BODY);

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_five_megabytes_that_are_no_json_are_answered_400_invalid_request(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post(str_repeat('a', self::FIVE_MEGABYTES));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
    }

    #[DataProvider('fieldsOfAStart')]
    public function test_a_start_with_a_field_of_five_megabytes_is_answered_400_invalid_request_and_does_nothing(string $backend, string $field): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->start([$field => str_repeat('a', self::FIVE_MEGABYTES)]));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_the_last_of_two_protocol_fields_is_the_one_read(string $backend): void
    {
        $this->shop($backend);

        $answers = [$this->post('{"protocol":2,"protocol":1,"verb":"ping"}'), $this->post('{"protocol":1,"protocol":2,"verb":"ping"}')];

        self::assertSame([[200, '{"protocols":[1]}'], self::UNSUPPORTED_PROTOCOL], array_map(self::statusAndBody(...), $answers));
    }

    #[DataProvider('backends')]
    public function test_the_last_of_two_verbs_is_the_one_obeyed(string $backend): void
    {
        $this->shop($backend);
        $body = '{"protocol":1,"verb":"start","invocationId":"inv_start","definition":"order-fulfilment","workflowId":"order:123",'
            . '"runId":"1-0192f3a8","expiresAt":' . (time() + 900) . ',"verb":"ping"}';

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame([200, '{"protocols":[1]}'], self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_the_last_of_two_run_ids_is_the_one_queued(string $backend): void
    {
        $this->shop($backend);
        $body = '{"protocol":1,"verb":"start","invocationId":"inv_start","definition":"order-fulfilment","workflowId":"order:123",'
            . '"runId":"1-the-first","runId":"1-the-last","expiresAt":' . (time() + 900) . '}';

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(202, $answer->statusCode);
        self::assertSame(['1-the-last'], array_map(static fn (Invocation $queued): string => $queued->runId, $this->queue->dispatched()));
    }

    /**
     * @return array<string, array<int, mixed>> the verb of a message that is valid but for its length, and its length
     */
    public static function validMessagesLongerThanTheEndpointDecodes(): array
    {
        return self::onEveryBackend([
            'a ping one byte too long' => ['ping', self::LONGEST_BODY + 1],
            'a start one byte too long' => ['start', self::LONGEST_BODY + 1],
            'a ping of five megabytes' => ['ping', self::FIVE_MEGABYTES],
        ]);
    }

    /**
     * A valid ping or start of exactly that many bytes: a field the protocol does not know fills it up.
     */
    protected function messageOf(string $verb, int $length): string
    {
        $message = ($verb === 'start' ? $this->start() : ['verb' => $verb]) + ['protocol' => 1];
        $unpadded = json_encode($message + ['padding' => ''], JSON_THROW_ON_ERROR);
        $body = json_encode($message + ['padding' => str_repeat('a', $length - strlen($unpadded))], JSON_THROW_ON_ERROR);

        self::assertSame($length, strlen($body));

        return $body;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function bodiesThatAreNoJsonObject(): array
    {
        return self::onEveryBackend([
            'no body' => [''],
            'white space' => [" \n"],
            'JSON cut short' => ['{"protocol": 1, "verb":'],
            'a form' => ['protocol=1&verb=ping'],
            'XML' => ['<ping protocol="1"/>'],
            'an object followed by something' => [self::PING . ' ping'],
            'two objects' => [self::PING . self::PING],
            'an object after a byte order mark' => ["\xEF\xBB\xBF" . self::PING],
            'an object in single quotes' => ["{'protocol':1,'verb':'ping'}"],
            'an object with a trailing comma' => ['{"protocol":1,"verb":"ping",}'],
            'an object with a comment' => ['{"protocol":1,/* */"verb":"ping"}'],
            'a compressed body' => ["\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x03\xAB\x56\x2A\x28\xCA\x2F\xC9\x4F\xCE\xCF\x51\xB2\x32\xAC\x05\x00"],
            'four thousand NULs' => [str_repeat("\u{0}", 4096)],
            'the number 1' => ['1'],
            'a decimal' => ['1.5'],
            'a string' => ['"ping"'],
            'a string that holds a message' => ['"{\"protocol\":1,\"verb\":\"ping\"}"'],
            'true' => ['true'],
            'null' => ['null'],
            'an empty list' => ['[]'],
            'a list of one message' => ['[' . self::PING . ']'],
            'a list of the fields of a message' => ['[1,"ping"]'],
            'a list of lists' => ['[[],[]]'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> bodies short enough to be decoded: none is longer than 65,536 bytes
     */
    public static function bodiesNestedTooDeepToDecode(): array
    {
        return self::onEveryBackend([
            'a ping with an unknown field nested 1,000 levels deep' => ['{"protocol":1,"verb":"ping","trigger":' . str_repeat('[', 1000) . str_repeat(']', 1000) . '}'],
            'a ping with an unknown field nested 10,000 objects deep' => ['{"protocol":1,"verb":"ping","trigger":' . str_repeat('{"a":', 10_000) . '1' . str_repeat('}', 10_000) . '}'],
            'sixty thousand lists that never close' => [str_repeat('[', 60_000)],
            'ten thousand objects that never close' => [str_repeat('{"a":', 10_000)],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function bodiesThatAreNotUtf8(): array
    {
        $start = '"invocationId":"inv_start","definition":"order-fulfilment","runId":"1-0192f3a8","expiresAt":1,';

        return self::onEveryBackend([
            'a byte that is no UTF-8 in a field of the verb' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"order:\xFF\"}"],
            'a byte that is no UTF-8 in a field the protocol does not know' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"order:123\",\"note\":\"\xFF\"}"],
            'a byte that is no UTF-8 in the name of a field' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"order:123\",\"\xC3\x28\":1}"],
            'a character cut short' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"order:\xE2\x82\"}"],
            'an overlong encoding of a slash' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"order\xC0\xAF123\"}"],
            'half of a surrogate pair, escaped' => ['{"protocol":1,"verb":"start",' . $start . '"workflowId":"order:\ud83d"}'],
            'Latin-1' => ['{"protocol":1,"verb":"start",' . $start . "\"workflowId\":\"caf\xE9\"}"],
            'UTF-16' => ["\xFF\xFE{\x00\"\x00p\x00\"\x00:\x001\x00}\x00"],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fieldsOfAStart(): array
    {
        return self::onEveryBackend([
            'definition' => ['definition'],
            'workflowId' => ['workflowId'],
            'runId' => ['runId'],
            'invocationId' => ['invocationId'],
        ]);
    }
}
