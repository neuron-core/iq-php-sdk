<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Observability;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Observability\IQListener;
use NeuronCore\IQ\Observability\Trace;
use NeuronCore\IQ\Observability\TraceExporter;
use NeuronCore\IQ\Runtime\PlatformClient;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\NarratingWorkflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Tracing\AskResearcherTool;
use NeuronCore\IQ\Tests\Stub\Tracing\Researcher;
use NeuronCore\IQ\Tests\Stub\Tracing\UnbuildableWorkflow;
use NeuronCore\IQ\Tests\Stub\Tracing\WeatherTool;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedStream;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Tracing\Otlp;
use NeuronCore\IQ\Tests\Support\Units\CapturesErrorLog;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use WeakReference;

use function array_column;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function json_encode;
use function preg_grep;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The listener on a workflow: what Neuron reports while a segment executes
 * becomes one OpenTelemetry trace, sent when the segment ends.
 */
class IQListenerTest extends IQTestCase
{
    use CapturesErrorLog;

    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const CONTENT = ['gen_ai.input.messages', 'gen_ai.output.messages', 'gen_ai.tool.call.arguments', 'gen_ai.tool.call.result'];

    protected const ANTHROPIC_MODEL = 'claude-sonnet-5-5';

    /**
     * The usage of the Messages API on a prompt mostly served from the cache:
     * input_tokens is only the uncached remainder of it.
     */
    protected const ANTHROPIC_USAGE = [
        'input_tokens' => 120,
        'cache_creation_input_tokens' => 2000,
        'cache_read_input_tokens' => 9000,
        'cache_creation' => ['ephemeral_5m_input_tokens' => 2000, 'ephemeral_1h_input_tokens' => 0],
        'output_tokens' => 48,
    ];

    protected ScriptedHttpClient $platform;

    protected function setUp(): void
    {
        $this->platform = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $this->benchOn(TestBench::IN_MEMORY);
    }

    public function test_a_segment_sends_one_trace_to_the_platform_when_it_ends(): void
    {
        $state = $this->traced(new CompletingWorkflow($this->bench->executions))->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(['https://platform.test/v1/traces'], array_map(static fn (HttpRequest $request): string => $request->uri, $this->platform->received()));
    }

    public function test_the_trace_of_a_workflow_has_a_root_span_named_after_it_and_a_span_for_each_node_under_it(): void
    {
        $this->traced(new CompletingWorkflow($this->bench->executions))->run();

        [$trace] = $this->traces();
        $root = Otlp::span($trace, 'invoke_workflow order-fulfilment');
        $node = Otlp::span($trace, 'CompleteNode');
        self::assertSame(['CompleteNode', 'invoke_workflow order-fulfilment'], Otlp::names($trace));
        self::assertSame([$root['spanId'], false], [$node['parentSpanId'], isset($root['parentSpanId'])]);
        self::assertSame(
            ['invoke_workflow', 'order-fulfilment', 'workflow', 'node'],
            [
                $root['attributes']['gen_ai.operation.name'],
                $root['attributes']['gen_ai.workflow.name'],
                $root['attributes']['neuron.span.kind'],
                $node['attributes']['neuron.span.kind'],
            ],
        );
        self::assertSame([Trace::KIND_INTERNAL, Trace::KIND_INTERNAL], [$root['kind'], $node['kind']]);
    }

    public function test_a_listener_that_was_given_no_name_names_the_spans_after_the_class(): void
    {
        $this->traced(new CompletingWorkflow($this->bench->executions), $this->listener(name: null))->run();

        self::assertContains('invoke_workflow CompletingWorkflow', Otlp::names($this->traces()[0]));
    }

    public function test_the_trace_is_the_run_its_id_comes_from_the_run_id_and_every_span_names_the_run_and_the_workflow_id(): void
    {
        $this->traced(new CompletingWorkflow($this->bench->executions))->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        foreach (Otlp::spans($this->traces()[0]) as $span) {
            self::assertSame(
                [substr(hash('sha256', self::RUN_ID), 0, 32), self::RUN_ID, self::WORKFLOW_ID],
                [$span['traceId'], $span['attributes']['neuron.run.id'], $span['attributes']['neuron.workflow.id']],
            );
        }
    }

    public function test_the_attributes_the_listener_was_given_are_on_every_span(): void
    {
        $this->traced(
            new CompletingWorkflow($this->bench->executions),
            $this->listener(metadata: ['deployment.environment' => 'production']),
        )->run();

        self::assertSame(
            ['production', 'production'],
            array_map(static fn (array $span): mixed => $span['attributes']['deployment.environment'] ?? null, Otlp::spans($this->traces()[0])),
        );
    }

