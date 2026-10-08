<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use DateTimeImmutable;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Stub\Units\OrderPlaced;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

use function array_column;
use function array_map;
use function array_unique;
use function array_values;
use function base64_encode;
use function json_decode;
use function json_encode;
use function serialize;

use const JSON_THROW_ON_ERROR;

class InvocationTest extends TestCase
{
    protected const RUN_ID = '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    public function test_the_array_form_has_one_entry_for_each_field(): void
    {
        $signal = self::invocation('inv_0192f3ab', Verb::Signal, 'application', 2, 1791539999, 'payment.received', ['amount' => 4200]);

        self::assertSame([
            'invocationId' => 'inv_0192f3ab',
            'verb' => 'signal',
            'origin' => 'application',
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => self::RUN_ID,
            'attempt' => 2,
            'expiresAt' => 1791539999,
            'signal' => 'payment.received',
            'payload' => base64_encode(serialize(['amount' => 4200])),
            'event' => null,
            'body' => null,
        ], $signal->toArray());
    }

    #[DataProvider('invocations')]
    public function test_an_invocation_is_rebuilt_from_its_array_form(Invocation $invocation): void
    {
        self::assertEquals($invocation, Invocation::fromArray($invocation->toArray()));
    }

    #[DataProvider('invocations')]
    public function test_an_invocation_is_rebuilt_after_a_json_queue_held_it(Invocation $invocation): void
    {
        $queued = json_encode($invocation->toArray(), JSON_THROW_ON_ERROR);

        self::assertEquals($invocation, Invocation::fromArray(json_decode($queued, true, flags: JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('invocations')]
    public function test_the_array_form_comes_back_from_json_as_it_went_in(Invocation $invocation): void
    {
        $queued = json_encode($invocation->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame($invocation->toArray(), json_decode($queued, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_a_payload_keeps_the_types_of_its_values_through_a_json_queue(): void
    {
        // JSON alone would hand the node the integer 10.
        $answer = self::invocation('inv_0192f3a9', Verb::Answer, 'application', 2, payload: ['amount' => 10.0, 'notes' => []]);

        $queued = json_encode($answer->toArray(), JSON_THROW_ON_ERROR);
        $received = Invocation::fromArray(json_decode($queued, true, flags: JSON_THROW_ON_ERROR));

        self::assertSame(['amount' => 10.0, 'notes' => []], $received->payload);
    }

    #[DataProvider('carriedPayloadsThatAreNoArray')]
    public function test_a_carried_payload_that_is_not_an_array_is_never_taken_for_no_payload(string $carried): void
    {
        $this->expectException(TypeError::class);

        Invocation::fromArray([
            'invocationId' => 'inv_0192f3a9',
            'verb' => 'answer',
            'origin' => 'application',
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => self::RUN_ID,
            'attempt' => 2,
            'payload' => $carried,
        ]);
    }

    public function test_the_verbs_are_the_four_segments_and_the_two_notices(): void
    {
        self::assertSame(['start', 'wake', 'answer', 'signal', 'announce', 'observe'], array_column(Verb::cases(), 'value'));
    }

    public function test_the_invocations_of_these_tests_are_of_every_verb(): void
    {
        $verbs = array_map(static fn (array $row): string => $row[0]->verb->value, self::invocations());

        self::assertEqualsCanonicalizing(array_column(Verb::cases(), 'value'), array_values(array_unique($verbs)));
    }

    public function test_a_field_the_array_form_does_not_hold_is_null(): void
    {
        $wake = Invocation::fromArray([
            'invocationId' => 'inv_01JB7K4T9QW2',
            'verb' => 'wake',
            'origin' => 'platform',
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => self::RUN_ID,
            'attempt' => 1,
        ]);

        self::assertEquals(self::invocation('inv_01JB7K4T9QW2', Verb::Wake, 'platform', 1), $wake);
    }

    /**
     * @return array<string, array{Invocation}> invocations of every verb, with the fields the SDK sets for each
     */
    public static function invocations(): array
    {
        return [
            'a start of the platform' => [self::invocation('inv_01JB2M', Verb::Start, 'platform', expiresAt: 1791368100)],
            'a start the application dispatched, with its event' => [self::invocation(
                'inv_0192f3a8',
                Verb::Start,
                'application',
                expiresAt: 1791368100,
                // A serialized object holds NUL bytes, which JSON cannot: that is why the event travels in base64.
                event: base64_encode(serialize(new OrderPlaced('order:123'))),
            )],
            'a wake' => [self::invocation('inv_01JB7K4T9QW2', Verb::Wake, 'platform', 1)],
            'an answer' => [self::invocation(
                'inv_0192f3a9',
                Verb::Answer,
                'application',
                2,
                1791539999,
                payload: ['call_7f3a' => 'approve', 'call_7f3b' => ['reject', 'Montant trop élevé'], 'amounts' => [12.5, 0, -3]],
            )],
            'an answer with nothing in it' => [self::invocation('inv_0192f3aa', Verb::Answer, 'application', 2, 1791539999, payload: [])],
            // Neither fits in JSON as it is: that is why the payload travels as the event does.
            'an answer with a binary string' => [self::invocation('inv_0192f3af', Verb::Answer, 'application', 2, payload: ['scan' => "\xB1\x31"])],
            'an answer with an object' => [self::invocation(
                'inv_0192f3b0',
                Verb::Answer,
                'application',
                2,
                payload: ['decidedAt' => new DateTimeImmutable('@1791453603')],
            )],
            'a signal' => [self::invocation(
                'inv_0192f3ab',
                Verb::Signal,
                'application',
                3,
                1791539999,
                'payment.received',
                ['reference' => 'PAY-2026-10', 'settled' => true, 'fee' => null],
            )],
            'an announce' => [self::invocation('ann_0192f3ac', Verb::Announce, 'platform', body: [
                'protocol' => 1,
                'invocationId' => 'inv_01JB7K4T9QW2',
                'verb' => 'wake',
                'origin' => 'platform',
                'definition' => 'order-fulfilment',
                'workflowId' => 'order:123',
                'runId' => self::RUN_ID,
                'attempt' => 1,
                'leaseSeconds' => 600,
                'at' => 1791453603,
            ])],
            'an observe' => [self::invocation('obs_0192f3ad', Verb::Observe, 'application', body: [
                'invocationId' => 'inv_0192f3a8',
                'verb' => 'start',
                'outcome' => 'failed',
                'code' => null,
                'errorClass' => 'App\Exceptions\PaymentDeclined',
                'executedAttempt' => 1,
            ])],
            'an observe of a job that died' => [self::invocation('obs_0192f3ae', Verb::Observe, 'platform', body: [
                'invocationId' => 'inv_01JB7K4T9QW2',
                'verb' => 'wake',
            ])],
        ];
    }

    /**
     * @return array<string, array{string}> what the queue may hold in place of a serialized array
     */
    public static function carriedPayloadsThatAreNoArray(): array
    {
        return [
            'a serialized string' => [base64_encode(serialize('approve'))],
            'a serialized null' => [base64_encode(serialize(null))],
            'no value at all' => [''],
            'characters outside base64' => ['%%%'],
        ];
    }

    /**
     * @param array<array-key, mixed>|null $payload
     * @param array<string, mixed>|null $body
     */
    protected static function invocation(
        string $invocationId,
        Verb $verb,
        string $origin,
        ?int $attempt = null,
        ?int $expiresAt = null,
        ?string $signal = null,
        ?array $payload = null,
        ?string $event = null,
        ?array $body = null,
    ): Invocation {
        return new Invocation(
            $invocationId,
            $verb,
            $origin,
            'order-fulfilment',
            'order:123',
            self::RUN_ID,
            $attempt,
            $expiresAt,
            $signal,
            $payload,
            $event,
            $body,
        );
    }
}
