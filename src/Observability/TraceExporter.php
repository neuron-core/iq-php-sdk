<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Observability;

use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Runtime\PlatformClient;

use function error_log;

/**
 * Ships finished traces over OTLP/HTTP with JSON encoding: `POST {platformUrl}/v1/traces`,
 * with the API key and the HTTP client of the SDK. Best-effort: a trace that fails to
 * encode or upload is logged, never thrown — tracing must not break the workflow run.
 *
 * @internal
 */
class TraceExporter
{
    public function __construct(protected readonly PlatformClient $platform)
    {
    }

    /**
     * @param array<string, mixed> $trace The payload returned by {@see Trace::close()}.
     */
    public function send(array $trace): void
    {
        try {
            $this->platform->traces($trace);
        } catch (IQException $e) {
            // The cause says why no answer came: a network error, or a value JSON cannot carry.
            error_log('[TraceExporter] Failed to send trace: ' . $e->getMessage() . ' ' . $e->getPrevious()?->getMessage());
        }
    }
}