    public function test_a_run_that_completes_is_traced_as_completed_with_no_failed_span(): void
    {
        $this->traced(new CompletingWorkflow($this->bench->executions))->run();

        self::assertSame('completed', $this->root()['attributes']['neuron.run.status']);
        self::assertSame([], array_column(Otlp::spans($this->traces()[0]), 'status'));
    }

    public function test_a_run_that_stops_at_a_wait_is_traced_as_suspended(): void
    {
        $state = $this->traced(new WaitingWorkflow($this->bench->executions))->run();

        self::assertSame(WorkflowStatus::Suspended, $state->getStatus());
        self::assertSame('suspended', $this->root()['attributes']['neuron.run.status']);
    }

    public function test_a_run_whose_node_throws_is_traced_as_failed_with_the_class_and_the_message_of_what_it_threw(): void
    {
        try {
            $this->traced(new FlakyWorkflow($this->bench->executions))->run();
            self::fail('The exception of the node was not thrown.');
        } catch (NodeFailed) {
        }

        $root = $this->root();
        self::assertSame('failed', $root['attributes']['neuron.run.status']);
        self::assertSame(['code' => 2, 'message' => 'The flaky node failed on purpose.'], $root['status']);
        self::assertSame(NodeFailed::class, $root['attributes']['error.type']);
        self::assertSame(
            [['exception', NodeFailed::class, 'The flaky node failed on purpose.']],
            array_map(
                static fn (array $event): array => [$event['name'], $event['attributes']['exception.type'], $event['attributes']['exception.message']],
                $root['events'],
            ),
        );
    }

    /**
     * Neuron reports a listener that threw with the event it reports a failed run with, and the run goes on.
     * The trace takes how the run ended from the end of the segment.
     */
    public function test_a_listener_of_the_application_that_throws_while_the_run_completes_does_not_make_the_trace_say_failed(): void
    {
        $workflow = $this->traced(new CompletingWorkflow($this->bench->executions))
            ->subscribe(WorkflowNodeStart::class, static fn () => throw new RuntimeException('The audit log is unreachable.'));

        $state = $workflow->run();

        $spans = Otlp::spans($this->traces()[0]);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('completed', $this->root()['attributes']['neuron.run.status']);
        self::assertSame([], array_column($spans, 'status'));
        self::assertSame(
            ['The audit log is unreachable.'],
            array_map(
                static fn (array $event): string => $event['attributes']['exception.message'],
                array_merge(...array_column($spans, 'events')),
            ),
        );
    }

    /**
     * Neuron reports no start for a segment that fails while its graph is built: the error is all the listener hears.
     */
    public function test_a_segment_that_fails_before_it_reports_its_start_is_still_traced_as_failed_under_its_run(): void
    {
        try {
            $this->traced(new UnbuildableWorkflow())->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
            self::fail('The exception of the graph was not thrown.');
        } catch (RuntimeException) {
        }

        [$trace] = $this->traces();
        $root = Otlp::span($trace, 'invoke_workflow order-fulfilment');
        self::assertSame(['invoke_workflow order-fulfilment'], Otlp::names($trace));
        self::assertSame(
            ['failed', RuntimeException::class, self::RUN_ID, self::WORKFLOW_ID, substr(hash('sha256', self::RUN_ID), 0, 32)],
            [
                $root['attributes']['neuron.run.status'],
                $root['attributes']['error.type'],
                $root['attributes']['neuron.run.id'],
                $root['attributes']['neuron.workflow.id'],
                $root['traceId'],
            ],
        );
        self::assertSame('The graph cannot be built.', $root['events'][0]['attributes']['exception.message']);
    }

    /**
     * A listener the factory of the application subscribed is called before the one of the SDK, and Neuron stops
     * at a listener that throws. It then reports what was thrown under the workflow, which the SDK does hear.
     */
    public function test_a_listener_of_the_application_that_throws_at_the_start_of_a_segment_does_not_keep_the_trace_from_its_run(): void
    {
        $workflow = (new CompletingWorkflow($this->bench->executions))
            ->subscribe(WorkflowStart::class, static fn () => throw new RuntimeException('The audit log is unreachable.'));

        $this->traced($workflow)->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        [$trace] = $this->traces();
        $root = Otlp::span($trace, 'invoke_workflow order-fulfilment');
        self::assertSame(['CompleteNode', 'invoke_workflow order-fulfilment'], Otlp::names($trace));
        self::assertSame($root['spanId'], Otlp::span($trace, 'CompleteNode')['parentSpanId']);
        self::assertSame(['completed', self::RUN_ID], [$root['attributes']['neuron.run.status'], $root['attributes']['neuron.run.id']]);
        self::assertArrayNotHasKey('status', $root);
    }

