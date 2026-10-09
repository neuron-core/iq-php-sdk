<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_replace;

/**
 * "Every request body carries "protocol": 1": anything else is answered 400
 * unsupported_protocol, before the verb is looked at.
 */
class ProtocolFieldTest extends RequestTestCase
{
    #[DataProvider('protocolsThatAreNotTheInteger1')]
    public function test_a_start_whose_protocol_is_not_the_integer_1_is_answered_400_unsupported_protocol_and_does_nothing(string $backend, string $json): void
    {
        $this->shop($backend);
        $body = str_replace('"protocol":1', $json, self::encode($this->start()));

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::UNSUPPORTED_PROTOCOL, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_start_without_a_protocol_is_answered_400_unsupported_protocol_and_does_nothing(string $backend): void
    {
        $this->shop($backend);
        $body = str_replace(',"protocol":1', '', self::encode($this->start()));

        $answer = $this->post($body, $this->signed($body, 'inv_start'));

        self::assertSame(self::UNSUPPORTED_PROTOCOL, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_another_protocol_is_refused_before_its_verb_is_looked_at(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post('{"protocol":2,"verb":"retire"}');

        self::assertSame(self::UNSUPPORTED_PROTOCOL, self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_list_of_protocols_as_a_sync_carries_it_is_not_the_protocol_of_a_message(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post('{"protocols":[1],"verb":"ping"}');

        self::assertSame(self::UNSUPPORTED_PROTOCOL, self::statusAndBody($answer));
    }

    /**
     * @return array<string, array<int, mixed>> what stands in the body where "protocol":1 stood
     */
    public static function protocolsThatAreNotTheInteger1(): array
    {
        return self::onEveryBackend([
            'protocol 2' => ['"protocol":2'],
            'protocol 0' => ['"protocol":0'],
            'protocol -1' => ['"protocol":-1'],
            'protocol 11' => ['"protocol":11'],
            'the string "1"' => ['"protocol":"1"'],
            'the decimal 1.0' => ['"protocol":1.0'],
            'the exponent 1e0' => ['"protocol":1e0'],
            'the decimal 1.5' => ['"protocol":1.5'],
            'true' => ['"protocol":true'],
            'null' => ['"protocol":null'],
            'a list that holds 1' => ['"protocol":[1]'],
            'an object' => ['"protocol":{"version":1}'],
            'a name in another case' => ['"Protocol":1'],
        ]);
    }
}
