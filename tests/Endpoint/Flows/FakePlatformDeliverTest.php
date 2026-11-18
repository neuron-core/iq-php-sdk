<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Flows;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Testing\FakeDispatcher;
use NeuronCore\IQ\Testing\FakePlatform;
use NeuronCore\IQ\Tests\Stub\EndpointFlows\TappedIQ;
use NeuronCore\IQ\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;
use function count;
use function hash_hmac;
use function json_decode;
use function substr;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * FakePlatform::deliver(): the platform's side of the endpoint. It puts one
 * message on the wire as IQ does: a POST of its JSON, signed as
 * Standard Webhooks asks. The endpoint is the real one, so what the fake
 * signs is what the SDK verifies.
 */
class FakePlatformDeliverTest extends TestCase
{
    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    public function test_deliver_hands_the_endpoint_a_post(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $platform->deliver($iq, ['verb' => 'ping']);

        self::assertSame('POST', $iq->handled()[0]['method']);
    }

    public function test_deliver_sends_the_message_as_json(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);
        $start = self::start();

        $platform->deliver($iq, $start);

        self::assertSame($start + ['protocol' => 1], self::bodyHandled($iq));
    }

    public function test_deliver_adds_protocol_1_to_a_message_that_names_none(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $platform->deliver($iq, ['verb' => 'ping']);

        self::assertSame(['verb' => 'ping', 'protocol' => 1], self::bodyHandled($iq));
    }

    public function test_deliver_keeps_the_protocol_a_message_names(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $answer = $platform->deliver($iq, ['protocol' => 2, 'verb' => 'ping']);

        self::assertSame(['protocol' => 2, 'verb' => 'ping'], self::bodyHandled($iq));
        self::assertSame([400, '{"code":"unsupported_protocol"}'], [$answer->statusCode, $answer->body]);
    }

    public function test_deliver_signs_the_id_the_timestamp_and_the_raw_body_with_the_bytes_of_the_signing_key(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $platform->deliver($iq, self::start());

        ['headers' => $headers, 'body' => $body] = $iq->handled()[0];
        $signed = "{$headers['webhook-id']}.{$headers['webhook-timestamp']}.{$body}";
        $key = (string) base64_decode(substr($platform->signingKey, 6), true);
        self::assertSame('v1,' . base64_encode(hash_hmac('sha256', $signed, $key, true)), $headers['webhook-signature']);
    }

    public function test_the_endpoint_that_holds_the_key_of_the_fake_verifies_what_deliver_signs(): void
    {
        $platform = new FakePlatform();

        $answer = $platform->deliver(self::shop($platform), ['verb' => 'ping']);

        self::assertSame([200, '{"protocols":[1]}'], [$answer->statusCode, $answer->body]);
    }

    public function test_an_endpoint_that_holds_another_key_refuses_what_deliver_signs(): void
    {
        $platform = new FakePlatform();
        $iq = new TappedIQ(TestBench::API_KEY, (new FakePlatform())->signingKey);

        $answer = $platform->deliver($iq, ['verb' => 'ping']);

        self::assertSame([401, '{"code":"invalid_signature"}'], [$answer->statusCode, $answer->body]);
    }

    /**
     * @param array<string, mixed> $order
     */
    #[DataProvider('orders')]
    public function test_the_webhook_id_of_a_start_or_a_wake_is_its_invocation_id(array $order): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $answer = $platform->deliver($iq, $order);

        self::assertSame($order['invocationId'], $iq->handled()[0]['headers']['webhook-id']);
        self::assertSame(202, $answer->statusCode);
    }

    public function test_a_message_delivered_again_carries_the_same_webhook_id(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);
        $start = self::start();

        $platform->deliver($iq, $start);
        $platform->deliver($iq, $start);

        [$first, $second] = $iq->handled();
        self::assertSame($first['headers']['webhook-id'], $second['headers']['webhook-id']);
        self::assertSame($first['body'], $second['body']);
    }

    /**
     * @param array<string, mixed> $question
     */
    #[DataProvider('messagesWithoutAnInvocationId')]
    public function test_a_message_without_an_invocation_id_gets_a_webhook_id_of_its_own_at_every_delivery(array $question): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $platform->deliver($iq, $question);
        $platform->deliver($iq, $question);

        [$first, $second] = $iq->handled();
        self::assertNotSame('', $first['headers']['webhook-id']);
        self::assertNotSame($first['headers']['webhook-id'], $second['headers']['webhook-id']);
        self::assertArrayNotHasKey('invocationId', self::bodyHandled($iq));
    }

    public function test_deliver_signs_now_when_it_is_given_no_timestamp(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);
        $before = time();

        $platform->deliver($iq, ['verb' => 'ping']);

        $timestamp = (int) $iq->handled()[0]['headers']['webhook-timestamp'];
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual(time(), $timestamp);
    }

    public function test_deliver_sends_the_timestamp_it_is_given_as_the_webhook_timestamp(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);
        $aMinuteAgo = time() - 60;

        $answer = $platform->deliver($iq, ['verb' => 'ping'], $aMinuteAgo);

        self::assertSame((string) $aMinuteAgo, $iq->handled()[0]['headers']['webhook-timestamp']);
        self::assertSame(200, $answer->statusCode, 'The signature covers the timestamp that was given.');
    }

    public function test_a_message_delivered_with_a_timestamp_out_of_the_window_is_refused_by_the_endpoint(): void
    {
        $platform = new FakePlatform();

        $answer = $platform->deliver(self::shop($platform), ['verb' => 'ping'], time() - 600);

        self::assertSame([401, '{"code":"invalid_signature"}'], [$answer->statusCode, $answer->body]);
    }

    public function test_deliver_returns_the_answer_of_the_endpoint_with_its_headers(): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform)->setDispatcher(new UnreachableQueue());

        $answer = $platform->deliver($iq, self::start());

        self::assertSame(503, $answer->statusCode);
        self::assertSame('{"code":"dispatch_failed"}', $answer->body);
        self::assertSame('5', $answer->header('Retry-After'));
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('messagesOfThePlatform')]
    public function test_what_deliver_puts_on_the_wire_matches_the_schema_of_its_message(string $schema, array $message): void
    {
        $platform = new FakePlatform();
        $iq = self::shop($platform);

        $platform->deliver($iq, $message);

        self::assertSame([], ProtocolSchema::violations($schema, self::bodyHandled($iq)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function orders(): array
    {
        return [
            'a start' => [self::start()],
            'a wake' => [self::wake()],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function messagesWithoutAnInvocationId(): array
    {
        return [
            'a ping' => [['verb' => 'ping']],
            'an inspect' => [self::inspect()],
            'an abandon' => [['verb' => 'abandon', 'definition' => 'order-fulfilment', 'workflowId' => 'order:123', 'runId' => self::RUN_ID]],
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>}> the schema, and a message as a test writes it
     */
    public static function messagesOfThePlatform(): array
    {
        return [
            'a start' => ['start', self::start()],
            'a wake' => ['wake', self::wake()],
            'an inspect' => ['inspect', self::inspect()],
            'an abandon' => ['abandon', ['verb' => 'abandon', 'definition' => 'order-fulfilment', 'workflowId' => 'order:123', 'runId' => self::RUN_ID, 'attempt' => 2]],
            'a ping' => ['ping', ['verb' => 'ping']],
        ];
    }

    /**
     * The endpoint of a shop that holds the signing key of the platform. Its queue keeps what it is handed.
     */
    protected static function shop(FakePlatform $platform): TappedIQ
    {
        return (new TappedIQ(TestBench::API_KEY, $platform->signingKey, TestBench::PLATFORM_URL, $platform))
            ->setDispatcher(new FakeDispatcher())
            ->register('order-fulfilment', static fn (): Workflow => new Workflow());
    }

    /**
     * @return array<string, mixed>
     */
    protected static function start(): array
    {
        return [
            'verb' => 'start',
            'invocationId' => 'inv_01JB2M',
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 900,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function wake(): array
    {
        return [
            'verb' => 'wake',
            'invocationId' => 'inv_01JB7K4T9QW2',
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => self::RUN_ID,
            'attempt' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function inspect(): array
    {
        return ['verb' => 'inspect', 'definition' => 'order-fulfilment', 'workflowId' => 'order:123'];
    }

    /**
     * @return array<string, mixed> the body of the last request the endpoint was handed
     */
    protected static function bodyHandled(TappedIQ $iq): array
    {
        $handled = $iq->handled();

        return json_decode($handled[count($handled) - 1]['body'], true, flags: JSON_THROW_ON_ERROR);
    }
}
