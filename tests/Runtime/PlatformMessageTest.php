<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Runtime\PlatformMessage;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class PlatformMessageTest extends TestCase
{
    protected const NOW = 1791453603;

    protected const WEBHOOK_ID = 'inv_01JB7K4T9QW2';

    protected const START = [
        'protocol' => 1,
        'verb' => 'start',
        'invocationId' => self::WEBHOOK_ID,
        'definition' => 'nightly-reconciliation',
        'workflowId' => 'reconciliation:2026-10-07',
        'runId' => '1-01JB2M8Q3W',
        'expiresAt' => self::NOW + 900,
    ];

    protected const WAKE = [
        'protocol' => 1,
        'verb' => 'wake',
        'invocationId' => self::WEBHOOK_ID,
        'definition' => 'order-fulfilment',
        'workflowId' => 'order:123',
        'runId' => '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
        'attempt' => 1,
    ];

    protected const INSPECT = ['protocol' => 1, 'verb' => 'inspect', 'definition' => 'order-fulfilment', 'workflowId' => 'order:123'];

    protected const ABANDON = [
        'protocol' => 1,
        'verb' => 'abandon',
        'definition' => 'order-fulfilment',
        'workflowId' => 'order:123',
        'runId' => '2-0192f3a8-7c1e',
    ];

    public function test_a_start_is_decoded_with_its_run_id_and_its_use_by_time(): void
    {
        $start = self::decoded(self::START);

        self::assertSame('start', $start->verb);
        self::assertSame(
            [self::WEBHOOK_ID, 'nightly-reconciliation', 'reconciliation:2026-10-07', '1-01JB2M8Q3W', self::NOW + 900],
            [$start->invocationId, $start->definition, $start->workflowId, $start->runId, $start->expiresAt],
        );
    }

    public function test_a_wake_is_decoded_with_its_fence(): void
    {
        $wake = self::decoded(self::WAKE);

        self::assertSame('wake', $wake->verb);
        self::assertSame(['2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12', 1], [$wake->runId, $wake->attempt]);
    }

    public function test_an_inspect_may_name_no_run(): void
    {
        $inspect = self::decoded(self::INSPECT);

        self::assertSame(['inspect', 'order-fulfilment', 'order:123', null], [$inspect->verb, $inspect->definition, $inspect->workflowId, $inspect->runId]);
    }

    public function test_an_inspect_may_name_a_run_started_outside_the_sdk(): void
    {
        self::assertSame('nightly', self::decoded(['runId' => 'nightly'] + self::INSPECT)->runId);
    }

    public function test_an_abandon_may_carry_no_attempt(): void
    {
        $abandon = self::decoded(self::ABANDON);

        self::assertSame(['abandon', '2-0192f3a8-7c1e', null], [$abandon->verb, $abandon->runId, $abandon->attempt]);
    }

    public function test_an_abandon_carries_the_attempt_when_the_run_exists(): void
    {
        self::assertSame(2, self::decoded(['attempt' => 2] + self::ABANDON)->attempt);
    }

    public function test_a_ping_carries_nothing(): void
    {
        $ping = self::decoded(['protocol' => 1, 'verb' => 'ping']);

        self::assertSame('ping', $ping->verb);
        self::assertNull($ping->definition);
    }

    public function test_a_field_the_receiver_does_not_know_is_ignored(): void
    {
        self::assertSame('wake', self::decoded(self::WAKE + ['aFieldOfALaterProtocol' => ['any' => 'value']])->verb);
    }

    public function test_only_the_fields_of_the_verb_are_read(): void
    {
        $ping = self::decoded(['protocol' => 1, 'verb' => 'ping', 'definition' => ['not', 'a', 'name'], 'runId' => 7]);
        $inspect = self::decoded(self::INSPECT + ['attempt' => 'two', 'expiresAt' => 'never', 'invocationId' => 9]);

        self::assertNull($ping->runId);
        self::assertSame([null, null, null], [$inspect->attempt, $inspect->expiresAt, $inspect->invocationId]);
    }

    #[DataProvider('bodiesThatAreNoJsonObject')]
    public function test_a_body_that_is_no_json_object_is_an_invalid_request(string $body): void
    {
        self::assertSame(Code::InvalidRequest, PlatformMessage::decode($body, self::WEBHOOK_ID, self::NOW));
    }

    #[DataProvider('otherProtocols')]
    public function test_a_body_that_does_not_say_protocol_1_is_an_unsupported_protocol(mixed $protocol): void
    {
        self::assertSame(Code::UnsupportedProtocol, self::refusalOf(['protocol' => $protocol] + self::WAKE));
    }

    public function test_a_body_without_a_protocol_is_an_unsupported_protocol(): void
    {
        self::assertSame(Code::UnsupportedProtocol, self::refusalOf(['verb' => 'ping']));
    }

    #[DataProvider('verbsThePlatformNeverSends')]
    public function test_a_reserved_or_unknown_verb_is_an_invalid_request(mixed $verb): void
    {
        self::assertSame(Code::InvalidRequest, self::refusalOf(['verb' => $verb] + self::WAKE));
    }

    public function test_a_message_without_a_verb_is_an_invalid_request(): void
    {
        self::assertSame(Code::InvalidRequest, self::refusalOf(['protocol' => 1]));
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('brokenFields')]
    public function test_a_field_that_breaks_its_format_is_an_invalid_request(array $message): void
    {
        self::assertSame(Code::InvalidRequest, self::refusalOf($message));
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('missingFields')]
    public function test_a_message_without_a_field_of_its_verb_is_an_invalid_request(array $message, string $field): void
    {
        unset($message[$field]);

        self::assertSame(Code::InvalidRequest, self::refusalOf($message));
    }

    public function test_the_invocation_id_of_a_start_must_be_the_webhook_id_that_was_signed(): void
    {
        $refusal = PlatformMessage::decode(json_encode(self::START, JSON_THROW_ON_ERROR), 'inv_another', self::NOW);

        self::assertSame(Code::InvalidRequest, $refusal);
    }

    public function test_the_invocation_id_of_a_wake_must_be_the_webhook_id_that_was_signed(): void
    {
        $refusal = PlatformMessage::decode(json_encode(self::WAKE, JSON_THROW_ON_ERROR), 'inv_another', self::NOW);

        self::assertSame(Code::InvalidRequest, $refusal);
    }

    public function test_a_start_may_be_used_for_an_hour_plus_the_signature_window(): void
    {
        self::assertSame(self::NOW + 3900, self::decoded(['expiresAt' => self::NOW + 3900] + self::START)->expiresAt);
    }

    public function test_a_start_that_could_be_used_for_longer_is_an_invalid_request(): void
    {
        self::assertSame(Code::InvalidRequest, self::refusalOf(['expiresAt' => self::NOW + 3901] + self::START));
    }

    public function test_a_start_whose_use_by_time_has_passed_is_left_to_the_runner_to_refuse(): void
    {
        self::assertSame(self::NOW - 60, self::decoded(['expiresAt' => self::NOW - 60] + self::START)->expiresAt);
    }

    public function test_a_start_and_a_wake_are_queued_and_the_rest_is_answered_in_the_request(): void
    {
        self::assertTrue(self::decoded(self::START)->runsInQueue());
        self::assertTrue(self::decoded(self::WAKE)->runsInQueue());
        self::assertFalse(self::decoded(self::INSPECT)->runsInQueue());
        self::assertFalse(self::decoded(self::ABANDON)->runsInQueue());
        self::assertFalse(self::decoded(['protocol' => 1, 'verb' => 'ping'])->runsInQueue());
    }

    public function test_the_invocation_of_a_start_is_built_field_by_field_with_the_origin_platform(): void
    {
        $invocation = self::decoded(self::START)->invocation();

        self::assertSame(
            [
                'invocationId' => self::WEBHOOK_ID,
                'verb' => 'start',
                'origin' => Origin::PLATFORM,
                'definition' => 'nightly-reconciliation',
                'workflowId' => 'reconciliation:2026-10-07',
                'runId' => '1-01JB2M8Q3W',
                'attempt' => null,
                'expiresAt' => self::NOW + 900,
                'signal' => null,
                'payload' => null,
                'event' => null,
                'body' => null,
            ],
            $invocation->toArray(),
        );
    }

    public function test_the_invocation_of_a_wake_carries_its_fence_and_no_use_by_time(): void
    {
        $invocation = self::decoded(self::WAKE)->invocation();

        self::assertSame(Verb::Wake, $invocation->verb);
        self::assertSame(['2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12', 1, null], [$invocation->runId, $invocation->attempt, $invocation->expiresAt]);
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('queuedMessages')]
    public function test_what_exists_only_in_the_queue_is_never_read_from_the_wire(array $message): void
    {
        $forged = [
            'origin' => Origin::APPLICATION,
            'signal' => 'payment.received',
            'payload' => ['amount' => 49.9],
            'event' => 'TzoxOiJYIjowOnt9',
            'body' => ['outcome' => 'completed'],
            'input' => ['orderId' => '123'],
        ];

        $invocation = self::decoded($forged + $message)->invocation();

        self::assertSame(Origin::PLATFORM, $invocation->origin);
        self::assertSame([null, null, null, null], [$invocation->signal, $invocation->payload, $invocation->event, $invocation->body]);
    }

    public function test_a_start_reads_no_attempt_from_the_wire(): void
    {
        self::assertNull(self::decoded(['attempt' => 4] + self::START)->invocation()->attempt);
    }

    public function test_a_wake_reads_no_use_by_time_from_the_wire(): void
    {
        self::assertNull(self::decoded(['expiresAt' => self::NOW + 900] + self::WAKE)->invocation()->expiresAt);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNoJsonObject(): array
    {
        return [
            'an empty body' => [''],
            'a body that is not JSON' => ['{"protocol": 1, "verb": "ping"'],
            'a JSON string' => ['"ping"'],
            'a JSON number' => ['1'],
            'a JSON null' => ['null'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function otherProtocols(): array
    {
        return [
            'protocol 2' => [2],
            'protocol 0' => [0],
            'the protocol as a string' => ['1'],
            'the protocol as a list' => [[1]],
            'no protocol' => [null],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function verbsThePlatformNeverSends(): array
    {
        return [
            'answer, which is reserved' => ['answer'],
            'signal, which is reserved' => ['signal'],
            'announce, which only the queue carries' => ['announce'],
            'observe, which only the queue carries' => ['observe'],
            'an unknown verb' => ['retire'],
            'a verb in another case' => ['Wake'],
            'a verb that is no string' => [['wake']],
            'no verb' => [null],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function brokenFields(): array
    {
        return [
            'a start with a definition the protocol cannot carry' => [['definition' => 'Nightly Reconciliation'] + self::START],
            'a start with a workflow ID that begins with two underscores' => [['workflowId' => '__iq:1'] + self::START],
            'a start whose run ID carries no version' => [['runId' => '01JB2M8Q3W'] + self::START],
            'a start whose use-by time is no integer' => [['expiresAt' => '1791368100'] + self::START],
            'a start whose use-by time is negative' => [['expiresAt' => -1] + self::START],
            'a start whose invocation ID is no string' => [['invocationId' => 7] + self::START],
            'a wake with a workflow ID that holds a control character' => [['workflowId' => "order\x07123"] + self::WAKE],
            'a wake whose run ID Neuron would refuse' => [['runId' => 'order:123'] + self::WAKE],
            'a wake whose attempt is zero' => [['attempt' => 0] + self::WAKE],
            'a wake whose attempt is a string' => [['attempt' => '1'] + self::WAKE],
            'a wake whose attempt is null' => [['attempt' => null] + self::WAKE],
            'an inspect with an empty workflow ID' => [['workflowId' => ''] + self::INSPECT],
            'an inspect whose run ID is null' => [['runId' => null] + self::INSPECT],
            'an inspect whose run ID is no string' => [['runId' => 12] + self::INSPECT],
            'an abandon whose run ID Neuron would refuse' => [['runId' => 'a run'] + self::ABANDON],
            'an abandon whose attempt is null' => [['attempt' => null] + self::ABANDON],
            'an abandon whose attempt is a string' => [['attempt' => '2'] + self::ABANDON],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingFields(): array
    {
        return [
            'a start without its invocation ID' => [self::START, 'invocationId'],
            'a start without a definition' => [self::START, 'definition'],
            'a start without a workflow ID' => [self::START, 'workflowId'],
            'a start without a run ID' => [self::START, 'runId'],
            'a start without a use-by time' => [self::START, 'expiresAt'],
            'a wake without its invocation ID' => [self::WAKE, 'invocationId'],
            'a wake without a run ID' => [self::WAKE, 'runId'],
            'a wake without its attempt' => [self::WAKE, 'attempt'],
            'an inspect without a definition' => [self::INSPECT, 'definition'],
            'an inspect without a workflow ID' => [self::INSPECT, 'workflowId'],
            'an abandon without a run ID' => [self::ABANDON, 'runId'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function queuedMessages(): array
    {
        return [
            'a start' => [self::START],
            'a wake' => [self::WAKE],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function decoded(array $message): PlatformMessage
    {
        $decoded = PlatformMessage::decode(json_encode($message, JSON_THROW_ON_ERROR), self::WEBHOOK_ID, self::NOW);
        self::assertInstanceOf(PlatformMessage::class, $decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function refusalOf(array $message): ?Code
    {
        $decoded = PlatformMessage::decode(json_encode($message, JSON_THROW_ON_ERROR), self::WEBHOOK_ID, self::NOW);

        return $decoded instanceof Code ? $decoded : null;
    }
}
