<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Observability;

use NeuronCore\IQ\Runtime\Protocol;
use Throwable;

use function array_is_list;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_reverse;
use function bin2hex;
use function explode;
use function hash;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function microtime;
use function random_bytes;
use function substr;

/**
 * One workflow run segment's trace under construction: the run identity, the
 * terminal status, the stack of open spans that yields the parent/child
 * hierarchy, and the finished spans. Pure state — nothing here performs I/O or
 * knows the framework's event types; {@see IQListener} maps events onto it
 * and {@see TraceExporter} ships what {@see close()} returns.
 *
 * The payload is an OpenTelemetry OTLP/JSON `ExportTraceServiceRequest`
 * (opentelemetry-proto, trace/v1): resourceSpans → scopeSpans → spans, hex
 * trace/span IDs, Unix-nanosecond timestamps as decimal strings, integer
 * enums, and typed `{key, value}` attributes — ingestible by any OTLP/HTTP
 * backend, not only IQ. What the spans mean is the listener's job
 * (the GenAI semantic conventions); this class only knows the wire shape.
 *
 * The trace is the run: the trace ID is derived from the framework's run ID,
 * so every suspend/resume segment of a durable run lands in the same trace.
 * OTLP spans are immutable — there is no upsert — so a segment never resends
 * a span: it ships only its own spans, under fresh span IDs, and its root is
 * parentless (a run-wide root could only ship at completion, days late, and
 * never for an abandoned run). The run ID, workflow ID and the caller's
 * attributes ride on every span: backends group and filter by span, and none
 * has a trace-level record to hang them on.
 *
 * @internal
 */