    public function test_an_error_reported_under_a_node_before_anything_else_opens_no_span_and_sends_nothing(): void
    {
        $listener = $this->listener();
        $ofANode = new WorkflowError(new RuntimeException('The audit log is unreachable.'), false);
        $ofANode->source = new CompleteNode($this->bench->executions);

        $listener($ofANode);
        $listener(new WorkflowEnd(new WorkflowState()));

        self::assertSame([], $this->platform->received());
    }

    public function test_the_end_of_a_failed_segment_of_which_nothing_else_was_heard_sends_nothing(): void
    {
        $failed = new WorkflowState();
        $failed->markAsFailed();

        $this->listener()(new WorkflowEnd($failed));

        self::assertSame([], $this->platform->received());
    }

    public function test_the_two_segments_of_a_run_that_waited_send_two_traces_with_one_trace_id_and_spans_of_their_own(): void
    {
        $this->traced(new WaitingWorkflow($this->bench->executions))->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
        $this->traced(new WaitingWorkflow($this->bench->executions))->run(ExecutionRequest::signal('payment.received', []));

        [$first, $second] = array_map(static fn (array $trace): array => Otlp::span($trace, 'invoke_workflow order-fulfilment'), $this->traces());
        self::assertSame($first['traceId'], $second['traceId']);
        self::assertNotSame($first['spanId'], $second['spanId']);
        self::assertSame([false, false], [isset($first['parentSpanId']), isset($second['parentSpanId'])]);
    }

    public function test_only_the_segments_after_the_first_of_a_run_say_they_resume_it(): void
    {
        $this->traced(new WaitingWorkflow($this->bench->executions))->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
        $this->traced(new WaitingWorkflow($this->bench->executions))->run(ExecutionRequest::signal('payment.received', []));

        [$first, $second] = array_map(static fn (array $trace): array => Otlp::span($trace, 'invoke_workflow order-fulfilment')['attributes'], $this->traces());
        self::assertArrayNotHasKey('iq.run.resumed', $first);
        self::assertTrue($second['iq.run.resumed']);
    }

    public function test_a_listener_starts_a_new_trace_for_the_next_run_of_its_workflow(): void
    {
        $workflow = $this->traced(new CompletingWorkflow($this->bench->executions));

        $workflow->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
        $workflow->run(ExecutionRequest::start(new StartEvent(), '1-another-run'));

        self::assertCount(2, array_unique(array_map(static fn (array $trace): string => Otlp::spans($trace)[0]['traceId'], $this->traces())));
        self::assertSame([2, 2], array_map(static fn (array $trace): int => count(Otlp::spans($trace)), $this->traces()));
    }

    /**
     * The run of the researcher starts and ends inside the tool call of the agent that asked it.
     */
    public function test_one_listener_on_an_agent_whose_tool_runs_another_agent_sends_a_trace_of_each_run_under_its_own_trace_id_and_thread(): void
    {
        [$supportRun, $researcherRun] = $this->askTheResearcher($this->listener(name: null));

        $traces = $this->traces();
        self::assertCount(2, $traces);
        self::assertNotSame($supportRun, $researcherRun);
        foreach ([[$traces[0], $researcherRun, 'thread:researcher'], [$traces[1], $supportRun, 'thread:support']] as [$trace, $runId, $thread]) {
            $spans = Otlp::spans($trace);
            self::assertSame([substr(hash('sha256', $runId), 0, 32)], array_values(array_unique(array_column($spans, 'traceId'))));
            self::assertSame([$runId], array_values(array_unique(array_map(static fn (array $span): mixed => $span['attributes']['neuron.run.id'], $spans))));
            self::assertSame([$thread], array_values(array_unique(array_filter(array_map(
                static fn (array $span): mixed => $span['attributes']['gen_ai.conversation.id'] ?? null,
                $spans,
            )))));
        }
    }

