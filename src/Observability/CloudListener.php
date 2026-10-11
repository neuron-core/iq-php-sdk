<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Observability;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Observability\Deserialized;
use NeuronAI\Agent\Observability\Deserializing;
use NeuronAI\Agent\Observability\Extracted;
use NeuronAI\Agent\Observability\Extracting;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\SchemaGenerated;
use NeuronAI\Agent\Observability\SchemaGeneration;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Agent\Observability\Validating;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Observability\PostProcessed;
use NeuronAI\RAG\Observability\PostProcessing;
use NeuronAI\RAG\Observability\PreProcessed;
use NeuronAI\RAG\Observability\PreProcessing;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\RAG\Observability\Retrieving;
use NeuronAI\Workflow\Observability\BranchEnd;
use NeuronAI\Workflow\Observability\BranchStart;
use NeuronAI\Workflow\Observability\ChannelError;
use NeuronAI\Workflow\Observability\MiddlewareEnd;
use NeuronAI\Workflow\Observability\MiddlewareStart;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronCore\Cloud\Http\Transport;
use Throwable;

use function array_map;
use function count;
use function error_log;
use function getenv;
use function is_array;
use function is_string;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function spl_object_id;
use function str_starts_with;
use function strrchr;
use function strtolower;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_UNICODE;

/**
 * Bridges neuron-ai's event-based observability to OpenTelemetry: pairs the
 * framework's start/stop events into spans (keyed on the emitting object or
 * class, so parallel branches cannot cross wires) and flushes one OTLP trace
 * per run segment on WorkflowEnd. Timing, hierarchy and the OTLP encoding live
 * in {@see Trace}; shipping in {@see TraceExporter}.
 *
 * Spans follow the OpenTelemetry GenAI semantic conventions (development
 * stability — github.com/open-telemetry/semantic-conventions-genai), so any
 * OTel backend reads them, not only Neuron Cloud:
 *
 *   run of an Agent     → `invoke_agent {name}`     INTERNAL
 *   run of a Workflow   → `invoke_workflow {name}`  INTERNAL
 *   inference           → `chat {model}`            CLIENT
 *   tool call           → `execute_tool {tool}`     INTERNAL
 *   RAG retrieval       → `retrieval`               CLIENT
 *
 * The conventions have no vocabulary for the rest — nodes, branches,
 * middleware, RAG processors, structured-output steps — which ship as plain
 * INTERNAL spans. Every span carries its agentic kind in `neuron.span.kind`.
 *
 * The trace is the run: the trace ID derives from the framework's run ID, so
 * every suspend/resume segment of a durable run adds its spans to the same
 * trace, and the workflow ID groups the runs at one address (for an Agent it
 * is the thread, shipped as `gen_ai.conversation.id`).
 *
 * The run's outcome follows the framework's terminal vocabulary: WorkflowEnd
 * alone = completed, plus WorkflowInterrupted = suspended, plus WorkflowError =
 * failed — the span where it happened gets an ERROR status and an `exception`
 * event. A ChannelError is not a failure — the run keeps going — so it is
 * only an `exception` event. Listener exceptions are swallowed: a tracing bug
 * must not break the run.
 */
class CloudListener
{
    /** Truncation limit for message/tool content copied into span attributes. */
    protected const MAX_CONTENT = 4000;

    /**
     * Framework provider classes whose lower-cased short name is not the
     * conventions' `gen_ai.provider.name` value. Every other class name already is.
     */
    protected const PROVIDER_NAMES = [
        'gemini' => 'gcp.gemini',
        'geminivertex' => 'gcp.vertex_ai',
        'anthropicvertex' => 'gcp.vertex_ai',
        'azureopenai' => 'azure.ai.openai',
        'openairesponses' => 'openai',
        'bedrockruntime' => 'aws.bedrock',
        'mistral' => 'mistral_ai',
        'grok' => 'x_ai',
    ];

    protected readonly TraceExporter $exporter;

    /** The trace of the run in flight, null between runs. */
    protected ?Trace $trace = null;

    /** The thread of the Agent run in flight; null for a plain workflow. */
    protected ?string $conversationId = null;

    /** The provider of the Agent run in flight; null for a plain workflow. */
    protected ?AIProviderInterface $provider = null;

    /**
     * @param string|null          $name           The agent/workflow name spans are reported under; the class name when null.
     * @param array<string, mixed> $metadata       Run-level attributes stamped on every span.
     * @param bool                 $captureContent Ship prompts, completions, tool arguments and results
     *                                             (truncated). The conventions make content opt-in; disable
     *                                             it where it must not leave the application.
     */
    public function __construct(
        Transport $transport,
        string $platformUrl,
        string $apiKey,
        protected readonly ?string $name = null,
        protected readonly ?string $userId = null,
        protected readonly ?string $sessionId = null,
        protected readonly array $metadata = [],
        protected readonly bool $captureContent = true,
    ) {
        $this->exporter = new TraceExporter($transport, $platformUrl, $apiKey);
    }

