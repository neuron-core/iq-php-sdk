<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Observability;

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
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use Throwable;
use WeakMap;

use function array_map;
use function count;
use function error_log;
use function getenv;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function spl_object_id;
use function strrchr;
use function substr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_UNICODE;

/**
 * Traces the runs of an agent or a workflow, and sends each to IQ.
 *
 * Build it from the SDK root, and subscribe it to an agent or a workflow the
 * application runs itself, with chat(), stream(), run() or events():
 *
 *   $agent->subscribe(ObservabilityEvent::class, $iq->tracer());
 *
 * Bridges neuron-ai's event-based observability to OpenTelemetry: pairs the
 * framework's start/stop events into spans (keyed on the emitting object or
 * class, so parallel branches cannot cross wires) and flushes one OTLP trace
 * per run segment on WorkflowEnd. Timing, hierarchy and the OTLP encoding live
 * in {@see Trace}; shipping in {@see TraceExporter}.
 *
 * Each run in flight has a trace of its own, kept by the run ID Neuron stamps
 * on every event of a segment, so one listener may serve several agents: an
 * agent whose tool runs another agent, a node that runs an agent, two streams
 * read in turns. The WorkflowEnd of a run sends the trace of that run alone.
 * An event that carries no run ID goes to one trace shared by all such events.
 *
 * Spans follow the OpenTelemetry GenAI semantic conventions (development
 * stability — github.com/open-telemetry/semantic-conventions-genai), so any
 * OTel backend reads them, not only IQ:
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
 * alone = completed, plus WorkflowInterrupted = suspended, and a WorkflowEnd
 * whose state is failed = failed — the span where it happened gets an ERROR
 * status. A WorkflowError is an `exception` event and no more: the framework
 * reports it for the failure of the run and for a listener that threw while
 * the run keeps going, and only the end tells which it was. A ChannelError is
 * not a failure either, so it is only an `exception` event. Listener
 * exceptions are swallowed: a tracing bug must not break the run.
 *
 * It takes the settings {@see \NeuronCore\IQ\IQ::enableTracing()}
 * was given when it was got. One got before enableTracing() was called
 * handles no event and sends nothing, so a line that subscribes it sends
 * nothing where tracing is off. Where the API key may be missing, the
 * application asks for the service only when tracing is on.
 *
 * The SDK subscribes one of its own to every workflow it builds to execute,
 * named after the definition, and a run it executes is traced by that one
 * alone: a listener of the application on the same workflow, subscribed by
 * the factory of the definition or by a configure() callback, leaves the run
 * to it and sends nothing for it.
 */
class IQListener
{
    /** Truncation limit for message/tool content copied into span attributes. */
    protected const MAX_CONTENT = 4000;

    /** The key of the run that events carrying no run ID belong to: a run ID is never empty. */
    protected const NO_RUN = '';

    /**
     * The segments a listener of the SDK traces in this process, by the
     * execution context every event of a segment carries. A claim is made at
     * the first event the listener hears of a segment and lasts as long as
     * the segment: Neuron lets go of it once the segment is over, whatever
     * its listeners heard.
     *
     * @var WeakMap<ExecutionContext, true>|null
     */
    protected static ?WeakMap $claimed = null;

    /**
     * The runs in flight, by run ID: the trace of each, and for an Agent run
     * its thread and its provider (null for a plain workflow).
     *
     * @var array<string, array{trace: Trace, conversationId: ?string, provider: ?AIProviderInterface}>
     */
    protected array $runs = [];

    /**
     * The segments whose end this listener heard, for as long as each exists:
     * what Neuron reports of one after its end is not traced.
     *
     * @var WeakMap<ExecutionContext, true>
     */
    protected WeakMap $ended;

    /**
     * @internal built by {@see \NeuronCore\IQ\IQ::tracer()}, and by the SDK for a workflow it executes
     *
     * @param TraceExporter|null   $exporter       null: tracing is off, and the listener handles no event.
     * @param string|null          $name           The agent/workflow name spans are reported under; the class name when null.
     * @param array<string, mixed> $metadata       Run-level attributes stamped on every span.
     * @param bool                 $captureContent Ship prompts, completions, tool arguments and results
     *                                             (truncated). The conventions make content opt-in; disable
     *                                             it where it must not leave the application.
     * @param bool                 $claimsRuns     The SDK's own: the segments it hears are left to it by every other listener.
     */
    public function __construct(
        protected readonly ?TraceExporter $exporter,
        protected readonly ?string $name = null,
        protected readonly ?string $userId = null,
        protected readonly ?string $sessionId = null,
        protected readonly array $metadata = [],
        protected readonly bool $captureContent = true,
        protected readonly bool $claimsRuns = false,
    ) {
        $this->ended = new WeakMap();
    }

