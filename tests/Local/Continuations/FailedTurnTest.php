<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;

/**
 * The flow of example (b) of section 5: every request of a chat thread calls
 * recover() before it starts a turn, because a start never replaces the run
 * of a turn that failed (row 16 of section 7). The provider is Neuron's fake:
 * it fails a turn when it has no response left.
 */
class FailedTurnTest extends ContinuationTestCase
{
    protected const FIRST_QUESTION = 'Where is my order?';

    protected const FIRST_ANSWER = 'It ships tomorrow.';

    protected const NEXT_QUESTION = 'And my invoice?';

    protected const NEXT_ANSWER = 'It is attached.';

    protected FakeAIProvider $provider;

    #[DataProvider('backends')]
    public function test_a_turn_started_on_a_thread_whose_last_turn_failed_is_refused_as_run_in_flight(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWhoseLastTurnFailed();
        $this->provider->addResponses(new AssistantMessage(self::NEXT_ANSWER));

        $refusal = self::thrownFrom(static fn (): WorkflowState => $thread->start(new UserMessage(self::NEXT_QUESTION))->run());

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(WorkflowStatus::Failed, $refusal->status);
        $this->provider->assertCallCount(0);
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_the_failed_turn_and_returns_the_state_with_the_answer_of_the_agent(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWhoseLastTurnFailed();
        $this->provider->addResponses(new AssistantMessage(self::FIRST_ANSWER));

        $recovered = $thread->recover();

        self::assertInstanceOf(AgentState::class, $recovered);
        self::assertSame(WorkflowStatus::Completed, $recovered->getStatus());
        self::assertSame(self::FIRST_ANSWER, $recovered->getMessage()?->getContent());
    }

    #[DataProvider('backends')]
    public function test_the_turn_started_after_recover_runs_on_the_conversation_the_recovered_turn_left(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWhoseLastTurnFailed();
        $this->provider->addResponses(new AssistantMessage(self::FIRST_ANSWER), new AssistantMessage(self::NEXT_ANSWER));

        $thread->recover();
        $state = $thread->start(new UserMessage(self::NEXT_QUESTION))->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->provider->assertSent(static fn (RequestRecord $request): bool => array_map(
            static fn (Message $message): mixed => $message->getContent(),
            $request->messages,
        ) === [self::FIRST_QUESTION, self::FIRST_ANSWER, self::NEXT_QUESTION]);
    }

    #[DataProvider('backends')]
    public function test_recover_returns_null_on_a_thread_whose_last_turn_ended_and_the_next_turn_runs(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->thread(new AssistantMessage(self::FIRST_ANSWER), new AssistantMessage(self::NEXT_ANSWER));
        $thread->start(new UserMessage(self::FIRST_QUESTION))->run();

        $recovered = $thread->recover();
        $state = $thread->start(new UserMessage(self::NEXT_QUESTION))->run();

        self::assertNull($recovered);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
    }

    #[DataProvider('backends')]
    public function test_recover_throws_what_the_turn_throws_when_it_fails_again_and_the_thread_stays_held(string $backend): void
    {
        $this->benchOn($backend);
        $thread = $this->threadWhoseLastTurnFailed();

        $thrown = self::thrownFrom(static fn (): ?WorkflowState => $thread->recover());

        self::assertSame(ProviderException::class, $thrown::class);
        self::assertSame([WorkflowStatus::Failed, 2], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    /**
     * "support-agent": an agent whose provider answers with $responses, in order, and fails once it has none.
     */
    protected function thread(Message ...$responses): ManagedWorkflow
    {
        $messages = new InMemoryMessageStore();
        $provider = $this->provider = new FakeAIProvider(...$responses);

        return $this->managed('support-agent', static fn (): Agent => Agent::make()
            ->setAiProvider($provider)
            ->setMessageStore($messages));
    }

    /**
     * A turn was started in a request while the provider was down: its run is failed and holds the thread.
     */
    protected function threadWhoseLastTurnFailed(): ManagedWorkflow
    {
        $thread = $this->thread();
        $failure = self::thrownFrom(static fn (): WorkflowState => $thread->start(new UserMessage(self::FIRST_QUESTION))->run());

        self::assertInstanceOf(ProviderException::class, $failure);
        self::assertSame(WorkflowStatus::Failed, $this->storedRun()?->status);

        return $thread;
    }
}