    public function __invoke(ObservabilityEvent $event): void
    {
        try {
            $this->handle($event);
        } catch (Throwable $e) {
            // The dispatcher does not guard listeners; a tracing bug must not
            // break the workflow run.
            error_log('[CloudListener] ' . $e->getMessage());
        }
    }

    protected function handle(ObservabilityEvent $event): void
    {
        match ($event::class) {
            WorkflowStart::class => $this->startRun($event),
            WorkflowInterrupted::class => $this->trace()->markSuspended(),
            WorkflowError::class => $this->trace()->markFailed($event->exception),
            ChannelError::class => $this->trace()->recordException($event->exception),
            WorkflowEnd::class => $this->flush(),

            WorkflowNodeStart::class => $this->start($event, 'node', $this->shortName($event->node)),
            WorkflowNodeEnd::class => $this->stop($event),

            BranchStart::class => $this->start($event, 'branch', "branch.{$event->branchId}"),
            BranchEnd::class => $this->stop($event),

            MiddlewareStart::class => $this->start(
                $event,
                'middleware',
                $this->shortName($event->middleware::class) . ".{$event->phase}",
            ),
            MiddlewareEnd::class => $this->stop($event),

            InferenceStart::class => $this->startInference($event),
            InferenceStop::class => $this->stopInference($event),

            ToolCalling::class => $this->start(
                $event,
                'tool',
                'execute_tool ' . $event->tool->getName(),
                [
                    'gen_ai.operation.name' => 'execute_tool',
                    'gen_ai.tool.name' => $event->tool->getName(),
                    'gen_ai.tool.type' => 'function',
                    'gen_ai.tool.call.id' => $event->tool->getCallId(),
                    'gen_ai.tool.description' => $event->tool->getDescription(),
                    'gen_ai.conversation.id' => $this->conversationId,
                ] + $this->content([
                    'gen_ai.tool.call.arguments' => fn (): string => $this->json($this->truncateStrings($event->tool->getInputs())),
                ]),
                forked: $event->fork,
            ),
            ToolCalled::class => $this->stop($event, $this->content([
                'gen_ai.tool.call.result' => fn (): ?string => $event->tool->hasResult()
                    ? $this->truncate((string) $event->tool->getResult())
                    : null,
            ])),

            Retrieving::class => $this->start(
                $event,
                'retrieval',
                'retrieval',
                ['gen_ai.operation.name' => 'retrieval'] + $this->content([
                    'gen_ai.retrieval.query.text' => fn (): string => $this->truncate((string) ($event->question->getContent() ?? '')),
                ]),
                Trace::KIND_CLIENT,
            ),
            Retrieved::class => $this->stop($event, ['neuron.rag.documents.count' => count($event->documents)] + $this->content([
                'gen_ai.retrieval.documents' => fn (): string => $this->json(array_map(
                    fn (Document $document): array => ['id' => $document->getId(), 'score' => $document->getScore()],
                    $event->documents,
                )),
            ])),

            PreProcessing::class => $this->start($event, 'processor', 'rag.preprocess.' . $this->shortName($event->processor)),
            PreProcessed::class => $this->stop($event),

            PostProcessing::class => $this->start($event, 'processor', 'rag.postprocess.' . $this->shortName($event->processor)),
            PostProcessed::class => $this->stop($event),

            SchemaGeneration::class => $this->start($event, 'structured', 'structured.schema'),
            SchemaGenerated::class => $this->stop($event),

            Extracting::class => $this->start($event, 'structured', 'structured.extract'),
            Extracted::class => $this->stop($event),

            Deserializing::class => $this->start($event, 'structured', 'structured.deserialize'),
            Deserialized::class => $this->stop($event),

            Validating::class => $this->start($event, 'structured', 'structured.validate'),
            Validated::class => $event->violations === []
                ? $this->stop($event)
                : $this->stop(
                    $event,
                    ['neuron.structured.violations' => $this->json($event->violations)],
                    'Structured output validation failed',
                ),

            // MessageSaving/MessageSaved and custom events are deliberately not
            // traced — history writes are noise at trace granularity.
            default => null,
        };
    }

