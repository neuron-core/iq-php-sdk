<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Stub\Continuations\RefundFailed;
use NeuronCore\Cloud\Tests\Stub\Continuations\RefundTool as FailingRefundTool;
use NeuronCore\Cloud\Tests\Stub\Flows\RefundTool;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_keys;

/**
 * The guard of an Agent stays in force under the platform's abandon: a
 * conversation that ends on a tool call nobody answered keeps its run,
 * whatever fence the abandon carries.
 */
class AbandonAgentTest extends QuestionsTestCase
{
    protected const RIGHT_ATTEMPT = 'the right attempt';

    protected const STALE_ATTEMPT = 'a stale attempt';

    protected const NO_ATTEMPT = 'no attempt';

    protected const ANOTHER_RUN_ID = 'the run ID of another run';

    #[DataProvider('fencesOnBackends')]
    public function test_a_conversation_that_ends_on_a_tool_call_is_answered_409_abandon_refused_with_the_run_whatever_the_fence(
        string $backend,
        string $fence,
    ): void {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();
        $held = $this->storedRun(self::THREAD_ID);

        $answer = $this->deliver($this->abandonOfTheTurn($fence));

        self::assertSame(
            [409, ['code' => 'abandon_refused', 'run' => $held, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
        self::assertSame($held, $this->storedRun(self::THREAD_ID), 'The agent keeps its run.');
    }

    #[DataProvider('whatAnApprovalHolds')]
    public function test_the_refusal_carries_nothing_of_the_approval_the_run_waits_for(string $backend, string $kept): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();

        $answer = $this->deliver($this->abandonOfTheTurn(self::RIGHT_ATTEMPT));

        self::assertSame(409, $answer->statusCode);
        self::assertStringContainsString($kept, $this->approvalAsTheStoreHoldsIt(), 'The approval request holds it.');
        self::assertStringNotContainsString($kept, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_the_approval_can_still_be_given_after_the_refusal_and_its_tool_then_runs_once(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();
        $this->deliver($this->abandonOfTheTurn(self::RIGHT_ATTEMPT));

        $state = $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('backends')]
    public function test_a_refused_abandon_has_entered_the_run_id_of_the_turn_in_the_ledger(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();
        $runId = $this->storedRun(self::THREAD_ID)['runId'];
        $before = $this->ledger(self::THREAD_ID);

        $this->deliver($this->abandonOfTheTurn(self::RIGHT_ATTEMPT));

        self::assertSame([[], [$runId]], [$before, array_keys($this->ledger(self::THREAD_ID))]);
    }

    #[DataProvider('backends')]
    public function test_a_failed_turn_whose_conversation_ends_on_a_tool_call_is_answered_409_abandon_refused_with_the_failed_run(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWhoseApprovedToolFailed();
        $held = $this->storedRun(self::THREAD_ID);

        $answer = $this->deliver($this->abandonOfTheTurn(self::RIGHT_ATTEMPT));

        self::assertSame('failed', $held['status'] ?? null);
        self::assertSame(
            [409, ['code' => 'abandon_refused', 'run' => $held, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_a_failed_turn_whose_conversation_ends_on_no_tool_call_is_discarded_and_answered_200(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWhoseProviderFailed();
        $held = $this->storedRun(self::THREAD_ID);

        $answer = $this->deliver($this->abandonOfTheTurn(self::RIGHT_ATTEMPT));

        self::assertSame('failed', $held['status'] ?? null);
        self::assertSame(
            [200, ['run' => null, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
        self::assertNull($this->storedRun(self::THREAD_ID));
    }

    /**
     * @return array<string, array<int, mixed>> the backend and the fence of the abandon
     */
    public static function fencesOnBackends(): array
    {
        return self::onEachBackend([
            self::RIGHT_ATTEMPT => [self::RIGHT_ATTEMPT],
            self::STALE_ATTEMPT => [self::STALE_ATTEMPT],
            self::NO_ATTEMPT => [self::NO_ATTEMPT],
            self::ANOTHER_RUN_ID => [self::ANOTHER_RUN_ID],
        ]);
    }

    /**
     * The abandon the platform sends for the turn that holds the thread now.
     *
     * @return array<string, mixed>
     */
    protected function abandonOfTheTurn(string $fence): array
    {
        $held = $this->storedRun(self::THREAD_ID);

        return $this->aboutTheThread(match ($fence) {
            self::STALE_ATTEMPT => $this->abandon($held['runId'], $held['attempt'] + 1),
            self::NO_ATTEMPT => $this->abandon($held['runId']),
            self::ANOTHER_RUN_ID => $this->abandon('1-another-run', $held['attempt']),
            default => $this->abandon($held['runId'], $held['attempt']),
        });
    }

    /**
     * The approval was given and the tool threw: the turn is failed, and its conversation still ends on the
     * tool call.
     */
    protected function threadWhoseApprovedToolFailed(): void
    {
        $executions = $this->bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('refund_order', self::CALL_ID)]));
        $this->bench->cloud->register(self::AGENT, static fn (): Agent => Agent::make()
            ->setAiProvider($provider)
            ->setMessageStore($conversations)
            ->addTool((new FailingRefundTool($executions, failures: 1))->requireApproval()));

        $thread = $this->bench->cloud->workflow(self::AGENT, self::THREAD_ID);
        $thread->start(new UserMessage('Refund order 123.'))->run();
        $failure = self::thrownBy(static fn (): mixed => $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run());
        $this->work();

        self::assertInstanceOf(RefundFailed::class, $failure);
    }

    /**
     * The provider failed before the model said anything: the turn is failed, and its conversation holds no
     * tool call.
     */
    protected function threadWhoseProviderFailed(): void
    {
        $conversations = new InMemoryMessageStore();
        $outOfAnswers = new FakeAIProvider();
        $this->bench->cloud->register(self::AGENT, static fn (): Agent => Agent::make()
            ->setAiProvider($outOfAnswers)
            ->setMessageStore($conversations));

        $thread = $this->bench->cloud->workflow(self::AGENT, self::THREAD_ID);
        $failure = self::thrownBy(static fn (): mixed => $thread->start(new UserMessage('Refund order 123.'))->run());
        $this->work();

        self::assertInstanceOf(ProviderException::class, $failure);
    }

    /**
     * @param callable(): mixed $call
     */
    protected static function thrownBy(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