    public function __invoke(ObservabilityEvent $event): void
    {
        if (!$this->exporter instanceof \NeuronCore\IQ\Observability\TraceExporter) {
            return;
        }

        try {
            if ($this->follows($event)) {
                $this->handle($event);
            }
        } catch (Throwable $e) {
            // The dispatcher does not guard listeners; a tracing bug must not
            // break the workflow run.
            error_log('[IQListener] ' . $e->getMessage());
        }
    }

    protected function handle(ObservabilityEvent $event): void
    {
        match ($event::class) {
            WorkflowStart::class => $this->startRun($event),
            WorkflowInterrupted::class => $this->trace($event)->markSuspended(),
            WorkflowError::class => $this->recordError($event),
            ChannelError::class => $this->trace($event)->recordException($event->exception),
            WorkflowEnd::class => $this->endRun($event),

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
                    'gen_ai.conversation.id' => $this->conversationId($event),
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
                    'gen_ai.retrieval.query.text' => fn (): string => $this->truncate($event->question->getContent() ?? ''),
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
            WorkflowStart::class, WorkflowError::class => 'workflow',
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
     *
     * The root of a segment that resumes its run carries `iq.run.resumed`, so
     * the platform counts the run once, from its first segment. The first
     * segment executes under attempt 1 and every later one, whatever resumed
     * it, under a higher attempt.
     */
    protected function startRun(WorkflowStart|WorkflowError $event): void
    {
        $this->trace($event)->identify($event->execution?->runId, $event->execution?->workflowId);

        $name = $this->name ?? ($event->source === null ? null : $this->shortName($event->source::class));
        $segment = ['iq.run.resumed' => ($event->execution->executionAttempt ?? 1) > 1 ? true : null];

        if ($event->source instanceof Agent) {
            $run = $this->runOf($event);
            // An Agent's workflow ID is its thread — a real conversation
            // identity, which is the only kind the conventions allow here.
            $this->runs[$run]['conversationId'] = $event->execution?->workflowId;
            $this->runs[$run]['provider'] = $this->providerOf($event->source);

            $this->start($event, 'agent', $this->spanName('invoke_agent', $name), [
                'gen_ai.operation.name' => 'invoke_agent',
                'gen_ai.agent.name' => $name,
                'gen_ai.conversation.id' => $this->conversationId($event),
            ] + $segment);

            return;
        }

        $this->start($event, 'workflow', $this->spanName('invoke_workflow', $name), [
            'gen_ai.operation.name' => 'invoke_workflow',
            'gen_ai.workflow.name' => $name,
        ] + $segment);
    }

    /**
     * A segment that fails while it builds its graph reports no start: the
     * error is the first thing heard of it, and opens the root span that a
     * start would have opened. An error reported under a node opens nothing,
     * and one reported after the end of its segment is not handled at all.
     */
    protected function recordError(WorkflowError $event): void
    {
        if (!isset($this->runs[$this->runOf($event)]) && $event->source instanceof Workflow) {
            $this->startRun($event);
        }

        $this->trace($event)->recordError($event->exception);
    }

    /**
     * The state of the segment's end is the framework's word on how the run
     * ended: an error it reported on the way may have been a listener's. An
     * end with nothing heard before it has no span to fail, and sends nothing.
     * The segment is over for this listener from then on.
     */
    protected function endRun(WorkflowEnd $event): void
    {
        $run = $this->runOf($event);

        if ($event->execution instanceof \NeuronAI\Workflow\ExecutionContext) {
            $this->ended[$event->execution] = true;
        }

        if ($event->state->getStatus() === WorkflowStatus::Failed) {
            ($this->runs[$run]['trace'] ?? null)?->markFailed();
        }

        $this->flush($run);
    }

    protected function startInference(InferenceStart $event): void
    {
        $provider = $this->providerOfRun($event);

        $this->start(
            $event,
            'generation',
            $this->spanName('chat', $provider?->getModel()),
            [
                'gen_ai.operation.name' => 'chat',
                'gen_ai.provider.name' => $provider instanceof \NeuronAI\Providers\AIProviderInterface ? ProviderName::of($provider::class) : null,
                'gen_ai.request.model' => $provider?->getModel(),
                'gen_ai.conversation.id' => $this->conversationId($event),
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
        ] + $this->usageAttributes($message) + $this->content([
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
        $this->trace($event)->openSpan(
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
        ($this->runs[$this->runOf($event)]['trace'] ?? null)?->closeSpan($this->spanKey($event), $attributes, $error);
    }

    /**
     * The run an event belongs to: the run ID Neuron stamps on every event of
     * a segment, or the one key of the events that carry none.
     */
    protected function runOf(ObservabilityEvent $event): string
    {
        return $event->execution->runId ?? self::NO_RUN;
    }

    /**
     * Whether this listener traces the event. A segment whose end it heard is
     * over for it: Neuron reports a listener that threw on that end with an
     * error after it, which neither claims the segment nor opens its run
     * again. The SDK's own listener claims each segment it hears, for as long
     * as the segment exists. Any other listener leaves a segment so claimed
     * to it: it drops what it holds of the run and handles none of its
     * events. One subscribed before the SDK's hears the first event of a
     * segment before the claim, and its WorkflowEnd while the claim stands.
     */
    protected function follows(ObservabilityEvent $event): bool
    {
        $segment = $event->execution;

        if (!$segment instanceof \NeuronAI\Workflow\ExecutionContext) {
            return true;
        }

        if (isset($this->ended[$segment])) {
            return false;
        }

        $claimed = self::$claimed ??= new WeakMap();

        if ($this->claimsRuns) {
            $claimed[$segment] = true;

            return true;
        }

        if (isset($claimed[$segment])) {
            unset($this->runs[$this->runOf($event)]);

            return false;
        }

        return true;
    }

    /**
     * The trace of the event's run, opened on its first event. Events can
     * arrive before WorkflowStart (e.g. an agent run whose listener was
     * attached mid-flight); opening lazily means nothing is lost.
     */
    protected function trace(ObservabilityEvent $event): Trace
    {
        return ($this->runs[$this->runOf($event)] ??= [
            'trace' => new Trace(
                getenv('OTEL_SERVICE_NAME') ?: null,
                $this->metadata + ['user.id' => $this->userId, 'session.id' => $this->sessionId],
            ),
            'conversationId' => null,
            'provider' => null,
        ])['trace'];
    }

    /**
     * The thread of the event's run, when it is an Agent run.
     */
    protected function conversationId(ObservabilityEvent $event): ?string
    {
        return $this->runs[$this->runOf($event)]['conversationId'] ?? null;
    }

    /**
     * The provider of the event's run, when it is an Agent run.
     */
    protected function providerOfRun(ObservabilityEvent $event): ?AIProviderInterface
    {
        return $this->runs[$this->runOf($event)]['provider'] ?? null;
    }

    /**
     * Ship the spans of one run's segment and forget the run: the other runs
     * in flight are untouched.
     */
    protected function flush(string $run): void
    {
        $trace = $this->runs[$run]['trace'] ?? null;
        unset($this->runs[$run]);

        if ($trace === null || $trace->isEmpty()) {
            return;
        }

        $this->exporter?->send($trace->close());
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
     * The conventions require `input_tokens` to include cached tokens, as
     * Neuron's usage already does for every provider; the cached, cache write
     * and reasoning counts ship only when reported. Neuron's usage has no
     * field for the cache writes: they are in the metadata of the message. So
     * is what a provider's own tool read, which is billed as input and so is
     * added to `input_tokens`.
     *
     * @return array<string, int>
     */
    protected function usageAttributes(Message $message): array
    {
        $usage = $message->getUsage();
        if (!$usage instanceof \NeuronAI\Chat\Messages\Usage) {
            return [];
        }

        $toolInputs = $message->getMetadata('toolInputTokens');

        $attributes = [
            'gen_ai.usage.input_tokens' => $usage->inputTokens + (is_int($toolInputs) ? $toolInputs : 0),
            'gen_ai.usage.output_tokens' => $usage->outputTokens,
        ];
        if ($usage->cachedInputTokens > 0) {
            $attributes['gen_ai.usage.cache_read.input_tokens'] = $usage->cachedInputTokens;
        }
        $cacheWrites = $message->getMetadata('cacheWriteTokens');
        if (is_int($cacheWrites) && $cacheWrites > 0) {
            $attributes['gen_ai.usage.cache_creation.input_tokens'] = $cacheWrites;
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