    public function test_the_trace_of_an_agent_whose_tool_runs_another_agent_on_the_same_listener_is_sent_whole_when_its_own_run_ends(): void
    {
        $this->askTheResearcher($this->listener(name: null));

        [$researcher, $support] = $this->traces();
        self::assertSame([['invoke_agent Researcher'], ['invoke_agent Agent']], [$this->rootsOf($researcher), $this->rootsOf($support)]);
        self::assertCount(1, preg_grep('/^chat fake$/', Otlp::names($researcher)) ?: []);
        // The answer the agent gives once the tool returned is in the trace of its run, under its root.
        self::assertCount(2, preg_grep('/^chat fake$/', Otlp::names($support)) ?: []);
        self::assertContains('execute_tool ask_researcher', Otlp::names($support));
        self::assertNotContains('invoke_agent Researcher', Otlp::names($support));
    }

    public function test_one_listener_on_two_streams_read_in_turns_sends_a_trace_of_each_with_the_spans_of_its_own_run(): void
    {
        $listener = $this->listener();
        $first = $this->traced(new NarratingWorkflow($this->bench->executions), $listener)
            ->events(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
        $second = $this->traced(new NarratingWorkflow($this->bench->executions), $listener, 'order:456')
            ->events(ExecutionRequest::start(new StartEvent(), '1-another-run'));

        $first->current();
        $second->current();
        while ($first->valid() || $second->valid()) {
            if ($first->valid()) {
                $first->next();
            }
            if ($second->valid()) {
                $second->next();
            }
        }

        $traces = $this->traces();
        self::assertCount(2, $traces);
        foreach ([[$traces[0], self::RUN_ID, self::WORKFLOW_ID], [$traces[1], '1-another-run', 'order:456']] as [$trace, $runId, $workflowId]) {
            $spans = Otlp::spans($trace);
            self::assertSame(['NarrateNode', 'invoke_workflow order-fulfilment'], Otlp::names($trace));
            self::assertSame(['invoke_workflow order-fulfilment'], $this->rootsOf($trace));
            foreach ($spans as $span) {
                self::assertSame(
                    [substr(hash('sha256', $runId), 0, 32), $runId, $workflowId],
                    [$span['traceId'], $span['attributes']['neuron.run.id'], $span['attributes']['neuron.workflow.id']],
                );
            }
        }
    }

    /**
     * A listener of the application subscribed before the one of the SDK hears the first event of the segment before
     * the SDK claims the run, and the end of the segment while the claim stands. One subscribed after it hears every
     * event of the run claimed.
     */
    public function test_listeners_of_the_application_on_a_workflow_the_sdk_traces_leave_the_run_to_it_and_send_nothing(): void
    {
        $workflow = (new CompletingWorkflow($this->bench->executions))->subscribe(ObservabilityEvent::class, $this->listener(name: null));
        $workflow = $this->traced($workflow, $this->listener(claimsRuns: true))
            ->subscribe(ObservabilityEvent::class, $this->listener(name: null));

        $workflow->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        self::assertCount(1, $this->traces());
        self::assertSame(['CompleteNode', 'invoke_workflow order-fulfilment'], Otlp::names($this->traces()[0]));
    }

    /**
     * The claim lasts as long as the segment: the next segment of the run is claimed again by its own listener.
     */
    public function test_a_listener_of_the_application_leaves_every_segment_of_a_run_to_the_sdk(): void
    {
        foreach ([ExecutionRequest::start(new StartEvent(), self::RUN_ID), ExecutionRequest::signal('payment.received', [])] as $request) {
            $workflow = (new WaitingWorkflow($this->bench->executions))->subscribe(ObservabilityEvent::class, $this->listener(name: null));
            $this->traced($workflow, $this->listener(claimsRuns: true))->run($request);
        }

        self::assertSame(
            [['invoke_workflow order-fulfilment'], ['invoke_workflow order-fulfilment']],
            array_map($this->rootsOf(...), $this->traces()),
        );
    }

    /**
     * The claim of the SDK ends with the segment it executed: a segment of the same run that it does not execute
     * is the application's to trace.
     */
    public function test_a_segment_the_sdk_does_not_execute_of_a_run_it_traced_before_is_traced_by_a_listener_of_the_application(): void
    {
        $sdk = (new WaitingWorkflow($this->bench->executions))->subscribe(ObservabilityEvent::class, $this->listener(name: null));
        $this->traced($sdk, $this->listener(claimsRuns: true))->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        $this->traced(new WaitingWorkflow($this->bench->executions), $this->listener(name: null))
            ->run(ExecutionRequest::signal('payment.received', []));

        self::assertSame(
            [['invoke_workflow order-fulfilment'], ['invoke_workflow WaitingWorkflow']],
            array_map($this->rootsOf(...), $this->traces()),
        );
    }

    /**
     * Neuron reports a listener that threw on the end of a segment with an error, after the end. A configure()
     * callback subscribes its listener after the one of the SDK, which has sent its trace by then. The claim of the
     * SDK lasts as long as the segment, and nothing holds the segment once it is over.
     */
    public function test_a_listener_that_throws_at_the_end_of_a_segment_the_sdk_traced_leaves_the_next_segment_of_the_run_to_the_application(): void
    {
        $segment = null;
        $this->traced(new WaitingWorkflow($this->bench->executions), $this->listener(claimsRuns: true))
            ->subscribe(WorkflowStart::class, static function (WorkflowStart $event) use (&$segment): void {
                $segment = WeakReference::create($event->execution);
            })
            ->subscribe(WorkflowEnd::class, static fn () => throw new RuntimeException('The audit log is unreachable.'))
            ->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        $this->traced(new WaitingWorkflow($this->bench->executions), $this->listener(name: null))
            ->run(ExecutionRequest::signal('payment.received', []));

        self::assertSame(
            [['invoke_workflow order-fulfilment'], ['invoke_workflow WaitingWorkflow']],
            array_map($this->rootsOf(...), $this->traces()),
        );
        self::assertInstanceOf(WeakReference::class, $segment);
        self::assertNull($segment->get(), 'The segment the SDK claimed is still held.');
    }

    /**
     * A listener the factory subscribed before the one of the SDK that throws on the end of a segment stops Neuron
     * there: the SDK never hears that end, and sends nothing for the segment. Its claim ends with the segment all
     * the same.
     */
    public function test_a_listener_that_throws_at_the_end_of_a_segment_before_the_sdk_hears_it_leaves_the_next_segment_of_the_run_to_the_application(): void
    {
        $segment = null;
        $sdk = (new WaitingWorkflow($this->bench->executions))
            ->subscribe(WorkflowStart::class, static function (WorkflowStart $event) use (&$segment): void {
                $segment = WeakReference::create($event->execution);
            })
            ->subscribe(WorkflowEnd::class, static fn () => throw new RuntimeException('The audit log is unreachable.'));
        $this->traced($sdk, $this->listener(claimsRuns: true))->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));

