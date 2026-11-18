<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Flows;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\Flows\RefundTool;
use NeuronCore\IQ\Tests\Stub\Flows\SupportAgent;
use NeuronCore\IQ\Tests\Support\Local\LocalTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function array_map;
use function end;
use function implode;
use function iterator_to_array;

/**
 * The end of milestone 4 of the design: the chat turn of example (b) of
 * section 5, streamed in the request that received it. The agent asks for a
 * tool that needs an approval and the stream stops there; the approval,
 * submitted with Neuron's own translator, is streamed to the end of the turn.
 * The platform hears of both segments through the queue alone. The provider is
 * Neuron's fake: no turn reaches a network.
 */
class StreamedChatTurnTest extends LocalTestCase
{
    protected const THREAD_ID = 'thread:42';

    protected const CALL_ID = 'call_refund_1';

    protected const QUESTION = 'Refund order 123.';

    protected const APPROVAL = [self::CALL_ID => 'approve'];

    protected FakeAIProvider $provider;

    #[DataProvider('backends')]
    public function test_a_turn_streamed_from_a_message_stops_at_the_approval_of_the_tool_call(string $backend): void
    {
        $bench = $this->support($backend);
        $events = $this->turn();

        $streamed = iterator_to_array($events);

        self::assertInstanceOf(InterruptEvent::class, end($streamed));
        self::assertSame(WorkflowStatus::Suspended, $events->getReturn()->getStatus());
        self::assertInstanceOf(ApprovalRequest::class, $events->getReturn()->getInterruptRequest());
        self::assertSame(0, $bench->executions->count(RefundTool::class), 'The tool waits for the decision.');
    }

