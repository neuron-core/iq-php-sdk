<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Observability;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Observability\ObservabilityEvent;
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
use NeuronCore\Cloud\Observability\CloudListener;
use NeuronCore\Cloud\Observability\Trace;
use NeuronCore\Cloud\Observability\TraceExporter;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Tracing\UnbuildableWorkflow;
use NeuronCore\Cloud\Tests\Stub\Tracing\WeatherTool;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Tracing\Otlp;
use NeuronCore\Cloud\Tests\Support\Units\CapturesErrorLog;
use RuntimeException;

use function array_column;
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
class CloudListenerTest extends CloudTestCase
{
    use CapturesErrorLog;

    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const CONTENT = ['gen_ai.input.messages', 'gen_ai.output.messages', 'gen_ai.tool.call.arguments', 'gen_ai.tool.call.result'];

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
        $listener->flush();

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

    public function test_a_listener_starts_a_new_trace_for_the_next_run_of_its_workflow(): void
    {
        $workflow = $this->traced(new CompletingWorkflow($this->bench->executions));

        $workflow->run(ExecutionRequest::start(new StartEvent(), self::RUN_ID));
        $workflow->run(ExecutionRequest::start(new StartEvent(), '1-another-run'));

        self::assertCount(2, array_unique(array_map(static fn (array $trace): string => Otlp::spans($trace)[0]['traceId'], $this->traces())));
        self::assertSame([2, 2], array_map(static fn (array $trace): int => count(Otlp::spans($trace)), $this->traces()));
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
            $state = $this->traced(new CompletingWorkflow($this->bench->executions), new CloudListener($broken))->run();
        });

        self::assertSame(WorkflowStatus::Completed, $state?->getStatus());
        self::assertStringContainsString('[CloudListener] The exporter is broken.', $logged);
    }

    public function test_the_end_of_a_node_that_no_run_in_flight_opened_sends_nothing(): void
    {
        $listener = $this->listener();

        $listener(new WorkflowNodeEnd(CompleteNode::class, new WorkflowState()));
        $listener->flush();

        self::assertSame([], $this->platform->received());
    }

    protected function listener(?string $name = 'order-fulfilment', array $metadata = [], bool $captureContent = true): CloudListener
    {
        return new CloudListener(
            new TraceExporter(new PlatformClient($this->platform, TestBench::PLATFORM_URL, TestBench::API_KEY)),
            $name,
            metadata: $metadata,
            captureContent: $captureContent,
        );
    }

    /**
     * A workflow on the store of the bench, with the listener subscribed as the SDK subscribes it.
     */
    protected function traced(Workflow $workflow, ?CloudListener $listener = null): Workflow
    {
        return $workflow
            ->for(self::WORKFLOW_ID)
            ->setPersistence(($this->bench->persistence)())
            ->subscribe(ObservabilityEvent::class, $listener ?? $this->listener());
    }

    /**
     * One turn of an agent: the model asks for the weather tool, the tool runs, the model answers.
     */
    protected function chatAboutTheWeather(CloudListener $listener): void
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
}
