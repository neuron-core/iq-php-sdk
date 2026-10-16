<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Tracing\WeatherTool;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Tracing\Otlp;
use NeuronCore\Cloud\Tests\Support\Units\CapturesErrorLog;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function hash;
use function json_encode;
use function parse_url;
use function substr;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * enableTracing(): every segment the SDK executes sends its trace to the
 * platform, from the process that executed it.
 */
class TracingTest extends CloudTestCase
{
    use CapturesErrorLog;

    protected const WORKFLOW_ID = 'order:123';

    public function test_enable_tracing_returns_the_instance_it_was_called_on(): void
    {
        $cloud = new NeuronCloud(TestBench::API_KEY);

        self::assertSame($cloud, $cloud->enableTracing());
    }

    #[DataProvider('backends')]
    public function test_no_trace_is_sent_unless_tracing_was_enabled(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->cloud->register('order-fulfilment', static fn (): Workflow => new CompletingWorkflow($bench->executions));

        $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run();

        self::assertSame([], $bench->platform->received());
    }

    #[DataProvider('backends')]
    public function test_a_segment_run_in_the_calling_process_sends_one_trace_named_after_its_definition(string $backend): void
    {
        $bench = $this->tracing($backend);

        $state = $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run();

        [$trace] = $this->traces();
        $root = Otlp::span($trace, 'invoke_workflow order-fulfilment');
        self::assertSame(['CompleteNode', 'invoke_workflow order-fulfilment'], Otlp::names($trace));
        self::assertSame(
            [substr(hash('sha256', $state->getRunId()), 0, 32), $state->getRunId(), self::WORKFLOW_ID, 'completed'],
            [$root['traceId'], $root['attributes']['neuron.run.id'], $root['attributes']['neuron.workflow.id'], $root['attributes']['neuron.run.status']],
        );
    }

    /**
     * With a queue set, the announcement and the observe of a segment go to the queue. Its trace does not.
     */
    public function test_with_a_queue_set_the_trace_is_the_one_call_the_process_that_runs_a_segment_makes_to_the_platform(): void
    {
        $bench = $this->tracing();

        $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run();

        self::assertSame(['/v1/traces'], $this->paths());
        $bench->dispatcher->assertDispatched(Verb::Announce, self::WORKFLOW_ID);
        $bench->dispatcher->assertDispatched(Verb::Observe, self::WORKFLOW_ID);
    }

    public function test_a_streamed_segment_sends_its_trace_when_its_stream_ends(): void
    {
        $bench = $this->tracing();
        $events = $bench->cloud->workflow('tale', self::WORKFLOW_ID)->start()->events();
        $events->current();
        $sentWhileTheStreamIsOpen = $this->paths();

        while ($events->valid()) {
            $events->next();
        }

        self::assertSame([[], ['/v1/traces']], [$sentWhileTheStreamIsOpen, $this->paths()]);
        self::assertContains('invoke_workflow tale', Otlp::names($this->traces()[0]));
    }

    public function test_a_segment_a_job_executes_sends_its_trace_between_its_announcement_and_its_report(): void
    {
        $bench = $this->tracing();
        $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->dispatch();

        [$report] = $bench->dispatcher->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(['/v1/announcements', '/v1/traces', '/v1/reports'], $this->paths());
        self::assertSame($report->runId, Otlp::spans($this->traces()[0])[0]['attributes']['neuron.run.id']);
    }

    public function test_every_segment_of_a_run_sends_a_trace_of_its_own_under_the_trace_id_of_the_run(): void
    {
        $bench = $this->tracing();
        $thread = $bench->cloud->workflow('checkout', self::WORKFLOW_ID);

        $thread->start()->run();
        $thread->signal('payment.received', [])->run();

        $roots = array_map(static fn (array $trace): array => Otlp::span($trace, 'invoke_workflow checkout'), $this->traces());
        self::assertCount(2, $roots);
        self::assertCount(1, array_unique([$roots[0]['traceId'], $roots[1]['traceId']]));
        self::assertNotSame($roots[0]['spanId'], $roots[1]['spanId']);
        self::assertSame(['suspended', 'suspended'], [$roots[0]['attributes']['neuron.run.status'], $roots[1]['attributes']['neuron.run.status']]);
    }

    public function test_a_segment_that_fails_sends_a_trace_that_says_failed_with_what_the_node_threw(): void
    {
        $bench = $this->tracing();

        try {
            $bench->cloud->workflow('flaky', self::WORKFLOW_ID)->start()->run();
            self::fail('The exception of the node was not thrown.');
        } catch (NodeFailed) {
        }

        $root = Otlp::span($this->traces()[0], 'invoke_workflow flaky');
        self::assertSame(['failed', NodeFailed::class], [$root['attributes']['neuron.run.status'], $root['attributes']['error.type']]);
        self::assertSame('The flaky node failed on purpose.', $root['events'][0]['attributes']['exception.message']);
    }

