<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Runtime\Notices;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_replace;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

class NoticesTest extends TestCase
{
    /** The announcement of the wire protocol, as the design shows it. */
    protected const ANNOUNCEMENT_OF_THE_PROTOCOL = '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
        . ' "definition": "order-fulfilment", "workflowId": "order:123",'
        . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1, "leaseSeconds": 600, "at": 1791453603}';

    protected const PAYLOAD_TEXT = 'refund-of-ACME';

    protected const SIGNAL = 'payment.received';

    protected const EVENT = 'YSBzZXJpYWxpemVkIHN0YXJ0IGV2ZW50';

    protected const EXPIRES_AT = 1791539999;

    protected const FACTS = [
        'invocationId' => 'inv_01JB7K4T9QW2',
        'verb' => 'wake',
        'outcome' => 'suspended',
        'code' => null,
        'errorClass' => null,
        'executedAttempt' => 2,
    ];

    public function test_an_announcement_has_the_fields_of_the_protocol_and_no_other(): void
    {
        $announcement = Notices::announcement(self::wake(), 600);

        $expected = json_decode(self::ANNOUNCEMENT_OF_THE_PROTOCOL, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_replace($expected, ['at' => $announcement['at']]), $announcement);
    }

    public function test_an_announcement_is_dated_when_it_is_built(): void
    {
        $before = time();

        $at = Notices::announcement(self::wake(), 600)['at'];

        self::assertGreaterThanOrEqual($before, $at);
        self::assertLessThanOrEqual(time(), $at);
    }

    public function test_the_announcement_of_a_start_has_no_attempt(): void
    {
        $announcement = Notices::announcement(self::segment(Verb::Start), 600);

        self::assertNull($announcement['attempt']);
    }

    public function test_an_announcement_carries_the_lease_it_is_given(): void
    {
        self::assertSame(90, Notices::announcement(self::wake(), 90)['leaseSeconds']);
    }

    #[DataProvider('segments')]
    public function test_the_announcement_of_every_kind_of_segment_matches_the_schema_of_the_protocol(Invocation $segment): void
    {
        ProtocolSchema::assertValid('announcement', Notices::announcement($segment, 600));
    }

    #[DataProvider('segments')]
    public function test_an_announcement_carries_nothing_that_exists_only_in_the_queue(Invocation $segment): void
    {
        $announcement = json_encode(Notices::announcement($segment, 600), JSON_THROW_ON_ERROR);

        foreach ([self::PAYLOAD_TEXT, self::SIGNAL, self::EVENT, (string) self::EXPIRES_AT] as $queueOnly) {
            self::assertStringNotContainsString($queueOnly, $announcement);
        }
    }

    public function test_a_queued_announcement_is_a_message_of_its_own_that_holds_the_announcement(): void
    {
        $announcement = Notices::announcement(self::wake(), 600);

        $notice = Notices::announce(self::wake(), $announcement);

        self::assertSame(Verb::Announce, $notice->verb);
        self::assertSame($announcement, $notice->body);
    }

    public function test_a_queued_observe_is_a_message_of_its_own_that_holds_how_the_segment_ended(): void
    {
        $notice = Notices::observe(self::wake(), self::FACTS);

        self::assertSame(Verb::Observe, $notice->verb);
        self::assertSame(self::FACTS, $notice->body);
    }

    #[DataProvider('notices')]
    public function test_a_notice_is_addressed_like_the_segment_it_speaks_for(Invocation $notice): void
    {
        $segment = self::wake();

        self::assertSame(
            [$segment->origin, $segment->definition, $segment->workflowId, $segment->runId],
            [$notice->origin, $notice->definition, $notice->workflowId, $notice->runId],
        );
    }

    #[DataProvider('notices')]
    public function test_a_notice_has_an_invocation_id_of_its_own_that_the_protocol_accepts(Invocation $notice, string $prefix): void
    {
        self::assertNotSame(self::wake()->invocationId, $notice->invocationId);
        self::assertStringStartsWith($prefix, $notice->invocationId);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $notice->invocationId);
    }

    #[DataProvider('notices')]
    public function test_a_notice_carries_no_fence_no_use_by_time_and_no_input(Invocation $notice): void
    {
        self::assertSame(
            [null, null, null, null, null],
            [$notice->attempt, $notice->expiresAt, $notice->signal, $notice->payload, $notice->event],
        );
    }

    #[DataProvider('notices')]
    public function test_a_notice_survives_a_json_queue(Invocation $notice): void
    {
        $queued = json_encode($notice->toArray(), JSON_THROW_ON_ERROR);

        self::assertEquals($notice, Invocation::fromArray(json_decode($queued, true, flags: JSON_THROW_ON_ERROR)));
    }

    public function test_two_notices_about_one_segment_have_different_invocation_ids(): void
    {
        $first = Notices::observe(self::wake(), self::FACTS);
        $second = Notices::observe(self::wake(), self::FACTS);

        self::assertNotSame($first->invocationId, $second->invocationId);
    }

    /**
     * @return array<string, array{Invocation}> one segment of every kind, with what only the queue may hold
     */
    public static function segments(): array
    {
        return [
            'a start' => [self::segment(Verb::Start)],
            'a wake' => [self::wake()],
            'an answer' => [self::segment(Verb::Answer, attempt: 2, payload: ['call_7f3a' => 'approve', 'note' => self::PAYLOAD_TEXT])],
            'a signal' => [self::segment(Verb::Signal, attempt: 2, signal: self::SIGNAL, payload: ['reference' => self::PAYLOAD_TEXT])],
        ];
    }

    /**
     * @return array<string, array{Invocation, string}> a notice about the wake, and the prefix of its invocation ID
     */
    public static function notices(): array
    {
        return [
            'an announcement' => [Notices::announce(self::wake(), Notices::announcement(self::wake(), 600)), 'ann_'],
            'an observe' => [Notices::observe(self::wake(), self::FACTS), 'obs_'],
        ];
    }

    protected static function wake(): Invocation
    {
        return self::segment(Verb::Wake, attempt: 1);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    protected static function segment(Verb $verb, ?int $attempt = null, ?string $signal = null, ?array $payload = null): Invocation
    {
        return new Invocation(
            'inv_01JB7K4T9QW2',
            $verb,
            'platform',
            'order-fulfilment',
            'order:123',
            '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
            $attempt,
            expiresAt: self::EXPIRES_AT,
            signal: $signal,
            payload: $payload,
            event: $verb === Verb::Start ? self::EVENT : null,
        );
    }
}
