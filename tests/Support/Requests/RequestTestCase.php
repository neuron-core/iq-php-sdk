<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Requests;

use Closure;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\Cloud\Tests\Support\Runner\InspectableDispatcher;
use NeuronCore\Cloud\Tests\Support\TestBench;

use function base64_decode;
use function base64_encode;
use function count;
use function hash_hmac;
use function is_string;
use function json_encode;
use function strlen;
use function substr;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * A test of the requests the endpoint is handed: every one goes through
 * NeuronCloud::handle() and is kept with its answer, and every answer must
 * follow the table of statuses of the wire protocol. The signatures are
 * computed here, not by the SDK.
 */
abstract class RequestTestCase extends EndpointTestCase
{
    protected const WEBHOOK_ID = 'msg_01JB7K4T9QW2';

    protected const PING = '{"protocol":1,"verb":"ping"}';

    /** The one answer to every signature problem. */
    protected const INVALID_SIGNATURE = [401, '{"code":"invalid_signature"}'];

    protected const INVALID_REQUEST = [400, '{"code":"invalid_request"}'];

    protected const UNSUPPORTED_PROTOCOL = [400, '{"code":"unsupported_protocol"}'];

    /** The cloud whose endpoint the requests are handed to. */
    protected NeuronCloud $cloud;

    protected InspectableDispatcher $queue;

    protected Footprint $footprint;

    /** @var Exchange[] */
    protected array $exchanges = [];

    protected function assertPostConditions(): void
    {
        if (isset($this->bench)) {
            parent::assertPostConditions();
        }

        foreach ($this->exchanges as $exchange) {
            StatusTable::assertRespectedBy($exchange);
        }
    }

    /**
     * Every case on every backend, for a data provider.
     *
     * @param array<string, array<int, mixed>> $cases
     * @return array<string, array<int, mixed>> the backend first, then the arguments of the case
     */
    protected static function onEveryBackend(array $cases): array
    {
        $rows = [];

        foreach ($cases as $case => $arguments) {
            foreach (TestBench::backends() as $store => [$backend]) {
                $rows["{$case}, on {$store}"] = [$backend, ...$arguments];
            }
        }

        return $rows;
    }

    /**
     * The bootstrap: "order-fulfilment" waits for a payment, then for a timer, then completes. Its queue can be
     * read, and what the endpoint asks of the store and of the factory is counted.
     */
    protected function shop(string $backend): TestBench
    {
        if (isset($this->bench)) {
            $this->bench->cleanUp();
        }

        $bench = $this->benchOn($backend);
        $this->queue = new InspectableDispatcher();
        $this->footprint = new Footprint();
        $this->cloud = $this->wire($bench->cloud);

        return $bench;
    }

    /**
     * Another deployment over the same store, queue and definition, holding another signing key.
     */
    protected function deploymentHolding(?string $signingKey): NeuronCloud
    {
        return $this->wire(new NeuronCloud(TestBench::API_KEY, $signingKey, TestBench::PLATFORM_URL, $this->bench->platform));
    }

    protected function wire(NeuronCloud $cloud): NeuronCloud
    {
        $executions = $this->bench->executions;

        return $cloud
            ->setPersistence($this->footprint->ofStores($this->bench->persistence))
            ->setDispatcher($this->queue)
            ->register(static::DEFINITION, $this->footprint->ofWorkflows(static fn (): Workflow => new WaitingWorkflow($executions)));
    }

    /**
     * Hand one request to the endpoint.
     *
     * @param array<array-key, mixed>|null $headers null: the headers the platform signs this body with, now
     */
    protected function post(string $body, ?array $headers = null, string $method = 'POST'): HttpResponse
    {
        $answer = $this->cloud->handle($method, $headers ?? $this->signed($body), $body);
        $this->exchanges[] = new Exchange($method, $body, $answer);

        return $answer;
    }

    /**
     * The platform sends a message: a start and a wake under their invocation ID, a question under an ID of its own.
     */
    protected function deliver(array $message, ?int $timestamp = null): HttpResponse
    {
        $body = self::encode($message);
        $webhookId = is_string($message['invocationId'] ?? null) ? $message['invocationId'] : self::WEBHOOK_ID;

        return $this->post($body, $this->signed($body, $webhookId, $timestamp));
    }

    /**
     * The headers of a body the platform signed.
     *
     * @param int|null $timestamp when it signed; null: now
     * @return array<string, string>
     */
    protected function signed(string $body, string $webhookId = self::WEBHOOK_ID, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => $webhookId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::signature($this->platformKey(), $webhookId, (string) $timestamp, $body),
        ];
    }

    /**
     * @return string the bytes the platform signs with
     */
    protected function platformKey(): string
    {
        return (string) base64_decode(substr($this->bench->platform->signingKey, strlen('whsec_')), true);
    }

    /**
     * The Standard Webhooks signature of one message.
     *
     * @param string $key the bytes of the key
     */
    protected static function signature(string $key, string $webhookId, string $timestamp, string $body): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * @param array<string, mixed> $message "protocol" is 1 unless the message says otherwise
     */
    protected static function encode(array $message): string
    {
        return json_encode($message + ['protocol' => 1], JSON_THROW_ON_ERROR);
    }

    /**
     * A bound measured against the endpoint's clock can only be told when the clock did not move between the
     * test reading it and the endpoint reading it: the exchange is made again when it did.
     *
     * @param Closure(int $now): HttpResponse $exchange
     */
    protected function atOneInstant(Closure $exchange): HttpResponse
    {
        do {
            $now = time();
            $answer = $exchange($now);
        } while (time() !== $now);

        return $answer;
    }

    /**
     * A worker deals with everything that waits in the queue of this test.
     *
     * @return array<int, Report|CloudException> what every invoke() returned or threw, in order
     */
    protected function work(): array
    {
        return $this->queue->runAll($this->cloud);
    }

    /**
     * @return array<string, int> everything a request can make the application do
     */
    protected function done(): array
    {
        return ['messages queued' => count($this->queue->dispatched())]
            + $this->footprint->toArray()
            + ['nodes executed' => $this->bench->executions->total()];
    }

    protected function assertNothingWasDone(): void
    {
        self::assertSame(
            ['messages queued' => 0, 'stores opened' => 0, 'store operations' => 0, 'workflows built' => 0, 'nodes executed' => 0],
            $this->done(),
        );
    }

    /**
     * @return array{int, string} what a test compares with the constants of this class
     */
    protected static function statusAndBody(HttpResponse $answer): array
    {
        return [$answer->statusCode, $answer->body];
    }
}