    /**
     * Pairing identity shared by a start event and its stop event, so the span
     * opened by one is closed by the other and parallel branches never cross
     * wires. Each pair's formula is written once, here.
     */
    protected function spanKey(ObservabilityEvent $event): string
    {
        $branch = $event->branchId ?? '';

        return match ($event::class) {
            WorkflowStart::class => 'workflow',
            WorkflowNodeStart::class, WorkflowNodeEnd::class => "node:{$event->node}:{$branch}",
            BranchStart::class, BranchEnd::class => "branch:{$branch}",
            MiddlewareStart::class, MiddlewareEnd::class => 'middleware:' . $event->middleware::class . ":{$event->phase}:{$branch}",
            InferenceStart::class, InferenceStop::class => 'inference:' . $this->sourceId($event),
            ToolCalling::class, ToolCalled::class => 'tool:' . spl_object_id($event->tool),
            Retrieving::class, Retrieved::class => 'rag:retrieval:' . $this->sourceId($event),
            PreProcessing::class, PreProcessed::class => "rag:pre:{$event->processor}:{$branch}",
            PostProcessing::class, PostProcessed::class => "rag:post:{$event->processor}:{$branch}",
            SchemaGeneration::class, SchemaGenerated::class => "so:schema:{$event->class}",
            Extracting::class, Extracted::class => 'so:extract:' . $this->sourceId($event),
            Deserializing::class, Deserialized::class => "so:deserialize:{$event->class}",
            Validating::class, Validated::class => "so:validate:{$event->class}",
            default => throw new LogicException($event::class . ' has no span pairing key.'),
        };
    }

    /**
     * Adopt the run's identity and open the segment's root span: an agent
     * invocation, or a workflow invocation for anything else. It has no stop
     * event — flush closes it last, after every span it wraps.
     */
    protected function startRun(WorkflowStart $event): void
    {
        $this->trace()->identify($event->execution?->runId, $event->execution?->workflowId);

        $name = $this->name ?? ($event->source === null ? null : $this->shortName($event->source::class));

        if ($event->source instanceof Agent) {
            // An Agent's workflow ID is its thread — a real conversation
            // identity, which is the only kind the conventions allow here.
            $this->conversationId = $event->execution?->workflowId;
            $this->provider = $this->providerOf($event->source);

            $this->start($event, 'agent', $this->spanName('invoke_agent', $name), [
                'gen_ai.operation.name' => 'invoke_agent',
                'gen_ai.agent.name' => $name,
                'gen_ai.conversation.id' => $this->conversationId,
            ]);

            return;
        }

        $this->start($event, 'workflow', $this->spanName('invoke_workflow', $name), [
            'gen_ai.operation.name' => 'invoke_workflow',
            'gen_ai.workflow.name' => $name,
        ]);
    }

    protected function startInference(InferenceStart $event): void
    {
        $this->start(
            $event,
            'generation',
            $this->spanName('chat', $this->provider?->getModel()),
            [
                'gen_ai.operation.name' => 'chat',
                'gen_ai.provider.name' => $this->provider === null ? null : $this->providerName($this->provider),
                'gen_ai.request.model' => $this->provider?->getModel(),
                'gen_ai.conversation.id' => $this->conversationId,
            ] + $this->content([
                'gen_ai.input.messages' => fn (): string => $this->json([$this->messagePayload($event->message)]),
            ]),
            Trace::KIND_CLIENT,
        );
    }