    #[DataProvider('backends')]
    public function test_the_approval_submitted_with_the_translator_of_neuron_is_streamed_to_the_end_of_the_turn(string $backend): void
    {
        $bench = $this->support($backend);
        iterator_to_array($this->turn());
        $events = $this->approval();

        $streamed = iterator_to_array($events);

        $state = $events->getReturn();
        self::assertInstanceOf(AgentState::class, $state);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('Refunded, as you asked.', self::textOf($streamed));
        self::assertSame(1, $bench->executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_the_consumer_receives_the_chunks_that_the_stream_of_neuron_itself_gives(string $backend): void
    {
        $this->support($backend);
        $direct = $this->agentOutsideTheSdk();

        $turn = iterator_to_array($this->turn());
        $approval = iterator_to_array($this->approval());

        self::assertEquals(iterator_to_array($direct->stream(new UserMessage(self::QUESTION))), $turn);
        self::assertEquals(iterator_to_array($direct->submitInputs(self::APPROVAL, new ApprovalTranslator())->events()), $approval);
        self::assertSame('Let me refund that.', self::textOf($turn), 'The comparison would hold for two empty streams.');
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_of_both_segments_through_the_queue(string $backend): void
    {
        $bench = $this->support($backend);
        iterator_to_array($this->turn());
        iterator_to_array($this->approval());
        $sentByTheRequests = $bench->platform->received();

        $this->queue->runAll($bench->iq);

        self::assertSame([], $sentByTheRequests, 'A streamed segment calls the platform from no request.');
        self::assertSame([Verb::Announce, Verb::Observe, Verb::Announce, Verb::Observe], $this->verbsQueued());
        $bench->platform->assertAnnounced(self::THREAD_ID, Verb::Start);
        $bench->platform->assertReported(self::THREAD_ID, Outcome::Suspended);
        $bench->platform->assertAnnounced(self::THREAD_ID, Verb::Answer);
        $bench->platform->assertReported(self::THREAD_ID, Outcome::Completed);
    }

    #[DataProvider('backends')]
    public function test_a_message_sent_while_the_approval_is_pending_is_refused_by_the_first_valid_and_not_later(string $backend): void
    {
        $this->support($backend);
        iterator_to_array($this->turn());
        $events = $this->thread()->start(new UserMessage('And order 124?'), stream: true)->events();
        $queuedBeforeTheFirstIteration = $this->verbsQueued();

        try {
            $events->valid();
            self::fail('A second turn replaced the one that waits for its approval.');
        } catch (RunInFlightException $refusal) {
            self::assertSame(WorkflowStatus::Suspended, $refusal->status);
        }

        self::assertSame([Verb::Announce, Verb::Observe, Verb::Announce], $queuedBeforeTheFirstIteration);
        self::assertFalse($events->valid(), 'The refusal ended the stream: nothing follows it.');
        $observe = $this->queue->dispatched()[3]->body;
        self::assertSame([Outcome::Refused->value, RefusalReason::RunInFlight->value], [$observe['outcome'], $observe['code']]);
        $this->provider->assertCallCount(1);
    }

    #[DataProvider('backends')]
    public function test_an_approval_streamed_a_second_time_is_refused_by_the_first_valid(string $backend): void
    {
        $bench = $this->support($backend);
        iterator_to_array($this->turn());
        $second = $this->approval();
        iterator_to_array($this->approval());

        try {
            $second->valid();
            self::fail('An approval the turn had already been given was applied again.');
        } catch (WorkflowRefusedException $refusal) {
            self::assertSame(RefusalReason::StaleRun, $refusal->reason);
        }

        self::assertSame(1, $bench->executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_a_browser_that_leaves_in_the_middle_of_the_turn_fails_the_run_and_the_platform_hears_what_the_store_shows(string $backend): void
    {
        $bench = $this->support($backend);
        $events = $this->turn();
        self::assertInstanceOf(TextChunk::class, $events->current());

        unset($events);
        [, $report] = $this->queue->runAll($bench->iq);

        self::assertSame(WorkflowStatus::Failed, (new WorkflowEngine(($bench->persistence)()))->inspect(self::THREAD_ID)?->status);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Observed, 'failed', 1], [$report->outcome, $report->run['status'] ?? null, $report->run['attempt'] ?? null]);
    }

    /**
     * "support-agent" is registered as the Laravel bridge would: a factory that needs nothing of a request.
     */
    protected function support(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = $this->provider = self::model();

        $bench->iq->register('support-agent', static fn (): Agent => new SupportAgent($provider, $conversations, $executions));

        return $bench;
    }

    /**
     * The model asks for a refund, then answers once it has the outcome of that call. A chunk carries the ID of
     * its message, so the messages are given one: two models then stream the same chunks.
     */
    protected static function model(): FakeAIProvider
    {
        return new FakeAIProvider(
            (new ToolCallMessage('Let me refund that.', [new ToolCall('refund', self::CALL_ID, ['iban' => 'IT60X0542811101000000123456'])]))->setId('msg_tool_call'),
            (new AssistantMessage('Refunded, as you asked.'))->setId('msg_reply'),
        );
    }

    /**
     * The same agent on a store of its own, as an application without the SDK calls it.
     */
    protected function agentOutsideTheSdk(): Agent
    {
        return (new SupportAgent(self::model(), new InMemoryMessageStore(), $this->bench->executions))
            ->for(self::THREAD_ID)
            ->setPersistence(new InMemoryPersistence());
    }

    protected function thread(): ManagedWorkflow
    {
        return $this->bench->iq->workflow('support-agent', self::THREAD_ID);
    }

    /**
     * The request that receives a message of the user, as the endpoint of example (b) prepares its stream:
     * nothing is admitted before the first iteration.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    protected function turn(): Generator
    {
        $thread = $this->thread();
        $thread->recover();

        return $thread->start(new UserMessage(self::QUESTION), stream: true)->events();
    }

    /**
     * The request that receives the decision of the person.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    protected function approval(): Generator
    {
        return $this->thread()->submitInputs(self::APPROVAL, new ApprovalTranslator())->events();
    }

    /**
     * @param object[] $streamed
     */
    protected static function textOf(array $streamed): string
    {
        return implode('', array_map(
            static fn (TextChunk $chunk): string => $chunk->content,
            array_filter($streamed, static fn (object $event): bool => $event instanceof TextChunk),
        ));
    }
}
