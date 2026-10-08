<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Flows;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Flows\RefundTool;
use NeuronCore\Cloud\Tests\Stub\Flows\SupportAgent;
use NeuronCore\Cloud\Tests\Support\Flows\FlowTestCase;
use NeuronCore\Cloud\Tests\Support\Flows\Planted;
use NeuronCore\Cloud\Tests\Support\Flows\PlainPhpWorker;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_filter;
use function array_map;

/**
 * A chat turn of example (b) of section 5 of the design, without its
 * streaming: the agent asks for a tool that needs an approval, the turn stops
 * there, and the decision of a person ends it, in the request or in a worker.
 * The provider is Neuron's fake: no turn reaches a network.
 */
class AgentApprovalFlowTest extends FlowTestCase
{
    protected const THREAD_ID = 'thread:42';

    protected const CALL_ID = 'call_refund_1';

    protected FakeAIProvider $provider;

    #[DataProvider('backends')]
    public function test_a_turn_started_with_a_message_stops_at_the_approval_of_the_tool_call(string $backend): void
    {
        $bench = $this->support($backend);

        $state = $this->turn()->run();

        self::assertSame(WorkflowStatus::Suspended, $state->getStatus());
        self::assertSame([self::CALL_ID], $this->pendingApprovals($state));
        self::assertSame(0, $bench->executions->count(RefundTool::class), 'The tool waits for the decision.');
    }

    #[DataProvider('backends')]
    public function test_the_model_is_given_the_message_the_turn_was_started_with(string $backend): void
    {
        $this->support($backend);

        $this->turn()->run();

        self::assertSame([['Refund order 123. ' . Planted::MESSAGE]], $this->conversationsSent());
    }