    protected function stopInference(InferenceStop $event): void
    {
        $message = $event->response->message();
        $stopReason = $message instanceof AssistantMessage ? $message->stopReason() : null;

        $this->stop($event, [
            'gen_ai.response.finish_reasons' => $stopReason === null ? null : [$stopReason],
        ] + $this->usageAttributes($message->getUsage(), $this->provider) + $this->content([
            'gen_ai.output.messages' => fn (): string => $this->json([$this->messagePayload($message)]),
        ]));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param Trace::KIND_*        $otelKind
     */
    protected function start(
        ObservabilityEvent $event,
        string $kind,
        string $name,
        array $attributes = [],
        int $otelKind = Trace::KIND_INTERNAL,
        bool $forked = false,
    ): void {
        $this->trace()->openSpan(
            $this->spanKey($event),
            $name,
            $otelKind,
            $attributes + ['neuron.span.kind' => $kind, 'neuron.branch.id' => $event->branchId],
            $forked,
        );
    }

    /**
     * A stop event with no run in flight is a stray: nothing to close, no trace to open.
     *
     * @param array<string, mixed> $attributes
     * @param string|null          $error Fails the span with this status message.
     */
    protected function stop(ObservabilityEvent $event, array $attributes = [], ?string $error = null): void
    {
        $this->trace?->closeSpan($this->spanKey($event), $attributes, $error);
    }

    /**
     * The trace of the run in flight, opened on the first event. Events can
     * arrive before WorkflowStart (e.g. an agent run whose listener was
     * attached mid-flight); opening lazily means nothing is lost.
     */
    protected function trace(): Trace
    {
        return $this->trace ??= new Trace(
            getenv('OTEL_SERVICE_NAME') ?: null,
            $this->metadata + ['user.id' => $this->userId, 'session.id' => $this->sessionId],
        );
    }

    /**
     * Ship the segment's spans and reset for the next run.
     */
    public function flush(): void
    {
        if ($this->trace === null) {
            return;
        }

        $trace = $this->trace->close();
        $this->trace = null;
        $this->conversationId = null;
        $this->provider = null;

        $this->exporter->send($trace);
    }

    /**
     * Best-effort provider behind the run's inference events. Nodes hold no
     * services, so it is read off the Agent definition; an agent without one
     * fails on its own, and tracing degrades to provider-less spans.
     */
    protected function providerOf(Agent $agent): ?AIProviderInterface
    {
        try {
            return $agent->getProvider();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The conventions' `gen_ai.provider.name`: a well-known value where one
     * exists, the lower-cased class name otherwise (custom values are allowed).
     */
    protected function providerName(AIProviderInterface $provider): string
    {
        $id = strtolower($this->shortName($provider::class));

        return self::PROVIDER_NAMES[$id] ?? $id;
    }

    /**
     * The conventions require `input_tokens` to include cached tokens.
     * Anthropic is the provider that reports them apart, so they are added
     * back; the cached and reasoning counts ship only when reported.
     *
     * @return array<string, int>
     */
    protected function usageAttributes(?Usage $usage, ?AIProviderInterface $provider): array
    {
        if ($usage === null) {
            return [];
        }

        $cachedApart = $provider !== null
            && str_starts_with(strtolower($this->shortName($provider::class)), 'anthropic');

        $attributes = [
            'gen_ai.usage.input_tokens' => $usage->inputTokens + ($cachedApart ? $usage->cachedInputTokens : 0),
            'gen_ai.usage.output_tokens' => $usage->outputTokens,
        ];
        if ($usage->cachedInputTokens > 0) {
            $attributes['gen_ai.usage.cache_read.input_tokens'] = $usage->cachedInputTokens;
        }
        if ($usage->reasoningTokens > 0) {
            $attributes['gen_ai.usage.reasoning.output_tokens'] = $usage->reasoningTokens;
        }

        return $attributes;
    }

    /**
     * A message in the conventions' `{role, parts}` schema. Media blocks ship
     * as a bare part type: their payload is not text and would dwarf the span.
     *
     * @return array{role: string, parts: list<array<string, mixed>>}
     */
    protected function messagePayload(Message $message): array
    {
        $parts = [];
        foreach ($message->getContentBlocks() as $block) {
            $parts[] = match (true) {
                $block instanceof ReasoningContent => ['type' => 'reasoning', 'content' => $this->truncate($block->getContent())],
                $block instanceof TextContent => ['type' => 'text', 'content' => $this->truncate($block->getContent())],
                default => ['type' => $block->getType()->value],
            };
        }

        if ($message instanceof ToolCallMessage) {
            foreach ($message->getToolCalls() as $call) {
                $parts[] = [
                    'type' => 'tool_call',
                    'id' => $call->getCallId(),
                    'name' => $call->getName(),
                    'arguments' => $this->truncateStrings($call->getInputs()),
                ];
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->getToolCalls() as $call) {
                $parts[] = [
                    'type' => 'tool_call_response',
                    'id' => $call->getCallId(),
                    'response' => $call->hasResult() ? $this->truncate((string) $call->getResult()) : null,
                ];
            }
        }

        return [
            'role' => $message instanceof ToolResultMessage ? 'tool' : $message->getRole(),
            'parts' => $parts,
        ];
    }

    /**
     * Content attributes, resolved only when content capture is on.
     *
     * @param array<string, callable(): ?string> $attributes
     * @return array<string, string|null>
     */
    protected function content(array $attributes): array
    {
        return $this->captureContent
            ? array_map(fn (callable $value): ?string => $value(), $attributes)
            : [];
    }

    /**
     * Structured values ride on span attributes as a JSON string — the form
     * the conventions allow on spans and the one backends parse.
     */
    protected function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * `{operation} {target}`, or the bare operation when the target is unknown.
     */
    protected function spanName(string $operation, ?string $target): string
    {
        return $target === null ? $operation : "{$operation} {$target}";
    }

    protected function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_CONTENT
            ? mb_substr($value, 0, self::MAX_CONTENT) . '…'
            : $value;
    }

    /**
     * Truncate every string leaf so a payload keeps its JSON structure
     * without unbounded content.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    protected function truncateStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $this->truncate($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->truncateStrings($value);
            }
        }

        return $values;
    }

    /**
     * Pairing identity for start/stop events emitted by the same object.
     */
    protected function sourceId(ObservabilityEvent $event): int
    {
        return $event->source === null ? 0 : spl_object_id($event->source);
    }

    protected function shortName(string $class): string
    {
        $pos = strrchr($class, '\\');

        return $pos === false ? $class : substr($pos, 1);
    }
}
