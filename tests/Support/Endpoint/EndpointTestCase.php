<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Endpoint;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Signature;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;

use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * A test of the endpoint: the fake platform signs the messages and calls
 * handle(), and the fake queue holds what the endpoint handed over. Every
 * message the endpoint took and every answer it gave must respect the protocol.
 */
abstract class EndpointTestCase extends CloudTestCase
{
    protected const DEFINITION = 'order-fulfilment';

    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /** What a store that fails says: no answer of the endpoint may repeat it. */
    protected const STORE_FAILURE = 'SQLSTATE[HY000] [2002] Connection refused to db.internal for user shop';

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    /**
     * @param array<string, mixed> $message
     * @param int|null $timestamp when the platform signed it; null: now
     */
    protected function deliver(array $message, ?int $timestamp = null): HttpResponse
    {
        return $this->bench->platform->deliver($this->bench->cloud, $message, $timestamp);
    }

    /**
     * The headers the platform signs a body with, for a request the test hands to handle() itself.
     *
     * @return array<string, string>
     */
    protected function signedHeaders(string $body, string $webhookId = 'msg_1'): array
    {
        $timestamp = time();
        $signature = (new Signature($this->bench->platform->signingKey))->sign($webhookId, $timestamp, $body);

        return ['webhook-id' => $webhookId, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => (string) $signature];
    }

    /**
     * From now on every operation of the store fails.
     */
    protected function failTheStore(): void
    {
        $persistence = $this->bench->persistence;

        $this->bench->cloud->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            static fn () => throw new StoreUnavailable(self::STORE_FAILURE),
        ));
    }

    /**
     * A start the platform sends: it can be used for a quarter of an hour.
     *
     * @param array<string, mixed> $fields the fields that differ
     * @return array<string, mixed>
     */
    protected function start(array $fields = []): array
    {
        return $fields + [
            'verb' => 'start',
            'invocationId' => 'inv_start',
            'definition' => static::DEFINITION,
            'workflowId' => static::WORKFLOW_ID,
            'runId' => static::RUN_ID,
            'expiresAt' => time() + 900,
        ];
    }

    /**
     * @param array<string, mixed> $fields the fields that differ
     * @return array<string, mixed>
     */
    protected function wake(int $attempt, array $fields = []): array
    {
        return $fields + [
            'verb' => 'wake',
            'invocationId' => 'inv_wake_' . $attempt,
            'definition' => static::DEFINITION,
            'workflowId' => static::WORKFLOW_ID,
            'runId' => static::RUN_ID,
            'attempt' => $attempt,
        ];
    }

    /**
     * @param string|null $runId null: the question names no run
     * @return array<string, mixed>
     */
    protected function inspect(?string $runId = null): array
    {
        return ['verb' => 'inspect', 'definition' => static::DEFINITION, 'workflowId' => static::WORKFLOW_ID]
            + ($runId === null ? [] : ['runId' => $runId]);
    }

    /**
     * @param int|null $attempt null: the platform has heard nothing of the run
     * @return array<string, mixed>
     */
    protected function abandon(string $runId = self::RUN_ID, ?int $attempt = null): array
    {
        return ['verb' => 'abandon', 'definition' => static::DEFINITION, 'workflowId' => static::WORKFLOW_ID, 'runId' => $runId]
            + ($attempt === null ? [] : ['attempt' => $attempt]);
    }

    /**
     * A worker deals with everything that waits in the queue.
     *
     * @return array<int, Report|CloudException> what every invoke() returned or threw, in order
     */
    protected function work(): array
    {
        return $this->bench->dispatcher->runAll($this->bench->cloud);
    }

    /**
     * @return array<string, mixed> the fields of an answer of the endpoint
     */
    protected static function fieldsOf(HttpResponse $answer): array
    {
        return json_decode($answer->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