    #[DataProvider('backends')]
    public function test_an_approval_submitted_in_the_request_runs_the_tool_and_ends_the_turn(string $backend): void
    {
        $bench = $this->support($backend);
        $this->turn()->run();

        $state = $this->thread()->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run();

        self::assertInstanceOf(AgentState::class, $state);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('Refunded. ' . Planted::REPLY, $state->getMessage()->getContent());
        self::assertSame(1, $bench->executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_an_approval_dispatched_to_the_queue_ends_the_turn_in_a_worker(string $backend): void
    {
        $bench = $this->support($backend);
        $this->turn()->run();
        $this->work();

        $this->thread()->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->dispatch();
        $inTheRequest = $bench->executions->count(RefundTool::class);
        $this->work();

        self::assertSame([0, 1], [$inTheRequest, $bench->executions->count(RefundTool::class)]);
        self::assertNull($this->holder(self::THREAD_ID), 'The turn completed: nothing holds the thread.');
        $this->provider->assertCallCount(2);
    }

    #[DataProvider('backends')]
    public function test_a_turn_dispatched_to_the_queue_stops_at_the_approval_in_a_worker(string $backend): void
    {
        $this->support($backend);

        $this->turn()->dispatch();
        $this->work();

        $held = $this->holder(self::THREAD_ID);
        self::assertSame([['Refund order 123. ' . Planted::MESSAGE]], $this->conversationsSent());
        self::assertInstanceOf(WorkflowRunSnapshot::class, $held);
        self::assertSame(WorkflowStatus::Suspended, $held->status);
        self::assertInstanceOf(ApprovalRequest::class, $held->interrupt);
    }

    #[DataProvider('backends')]
    public function test_a_rejection_with_its_reason_ends_the_turn_without_running_the_tool(string $backend): void
    {
        $bench = $this->support($backend);
        $this->turn()->run();

        $state = $this->reject()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(0, $bench->executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_a_turn_as_a_start_that_waits_for_an_event_and_an_answer_that_completes(string $backend): void
    {
        $this->support($backend);

        $this->playATurnWithItsApproval();

        [$started, $answered] = $this->reportsOf(self::THREAD_ID);
        self::assertSame(['start: suspended', 'answer: completed'], $this->outcomesReported(self::THREAD_ID));
        self::assertSame(['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => ApprovalRequest::EVENT_NAME, 'wakeAt' => null], $started['run']['wait']);
        self::assertSame([2, null], [$answered['executedAttempt'], $answered['run']]);
        self::assertSame([Verb::Start->value, Verb::Answer->value], array_column($this->announcementsOf(self::THREAD_ID), 'verb'));
    }

    #[DataProvider('backends')]
    public function test_a_new_message_is_refused_as_run_in_flight_while_the_approval_is_pending(string $backend): void
    {
        $this->support($backend);
        $held = $this->turn()->run();

        try {
            $this->thread()->start(new UserMessage('And order 124?'))->run();
            self::fail('A second turn replaced the one that waits for its approval.');
        } catch (RunInFlightException $refusal) {
            self::assertSame([$held->getRunId(), WorkflowStatus::Suspended], [$refusal->runId, $refusal->status]);
        }
    }

    #[DataProvider('backends')]
    public function test_decisions_for_a_tool_call_the_turn_did_not_ask_about_are_refused_in_the_request_and_not_queued(string $backend): void
    {
        $this->support($backend);
        $this->turn()->run();
        $this->work();
        $thread = $this->thread();

        try {
            $thread->submitInputs(['call_of_another_turn' => 'approve'], new ApprovalTranslator());
            self::fail('A decision for an unknown tool call was accepted.');
        } catch (InputTranslationException) {
            self::assertSame([], $this->redis->lRange(PlainPhpWorker::QUEUE));
        }
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_approval_delivered_twice_runs_the_tool_once(string $backend): void
    {
        $bench = $this->support($backend);
        $this->turn()->run();
        $this->work();
        $this->thread()->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->dispatch();
        [$approval] = $this->redis->lRange(PlainPhpWorker::QUEUE);
        $this->work();

        $this->redis->rPush(PlainPhpWorker::QUEUE, $approval);
        $this->work();

        self::assertSame(['start: suspended', 'answer: completed', 'answer: refused'], $this->outcomesReported(self::THREAD_ID));
        self::assertSame(1, $bench->executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_no_request_of_a_chat_turn_calls_the_platform(string $backend): void
    {
        $bench = $this->support($backend);

        $this->playATurnWithItsApproval();

        self::assertSame(0, $this->sentWhileServingRequests);
        self::assertNotSame([], $bench->platform->received(), 'The workers told the platform of both segments.');
    }

    #[DataProvider('backends')]
    public function test_nothing_planted_in_a_chat_turn_reaches_the_platform(string $backend): void
    {
        $this->support($backend);

        $this->playATurnWithItsApproval();

        $this->assertNothingReachedThePlatform(Planted::inAChatTurn());
    }

    #[DataProvider('backends')]
    public function test_what_was_planted_in_a_chat_turn_was_in_the_store_of_the_application(string $backend): void
    {
        $this->support($backend);

        $this->playATurnWithItsApproval();

        $neverStored = array_filter(Planted::inAChatTurn(), fn (string $marker): bool => !$this->journal->wrote($marker));
        self::assertSame([], $neverStored, 'The boundary would be checked on values no run ever held.');
    }

    /**
     * "support-agent" is registered as the Laravel bridge would: a factory that needs nothing of a request. The
     * model asks for a refund, then answers once it has the outcome of that call.
     */
    protected function support(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = $this->provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('refund', self::CALL_ID, ['iban' => Planted::TOOL_INPUT])]),
            new AssistantMessage('Refunded. ' . Planted::REPLY),
        );

        $bench->cloud->register('support-agent', static fn (): Agent => new SupportAgent($provider, $conversations, $executions));

        return $bench;
    }

    protected function thread(): ManagedWorkflow
    {
        return $this->bench->cloud->workflow('support-agent', self::THREAD_ID);
    }

    /**
     * A turn of the user, up to its choice between run() and dispatch().
     */
    protected function turn(): PendingSegment
    {
        $thread = $this->thread();
        $thread->recover();

        return $thread->start(new UserMessage('Refund order 123. ' . Planted::MESSAGE));
    }

    /**
     * The person refuses the refund and says why.
     */
    protected function reject(): PendingSegment
    {
        return $this->thread()->submitInputs([self::CALL_ID => ['reject', Planted::ANSWER]], new ApprovalTranslator());
    }

    /**
     * The turn runs in the request and stops at the approval; the rejection
     * is dispatched and a worker ends the turn. Workers post every notice.
     */
    protected function playATurnWithItsApproval(): void
    {
        $this->inARequest(fn (): WorkflowState => $this->turn()->run());
        $this->work();

        $this->inARequest(fn (): string => $this->reject()->dispatch());
        $this->work();
    }

    /**
     * @return string[] the ID of every tool call a suspended turn still waits a decision for
     */
    protected function pendingApprovals(WorkflowState $state): array
    {
        $request = $state->getInterruptRequest();
        self::assertInstanceOf(ApprovalRequest::class, $request);

        return array_map(
            static fn (Action $action): string => $action->id,
            array_filter($request->getActions(), static fn (Action $action): bool => $action->isPending()),
        );
    }

    /**
     * @return array<int, string[]> the text of the messages of every request the provider received, in order
     */
    protected function conversationsSent(): array
    {
        return array_map(
            static fn (RequestRecord $request): array => array_map(
                static fn (Message $message): string => (string) $message->getContent(),
                $request->messages,
            ),
            $this->provider->getRecorded(),
        );
    }
}