class Trace
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_FAILED = 'failed';

    public const KIND_INTERNAL = 1;
    public const KIND_CLIENT = 3;

    protected const STATUS_CODE_ERROR = 2;

    /** W3C sampled (0x01) + "is-remote known, local parent" (0x100). */
    protected const FLAGS = 257;

    protected const SCOPE = 'neuron-core/iq-php-sdk';

    /** 32-hex trace ID: derived from the run ID once known, random until then. */
    protected string $traceId;

    protected ?string $runId = null;

    /** The durable address every run of the workflow shares; groups traces. */
    protected ?string $workflowId = null;
    protected string $status = self::STATUS_COMPLETED;

    /** @var array<string, array<string, mixed>> open spans keyed by pairing key, outermost first */
    protected array $open = [];

    /** @var array<string, true> keys of open spans whose work runs outside this process */
    protected array $forked = [];

    /** @var list<array<string, mixed>> finished spans */
    protected array $spans = [];

    /**
     * The last error the run reported, as strings: the exception itself would be kept as long as the trace.
     *
     * @var array{type: class-string, message: string}|null
     */
    protected ?array $lastError = null;

    /**
     * @param array<string, mixed> $attributes Run-level attributes stamped on every span.
     */
    public function __construct(
        protected readonly ?string $serviceName = null,
        protected readonly array $attributes = [],
    ) {
        $this->traceId = bin2hex(random_bytes(16));
    }

    /**
     * Adopt the framework's identity: the run ID determines the trace ID (a
     * hash keeps the bits samplers read uniform) and the workflow ID is the
     * grouping key. Without a run ID (no WorkflowStart seen) the random trace
     * ID from construction stays.
     */
    public function identify(?string $runId, ?string $workflowId): void
    {
        if ($runId !== null) {
            $this->runId = $runId;
            $this->traceId = substr(hash('sha256', $runId), 0, 32);
        }

        $this->workflowId = $workflowId;
    }

    public function markSuspended(): void
    {
        if ($this->status !== self::STATUS_FAILED) {
            $this->status = self::STATUS_SUSPENDED;
        }
    }

    /**
     * Fail the run and the innermost open span, with the last error the run
     * reported: OTel has no trace-level status, a failure exists only where a
     * span carries it.
     */
    public function markFailed(): void
    {
        $this->status = self::STATUS_FAILED;

        $key = $this->innermost();
        if ($key !== null) {
            $this->open[$key]['status'] = ['code' => self::STATUS_CODE_ERROR, 'message' => $this->lastError['message'] ?? ''];
            $this->open[$key]['attributes']['error.type'] = $this->lastError['type'] ?? null;
        }
    }

    /**
     * Attach an `exception` span event for an error that may be the run's
     * failure. The framework reports a listener that threw the same way, and
     * the run goes on then: only the end of the segment tells which it was,
     * and {@see markFailed()} is called there.
     */
    public function recordError(Throwable $exception): void
    {
        $this->lastError = ['type' => $exception::class, 'message' => $exception->getMessage()];

        $this->recordException($exception);
    }

    /**
     * Attach an `exception` span event to the innermost open span without
     * failing it — for errors the run survives.
     */
    public function recordException(Throwable $exception): void
    {
        $key = $this->innermost();
        if ($key === null) {
            return;
        }

        $this->open[$key]['events'][] = [
            'timeUnixNano' => $this->now(),
            'name' => 'exception',
            'attributes' => $this->encodeAttributes([
                'exception.type' => $exception::class,
                'exception.message' => $exception->getMessage(),
                'exception.stacktrace' => $exception->getTraceAsString(),
            ]),
        ];
    }

    /**
     * Open a span under the innermost open span.
     *
     * A forked span runs its work in another process, so nothing can nest
     * under it: spans opened while it is open attach to the nearest
     * non-forked ancestor instead (parallel tool calls come out as siblings).
     *
     * @param self::KIND_*         $kind
     * @param array<string, mixed> $attributes
     */
    public function openSpan(
        string $key,
        string $name,
        int $kind = self::KIND_INTERNAL,
        array $attributes = [],
        bool $forked = false,
    ): void {
        // A duplicate key means the paired stop event was lost; close the stale
        // span rather than leaking it.
        if (isset($this->open[$key])) {
            $this->closeSpan($key);
        }

        $span = [
            'spanId' => bin2hex(random_bytes(8)),
            'name' => $name,
            'kind' => $kind,
            'startTimeUnixNano' => $this->now(),
            'attributes' => $attributes,
        ];

        $parent = $this->innermost();
        if ($parent !== null) {
            $span['parentSpanId'] = $this->open[$parent]['spanId'];
        }

        $this->open[$key] = $span;
        if ($forked) {
            $this->forked[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $attributes Merged into the span's attributes.
     * @param string|null          $error      Fails the span with this status message.
     */
    public function closeSpan(string $key, array $attributes = [], ?string $error = null): void
    {
        $span = $this->open[$key] ?? null;
        if ($span === null) {
            return;
        }
        unset($this->open[$key], $this->forked[$key]);

        $span['endTimeUnixNano'] = $this->now();
        $span['attributes'] = $attributes + $span['attributes'];
        if ($error !== null) {
            $span['status'] = ['code' => self::STATUS_CODE_ERROR, 'message' => $error];
        }

        $this->spans[] = $span;
    }

    /**
     * Whether nothing was opened: an export of it would be a request with no span.
     */
    public function isEmpty(): bool
    {
        return $this->open === [] && $this->spans === [];
    }

    /**
     * Close the trace and return the OTLP payload. Spans still open (an
     * inference that threw before its stop event, a node interrupted
     * mid-flight) are closed now: OTLP cannot represent an unfinished span.
     * The segment's terminal status rides on its parentless span(s) as
     * `neuron.run.status` — the conventions have no suspend/resume vocabulary yet.
     *
     * @return array<string, mixed>
     */
    public function close(): array
    {
        while ($this->open !== []) {
            $this->closeSpan(array_key_last($this->open));
        }

        $common = $this->attributes;
        if ($this->runId !== null) {
            $common['neuron.run.id'] = $this->runId;
        }
        if ($this->workflowId !== null) {
            $common['neuron.workflow.id'] = $this->workflowId;
        }

        $spans = array_map(function (array $span) use ($common): array {
            $attributes = $span['attributes'] + $common;
            if (!isset($span['parentSpanId'])) {
                $attributes['neuron.run.status'] = $this->status;
            }

            return [
                'traceId' => $this->traceId,
                'flags' => self::FLAGS,
                'attributes' => $this->encodeAttributes($attributes),
            ] + $span;
        }, $this->spans);

        [$sdkName, $sdkVersion] = explode('/', Protocol::SDK);

        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->encodeAttributes([
                    'service.name' => $this->serviceName ?? 'unknown_service',
                    'telemetry.sdk.name' => $sdkName,
                    'telemetry.sdk.language' => 'php',
                    'telemetry.sdk.version' => $sdkVersion,
                ])],
                'scopeSpans' => [[
                    'scope' => ['name' => self::SCOPE, 'version' => $sdkVersion],
                    'spans' => $spans,
                ]],
            ]],
        ];
    }

    /**
     * OTLP attributes are a list of typed key/values; nulls are not representable.
     *
     * @param array<string, mixed> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    protected function encodeAttributes(array $attributes): array
    {
        $encoded = [];
        foreach ($attributes as $key => $value) {
            if ($value !== null) {
                $encoded[] = ['key' => (string) $key, 'value' => $this->encodeValue($value)];
            }
        }

        return $encoded;
    }

    /**
     * The OTLP AnyValue for a PHP value. 64-bit integers travel as decimal strings.
     *
     * @return array<string, mixed>
     */
    protected function encodeValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) && array_is_list($value) => ['arrayValue' => [
                'values' => array_map($this->encodeValue(...), $value),
            ]],
            is_array($value) => ['kvlistValue' => ['values' => $this->encodeAttributes($value)]],
            default => ['stringValue' => (string) $value],
        };
    }

    /**
     * Key of the innermost open span that can take children: forked spans are skipped.
     */
    protected function innermost(): ?string
    {
        foreach (array_reverse(array_keys($this->open)) as $key) {
            if (!isset($this->forked[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Wall-clock Unix nanoseconds as a decimal string (microsecond precision) —
     * the framework stamps no timing, so the trace is the clock.
     */
    protected function now(): string
    {
        return (int) (microtime(true) * 1_000_000) . '000';
    }
}