    public function test_a_call_that_executes_no_segment_sends_no_trace(): void
    {
        $bench = $this->tracing();
        $bench->cloud->workflow('checkout', self::WORKFLOW_ID)->start()->run();
        $tracesOfTheSegment = $this->traces();

        $bench->cloud->workflow('checkout', self::WORKFLOW_ID)->recover();

        self::assertCount(1, $tracesOfTheSegment);
        self::assertSame($tracesOfTheSegment, $this->traces());
    }

    public function test_the_attributes_given_to_enable_tracing_are_on_every_span(): void
    {
        $bench = $this->tracing(attributes: ['deployment.environment' => 'production', 'service.version' => '2026.10']);

        $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run();

        foreach (Otlp::spans($this->traces()[0]) as $span) {
            self::assertSame(
                ['production', '2026.10'],
                [$span['attributes']['deployment.environment'], $span['attributes']['service.version']],
            );
        }
    }

    public function test_content_is_captured_unless_enable_tracing_is_told_not_to(): void
    {
        $bench = $this->tracing();

        $bench->cloud->workflow('assistant', 'thread:1')->start(new UserMessage('What is the weather in Rome?'))->run();

        $trace = $this->traces()[0];
        self::assertStringContainsString('What is the weather in Rome?', Otlp::span($trace, 'chat fake')['attributes']['gen_ai.input.messages']);
        self::assertSame('Sunny in Rome', Otlp::span($trace, 'execute_tool weather')['attributes']['gen_ai.tool.call.result']);
        self::assertSame('assistant', Otlp::span($trace, 'invoke_agent assistant')['attributes']['gen_ai.agent.name']);
    }

    public function test_with_content_capture_off_no_prompt_no_completion_and_nothing_a_tool_was_given_or_returned_is_sent(): void
    {
        $bench = $this->tracing(captureContent: false);

        $bench->cloud->workflow('assistant', 'thread:1')->start(new UserMessage('What is the weather in Rome?'))->run();

        $trace = $this->traces()[0];
        // The question, the argument of the tool, its result and the answer all name the city.
        self::assertStringNotContainsString('Rome', json_encode($trace, JSON_THROW_ON_ERROR));
        self::assertContains('execute_tool weather', Otlp::names($trace));
    }

    public function test_a_platform_that_refuses_the_trace_changes_nothing_for_the_segment_or_for_its_observe(): void
    {
        $bench = $this->tracing();
        $bench->platform->failNext(1, 503);

        $logged = self::loggedBy(static function () use ($bench, &$state): void {
            $state = $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run();
        });

        self::assertSame(WorkflowStatus::Completed, $state?->getStatus());
        self::assertStringContainsString('[TraceExporter] Failed to send trace: The platform answered 503 to /v1/traces.', $logged);
        $bench->dispatcher->assertDispatched(Verb::Observe, self::WORKFLOW_ID);
    }

    public function test_a_trace_that_was_refused_is_not_sent_again(): void
    {
        $bench = $this->tracing();
        $bench->platform->failNext(1, 503);

        self::loggedBy(static fn (): \NeuronAI\Workflow\WorkflowState => $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start()->run());

        self::assertSame(['/v1/traces'], $this->paths());
    }

    /**
     * A bench whose cloud traces, with a workflow that completes, one that streams, one that waits, one that
     * fails and an agent that calls a tool.
     *
     * @param array<string, mixed> $attributes
     */
    protected function tracing(string $backend = TestBench::IN_MEMORY, bool $captureContent = true, array $attributes = []): TestBench
    {
        $bench = $this->benchOn($backend);
        $bench->cloud
            ->enableTracing($captureContent, $attributes)
            ->register('order-fulfilment', static fn (): Workflow => new CompletingWorkflow($bench->executions))
            ->register('tale', static fn (): Workflow => new NarratingWorkflow($bench->executions))
            ->register('checkout', static fn (): Workflow => new WaitingWorkflow($bench->executions))
            ->register('flaky', static fn (): Workflow => new FlakyWorkflow($bench->executions))
            ->register('assistant', static fn (): Agent => Agent::make()
                ->setAiProvider(new FakeAIProvider(
                    new ToolCallMessage(null, [new ToolCall('weather', 'call_1', ['city' => 'Rome'])]),
                    new AssistantMessage('Sunny in Rome.'),
                ))
                ->addTool(new WeatherTool()));

        return $bench;
    }

    /**
     * @return string[] the path of every request the platform received, in order
     */
    protected function paths(): array
    {
        return array_map(
            static fn (HttpRequest $request): string => (string) parse_url($request->uri, PHP_URL_PATH),
            $this->bench->platform->received(),
        );
    }

    /**
     * @return array<int, array<string, mixed>> the traces the platform received, in order
     */
    protected function traces(): array
    {
        return array_values(array_map(
            static fn (HttpRequest $request): array => (array) $request->body,
            array_filter(
                $this->bench->platform->received(),
                static fn (HttpRequest $request): bool => parse_url($request->uri, PHP_URL_PATH) === '/v1/traces',
            ),
        ));
    }
}
