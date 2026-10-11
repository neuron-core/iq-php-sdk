<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Observability;

use JsonException;
use NeuronCore\Cloud\Http\Transport;
use NeuronCore\Cloud\Http\TransportException;
use NeuronCore\Cloud\NeuronCloud;

use function error_log;
use function json_encode;
use function rtrim;

use const JSON_THROW_ON_ERROR;

/**
 * Ships finished traces over OTLP/HTTP with JSON encoding: `POST {platformUrl}/traces`.
 * The base URL includes `/api` for Neuron Cloud or `/v1` for an OTLP collector. Best-effort: a trace that fails to encode or upload is logged, never
 * thrown — tracing must not break the workflow run.
 */
class TraceExporter
{
    public function __construct(
        protected readonly Transport $transport,
        protected readonly string $platformUrl,
        protected readonly string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $trace The payload returned by {@see Trace::close()}.
     */
    public function send(array $trace): void
    {
        try {
            $json = json_encode($trace, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[TraceExporter] Failed to encode trace: ' . $e->getMessage());

            return;
        }

        try {
            $this->transport->send(
                rtrim($this->platformUrl, '/') . '/traces',
                $json,
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Neuron-SDK' => NeuronCloud::SDK,
                ],
                'POST',
            );
        } catch (TransportException $e) {
            error_log('[TraceExporter] Failed to send trace: ' . $e->getMessage());
        }
    }
}