        $this->traced(new WaitingWorkflow($this->bench->executions), $this->listener(name: null))
            ->run(ExecutionRequest::signal('payment.received', []));

        self::assertSame([['invoke_workflow WaitingWorkflow']], array_map($this->rootsOf(...), $this->traces()));
        self::assertInstanceOf(WeakReference::class, $segment);
        self::assertNull($segment->get(), 'The segment the SDK claimed is still held.');
    }

    /**
     * A segment whose end the listener heard is over for it: the error Neuron reports after the end, for a listener
     * that threw on it, opens no run that the next segment of the run would send.
     */
    public function test_an_error_reported_after_the_end_of_a_segment_is_in_no_trace_the_listener_sends(): void
    {
        $listener = $this->listener(name: null);

        foreach ([ExecutionRequest::start(new StartEvent(), self::RUN_ID), ExecutionRequest::signal('payment.received', [])] as $request) {
            $this->traced(new WaitingWorkflow($this->bench->executions), $listener)
                ->subscribe(WorkflowEnd::class, static fn () => throw new RuntimeException('The audit log is unreachable.'))
                ->run($request);
        }

        $traces = $this->traces();
        self::assertSame([['invoke_workflow WaitingWorkflow'], ['invoke_workflow WaitingWorkflow']], array_map($this->rootsOf(...), $traces));
        self::assertSame([], array_merge(...array_column(array_merge(...array_map(Otlp::spans(...), $traces)), 'events')));
    }

    public function test_the_trace_of_an_agent_has_a_root_span_of_the_agent_a_client_span_for_each_inference_and_a_span_for_each_tool_call(): void
    {
        $this->chatAboutTheWeather($this->listener(name: 'support-agent'));

        [$trace] = $this->traces();
        $root = Otlp::span($trace, 'invoke_agent support-agent');
        $inference = Otlp::span($trace, 'chat fake');
        $tool = Otlp::span($trace, 'execute_tool weather');
        self::assertCount(2, preg_grep('/^chat fake$/', Otlp::names($trace)) ?: []);
        self::assertSame(
            ['invoke_agent', 'support-agent', 'agent', 'thread:1'],
            [
                $root['attributes']['gen_ai.operation.name'],
                $root['attributes']['gen_ai.agent.name'],
                $root['attributes']['neuron.span.kind'],
                $root['attributes']['gen_ai.conversation.id'],
            ],
        );
        self::assertSame(
            [Trace::KIND_CLIENT, 'chat', 'fake', 'fakeaiprovider', 'generation'],
            [
                $inference['kind'],
                $inference['attributes']['gen_ai.operation.name'],
                $inference['attributes']['gen_ai.request.model'],
                $inference['attributes']['gen_ai.provider.name'],
                $inference['attributes']['neuron.span.kind'],
            ],
        );
        self::assertSame(
            ['execute_tool', 'weather', 'call_1', 'tool'],
            [
                $tool['attributes']['gen_ai.operation.name'],
                $tool['attributes']['gen_ai.tool.name'],
                $tool['attributes']['gen_ai.tool.call.id'],
                $tool['attributes']['neuron.span.kind'],
            ],
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function chatAndStream(): array
    {
        return ['a chat' => [false], 'a stream' => [true]];
    }

    /**
     * Neuron adds the cache reads and writes Anthropic reports apart back into the input tokens: the span sends them
     * as the usage of the message holds them, the usage the output of an evaluation sends for the same call.
     */
    #[DataProvider('chatAndStream')]
    public function test_the_input_tokens_of_an_anthropic_inference_are_the_whole_prompt_with_each_cached_token_counted_once(bool $stream): void
    {
        $message = $this->inferenceWith(new Anthropic('key', self::ANTHROPIC_MODEL, httpClient: $this->anthropicApi()), $stream);

        $attributes = Otlp::span($this->traces()[0], 'chat ' . self::ANTHROPIC_MODEL)['attributes'];
        self::assertSame(
            [120 + 2000 + 9000, 9000, 2000, 48],
            [
                $attributes['gen_ai.usage.input_tokens'],
                $attributes['gen_ai.usage.cache_read.input_tokens'],
                $attributes['gen_ai.usage.cache_creation.input_tokens'],
                $attributes['gen_ai.usage.output_tokens'],
            ],
        );
        self::assertSame(Trajectory::fromMessages([$message])->usage()->inputTokens, $attributes['gen_ai.usage.input_tokens']);
    }

    #[DataProvider('chatAndStream')]
    public function test_an_anthropic_inference_that_wrote_nothing_to_the_cache_sends_no_cache_writes(bool $stream): void
    {
        $usage = ['cache_creation_input_tokens' => 0, 'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 0]] + self::ANTHROPIC_USAGE;

        $this->inferenceWith(new Anthropic('key', self::ANTHROPIC_MODEL, httpClient: $this->anthropicApi($usage)), $stream);

        $attributes = Otlp::span($this->traces()[0], 'chat ' . self::ANTHROPIC_MODEL)['attributes'];
        self::assertSame(120 + 9000, $attributes['gen_ai.usage.input_tokens']);
        self::assertArrayNotHasKey('gen_ai.usage.cache_creation.input_tokens', $attributes);
    }

    public function test_what_a_provider_tool_read_is_sent_as_input_tokens(): void
    {
        $answer = (new AssistantMessage('Done.'))->setUsage(new Usage(27, 45));
        $answer->addMetadata('toolInputTokens', 10309);

        $message = $this->inferenceWith(new FakeAIProvider($answer));

        $attributes = Otlp::span($this->traces()[0], 'chat fake')['attributes'];
        self::assertSame(27 + 10309, $attributes['gen_ai.usage.input_tokens']);
        self::assertSame(27, $message->getUsage()->inputTokens);
    }

    public function test_the_input_tokens_of_an_openai_inference_are_the_prompt_tokens_it_reports_with_the_cached_ones_in_them(): void
    {
        $api = new ScriptedHttpClient(new HttpResponse(200, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Sunny in Rome.'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 11120,
                'completion_tokens' => 48,
                'prompt_tokens_details' => ['cached_tokens' => 9000],
                'completion_tokens_details' => ['reasoning_tokens' => 12],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->inferenceWith(new OpenAI('key', 'gpt-5', httpClient: $api));

        $attributes = Otlp::span($this->traces()[0], 'chat gpt-5')['attributes'];
        self::assertSame(
            [11120, 9000, 48, 12],
            [
                $attributes['gen_ai.usage.input_tokens'],
                $attributes['gen_ai.usage.cache_read.input_tokens'],
                $attributes['gen_ai.usage.output_tokens'],
                $attributes['gen_ai.usage.reasoning.output_tokens'],
            ],
        );
        self::assertArrayNotHasKey('gen_ai.usage.cache_creation.input_tokens', $attributes);
    }

    public function test_a_listener_that_captures_content_sends_the_prompts_the_completions_and_what_a_tool_was_given_and_returned(): void
    {
        $this->chatAboutTheWeather($this->listener());

        [$trace] = $this->traces();
        $tool = Otlp::span($trace, 'execute_tool weather');
        self::assertStringContainsString('What is the weather in Rome?', Otlp::span($trace, 'chat fake')['attributes']['gen_ai.input.messages']);
        self::assertStringContainsString('Sunny in Rome.', json_encode($trace, JSON_THROW_ON_ERROR));
        self::assertSame(['{"city":"Rome"}', 'Sunny in Rome'], [$tool['attributes']['gen_ai.tool.call.arguments'], $tool['attributes']['gen_ai.tool.call.result']]);
    }

    public function test_a_listener_that_does_not_capture_content_sends_no_prompt_no_completion_and_nothing_of_a_tool_call_but_its_name(): void
    {
        $this->chatAboutTheWeather($this->listener(captureContent: false));

        [$trace] = $this->traces();
        $sent = array_merge(...array_map(static fn (array $span): array => array_keys($span['attributes']), Otlp::spans($trace)));
        self::assertSame([], array_values(array_intersect(self::CONTENT, $sent)));
        // The question, the argument of the tool, its result and the answer all name the city.
        self::assertStringNotContainsString('Rome', json_encode($trace, JSON_THROW_ON_ERROR));
        self::assertContains('execute_tool weather', Otlp::names($trace));
    }

    /**
     * Content is what the model and the tools were given and gave. What a node threw is not part of it.
     */
    public function test_the_message_and_the_stack_trace_of_an_exception_are_sent_whether_content_is_captured_or_not(): void
    {
        try {
            $this->traced(new FlakyWorkflow($this->bench->executions), $this->listener(captureContent: false))->run();
        } catch (NodeFailed) {
        }

        $event = $this->root()['events'][0]['attributes'];
        self::assertSame('The flaky node failed on purpose.', $event['exception.message']);
        self::assertStringContainsString('FlakyNode', $event['exception.stacktrace']);
    }

    public function test_a_platform_that_refuses_the_trace_changes_nothing_for_the_run(): void
    {
        $this->platform = new ScriptedHttpClient(new HttpResponse(503, ''));

        $logged = self::loggedBy(function () use (&$state): void {
            $state = $this->traced(new CompletingWorkflow($this->bench->executions))->run();
        });

        self::assertSame(WorkflowStatus::Completed, $state?->getStatus());
        self::assertStringContainsString('[TraceExporter] Failed to send trace', $logged);
    }

    public function test_what_the_listener_itself_throws_is_logged_and_changes_nothing_for_the_run(): void
    {
        $broken = new class (new PlatformClient($this->platform, TestBench::PLATFORM_URL, TestBench::API_KEY)) extends TraceExporter {
            public function send(array $trace): void
            {
                throw new RuntimeException('The exporter is broken.');
            }
        };

        $logged = self::loggedBy(function () use (&$state, $broken): void {
            $state = $this->traced(new CompletingWorkflow($this->bench->executions), new IQListener($broken))->run();
        });

        self::assertSame(WorkflowStatus::Completed, $state?->getStatus());
        self::assertStringContainsString('[IQListener] The exporter is broken.', $logged);
    }

    public function test_the_end_of_a_node_that_no_run_in_flight_opened_sends_nothing(): void
    {
        $listener = $this->listener();

        $listener(new WorkflowNodeEnd(CompleteNode::class, new WorkflowState()));
        $listener(new WorkflowEnd(new WorkflowState()));

        self::assertSame([], $this->platform->received());
    }

    /**
     * @param bool $claimsRuns a listener as the SDK subscribes it to a workflow it executes
     */
    protected function listener(
        ?string $name = 'order-fulfilment',
        array $metadata = [],
        bool $captureContent = true,
        bool $claimsRuns = false,
    ): IQListener {
        return new IQListener(
            new TraceExporter(new PlatformClient($this->platform, TestBench::PLATFORM_URL, TestBench::API_KEY)),
            $name,
            metadata: $metadata,
            captureContent: $captureContent,
            claimsRuns: $claimsRuns,
        );
    }

    /**
     * A workflow on the store of the bench, with the listener subscribed as the SDK subscribes it.
     */
    protected function traced(Workflow $workflow, ?IQListener $listener = null, string $workflowId = self::WORKFLOW_ID): Workflow
    {
        return $workflow
            ->for($workflowId)
            ->setPersistence(($this->bench->persistence)())
            ->subscribe(ObservabilityEvent::class, $listener ?? $this->listener());
    }

    /**
     * One turn of an agent: the model asks for the weather tool, the tool runs, the model answers.
     */
    protected function chatAboutTheWeather(IQListener $listener): void
    {
        $model = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('weather', 'call_1', ['city' => 'Rome'])]),
            new AssistantMessage('Sunny in Rome.'),
        );
        $agent = Agent::make()
            ->setAiProvider($model)
            ->addTool(new WeatherTool())
            ->setPersistence(($this->bench->persistence)())
            ->setThreadId('thread:1')
            ->subscribe(ObservabilityEvent::class, $listener);

        $agent->chat(new UserMessage('What is the weather in Rome?'))->getMessage();
    }

    /**
     * One turn of an agent on that provider.
     *
     * @return Message the answer of the provider
     */
    protected function inferenceWith(AIProviderInterface $provider, bool $stream = false): Message
    {
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setPersistence(($this->bench->persistence)())
            ->setThreadId('thread:1')
            ->subscribe(ObservabilityEvent::class, $this->listener());
        $question = new UserMessage('What is the weather in Rome?');

        if (!$stream) {
            return $agent->chat($question)->getMessage();
        }

        $chunks = $agent->stream($question);
        foreach ($chunks as $ignored) {
        }

        return $chunks->getReturn()->getMessage();
    }

    /**
     * The Messages API answering a chat and a stream with that usage.
     *
     * @param array<string, mixed> $usage
     */
    protected function anthropicApi(array $usage = self::ANTHROPIC_USAGE): ScriptedHttpClient
    {
        $events = [
            ['type' => 'message_start', 'message' => ['role' => 'assistant', 'content' => [], 'usage' => ['output_tokens' => 1] + $usage]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Sunny in Rome.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => $usage['output_tokens']]],
            ['type' => 'message_stop'],
        ];

        return new ScriptedHttpClient(
            new HttpResponse(200, json_encode([
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Sunny in Rome.']],
                'stop_reason' => 'end_turn',
                'usage' => $usage,
            ], JSON_THROW_ON_ERROR)),
            stream: new ScriptedStream(array_map(static fn (array $event): string => 'data: ' . json_encode($event, JSON_THROW_ON_ERROR), $events)),
        );
    }

    /**
     * One turn of an agent whose tool asks a researcher, another agent, with the listener subscribed to both.
     *
     * @return array{string, string} the run ID of the agent and the run ID of the researcher
     */
    protected function askTheResearcher(IQListener $listener): array
    {
        $researcherRun = null;
        $researcher = Researcher::make()
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Rome is sunny.')))
            ->setPersistence(($this->bench->persistence)())
            ->setThreadId('thread:researcher')
            ->subscribe(ObservabilityEvent::class, $listener)
            ->subscribe(WorkflowStart::class, static function (WorkflowStart $event) use (&$researcherRun): void {
                $researcherRun = $event->execution?->runId;
            });
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [new ToolCall('ask_researcher', 'call_1', ['question' => 'What is the weather in Rome?'])]),
                new AssistantMessage('It is sunny in Rome.'),
            ))
            ->addTool(new AskResearcherTool($researcher))
            ->setPersistence(($this->bench->persistence)())
            ->setThreadId('thread:support')
            ->subscribe(ObservabilityEvent::class, $listener);

        $state = $agent->chat(new UserMessage('What is the weather in Rome?'));

        return [(string) $state->getRunId(), (string) $researcherRun];
    }

    /**
     * @return array<int, array<string, mixed>> the export requests the platform received, in order
     */
    protected function traces(): array
    {
        return array_map(static fn (HttpRequest $request): array => (array) $request->body, $this->platform->received());
    }

    /**
     * @return array<string, mixed> the parentless span of the first trace
     */
    protected function root(): array
    {
        foreach (Otlp::spans($this->traces()[0]) as $span) {
            if (!isset($span['parentSpanId'])) {
                return $span;
            }
        }

        self::fail('The trace has no parentless span.');
    }

    /**
     * @param array<string, mixed> $trace
     * @return list<string> the names of the parentless spans of the trace
     */
    protected function rootsOf(array $trace): array
    {
        return array_values(array_column(
            array_filter(Otlp::spans($trace), static fn (array $span): bool => !isset($span['parentSpanId'])),
            'name',
        ));
    }
}
