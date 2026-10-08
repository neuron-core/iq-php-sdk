<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Continuations\RefundFailed;
use NeuronCore\Cloud\Tests\Stub\Continuations\RefundTool;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Neuron's own ApprovalTranslator against an agent suspended on a tool
 * approval, as the continuation of a chat turn: answered in the request, or
 * on the queue. The provider is Neuron's fake, so no network is used.
 */
class ToolApprovalTest extends ContinuationTestCase
{
    protected const CALL_ID = 'call_refund';

    protected const ANSWER_OF_THE_AGENT = 'The order is refunded.';

    protected FakeAIProvider $provider;

    #[DataProvider('backends')]
    public function test_an_approval_run_in_place_executes_the_tool_and_ends_the_turn_with_the_answer_of_the_agent(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();

        $state = $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run();

        self::assertInstanceOf(AgentState::class, $state);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(self::ANSWER_OF_THE_AGENT, $state->getMessage()?->getContent());
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_a_rejection_run_in_place_ends_the_turn_and_the_tool_never_runs(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();

        $state = $thread->submitInputs([self::CALL_ID => ['reject', 'The order was delivered.']], new ApprovalTranslator())->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(0, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_approval_executes_nothing_in_the_request_and_queues_the_decisions_with_the_fence(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();
        $suspended = $this->storedRun();
        $queued = $this->countQueued();

        $runId = $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->dispatch();

        [$answer] = $this->queuedAfter($queued);
        self::assertSame(0, $executions->count(RefundTool::class));
        self::assertSame($suspended?->runId, $runId);
        self::assertSame(
            [Verb::Answer, $suspended->runId, $suspended->executionAttempt, [self::CALL_ID => 'approve']],
            [$answer->verb, $answer->runId, $answer->attempt, $answer->payload],
        );
    }

    #[DataProvider('backends')]
    public function test_a_worker_executes_a_dispatched_approval_and_reports_the_turn_completed(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();
        $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->dispatch();

        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Verb::Answer, Outcome::Completed, 2], [$report->verb, $report->outcome, $report->executedAttempt]);
        self::assertSame(1, $executions->count(RefundTool::class));
        $this->provider->assertCallCount(2);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_approval_delivered_twice_executes_the_tool_once(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();
        $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->dispatch();

        [, $copy] = $this->work(deliveries: 2);

        self::assertInstanceOf(Report::class, $copy);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$copy->outcome, $copy->code]);
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_on_a_thread_that_waits_for_an_approval_and_the_approval_then_runs(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();

        $recovered = $thread->recover();
        $state = $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run();

        self::assertNull($recovered);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_an_approval_sent_again_after_its_tool_failed_is_an_input_translation_exception_and_the_tool_is_not_run_again(
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWhoseApprovedToolFailed();

        $refusal = self::thrownFrom(static fn (): PendingSegment => $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator()));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_recover_replays_the_approval_of_a_tool_that_failed_and_ends_the_turn(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWhoseApprovedToolFailed();

        $recovered = $thread->recover();

        self::assertInstanceOf(AgentState::class, $recovered);
        self::assertSame(WorkflowStatus::Completed, $recovered->getStatus());
        self::assertSame(self::ANSWER_OF_THE_AGENT, $recovered->getMessage()?->getContent());
        self::assertSame(2, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_an_approval_sent_again_once_recover_has_replayed_it_finds_no_run_to_continue(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWhoseApprovedToolFailed();
        $thread->recover();

        $refusal = self::thrownFrom(static fn (): PendingSegment => $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator()));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame('There is no persisted run to continue.', $refusal->getMessage());
    }

    #[DataProvider('backends')]
    public function test_a_decision_the_translator_does_not_know_is_refused_in_the_request_and_nothing_is_queued(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWaitingForAnApproval();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(static fn (): PendingSegment => $thread->submitInputs([self::CALL_ID => 'maybe'], new ApprovalTranslator()));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_a_decision_for_a_tool_call_the_run_does_not_wait_for_is_refused_in_the_request_and_nothing_is_queued(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWaitingForAnApproval();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(static fn (): PendingSegment => $thread->submitInputs(['call_other' => 'approve'], new ApprovalTranslator()));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame("No matching request for tool call 'call_other'.", $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_the_approval_translator_refuses_decisions_for_a_wait_that_is_not_an_approval(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();

        $refusal = self::thrownFrom(static fn (): PendingSegment => $contract->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator()));

        self::assertSame(InputTranslationException::class, $refusal::class);
    }

    /**
     * "support": an agent with a refund tool that needs approval. A turn was started in a request and a worker
     * posted its notices: the model called the tool and the run is suspended on the approval.
     *
     * @param int $toolFailures how many executions of the tool throw before one refunds the order
     */
    protected function threadWaitingForAnApproval(int $toolFailures = 0): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $messages = new InMemoryMessageStore();
        $provider = $this->provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('refund_order', self::CALL_ID)]),
            new AssistantMessage(self::ANSWER_OF_THE_AGENT),
        );

        $thread = $this->managed('support', static fn (): Agent => Agent::make()
            ->setAiProvider($provider)
            ->setMessageStore($messages)
            ->addTool((new RefundTool($executions, $toolFailures))->requireApproval()));
        $thread->start(new UserMessage('Refund my order.'))->run();
        $this->work();

        self::assertInstanceOf(ApprovalRequest::class, $this->storedRun()?->interrupt);

        return $thread;
    }

    /**
     * The approval was given in a request and the tool threw: the run is failed, with its answered approval
     * still attached.
     */
    protected function threadWhoseApprovedToolFailed(): ManagedWorkflow
    {
        $thread = $this->threadWaitingForAnApproval(toolFailures: 1);
        $failure = self::thrownFrom(static fn (): WorkflowState => $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run());

        self::assertInstanceOf(RefundFailed::class, $failure);
        self::assertSame(WorkflowStatus::Failed, $this->storedRun()?->status);

        return $thread;
    }
}
