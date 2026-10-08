<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Starts;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\Cloud\Tests\Stub\Local\BriefingAgent;
use NeuronCore\Cloud\Tests\Stub\Units\BriefingStartEvent;
use NeuronCore\Cloud\Tests\Stub\Units\PromptingAgent;
use NeuronCore\Cloud\Tests\Support\Flows\StartTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The start event of an agent (rules 2, 3 and 5 of section 9.3 of the
 * design), as ManagedWorkflow::start() applies them. The provider is Neuron's
 * fake: no turn reaches a network.
 */
class AgentStartInputTest extends StartTestCase
{
    protected FakeAIProvider $provider;

    #[DataProvider('backends')]
    public function test_a_message_starts_a_turn_of_that_message(string $backend): void
    {
        $this->benchWithAgents($backend);

        $state = $this->thread('assistant')->start(new UserMessage('Refund order 123.'))->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame([['Refund order 123.']], $this->conversationsSent());
    }

    #[DataProvider('backends')]
    public function test_a_list_of_messages_starts_a_turn_of_all_of_them_in_order(string $backend): void
    {
        $this->benchWithAgents($backend);
        $conversation = [new UserMessage('Hello.'), new AssistantMessage('How can I help?'), new UserMessage('Refund order 123.')];

        $this->thread('assistant')->start($conversation)->run();

        self::assertSame([['Hello.', 'How can I help?', 'Refund order 123.']], $this->conversationsSent());
    }

    #[DataProvider('streamFlagsOnEveryBackend')]
    public function test_the_stream_flag_reaches_the_options_of_the_event_the_messages_are_set_on(string $backend, bool $stream): void
    {
        $this->benchWithAgents($backend);

        $this->thread('assistant')->start(new UserMessage('Refund order 123.'), stream: $stream)->dispatch();

        $event = $this->eventCarriedBy($this->onlyQueued());
        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertSame($stream, $event->options->stream);
    }

    #[DataProvider('streamFlagsOnEveryBackend')]
    public function test_the_stream_flag_decides_whether_the_provider_streams_its_answer(string $backend, bool $stream): void
    {
        $this->benchWithAgents($backend);

        $this->thread('assistant')->start(new UserMessage('Refund order 123.'), stream: $stream)->run();

        $this->provider->assertMethodCallCount($stream ? 'stream' : 'chat', 1);
    }

    #[DataProvider('backends')]
    public function test_messages_are_set_on_a_copy_of_the_event_the_hook_of_the_agent_builds(string $backend): void
    {
        $this->benchWithAgents($backend);

        $this->thread('briefing')->start(new UserMessage('Brief me.'))->dispatch();

        $event = $this->eventCarriedBy($this->onlyQueued());
        self::assertInstanceOf(BriefingStartEvent::class, $event);
        self::assertSame(['Brief me.'], $this->contentsOf($event->messages));
        self::assertSame(BriefingAgent::MAX_RETRIES, $event->options->maxRetries);
    }

    #[DataProvider('backends')]
    public function test_an_event_of_the_class_of_the_agent_own_event_is_used_as_given(string $backend): void
    {
        $this->benchWithAgents($backend);
        $given = new AgentStartEvent([new UserMessage('Refund order 123.')], new AgentRunOptions(stream: true, maxRetries: 4));

        $this->thread('assistant')->start($given)->dispatch();

        self::assertEquals($given, $this->eventCarriedBy($this->onlyQueued()));
    }

    #[DataProvider('backends')]
    public function test_the_stream_argument_leaves_the_options_of_an_event_given_to_an_agent_as_they_are(string $backend): void
    {
        $this->benchWithAgents($backend);
        $given = new AgentStartEvent([new UserMessage('Refund order 123.')], new AgentRunOptions(stream: false));

        $this->thread('assistant')->start($given, stream: true)->run();

        $this->provider->assertMethodCallCount('chat', 1);
    }

    #[DataProvider('backends')]
    public function test_an_agent_whose_hook_builds_its_prompt_starts_with_no_input(string $backend): void
    {
        $this->benchWithAgents($backend);

        $state = $this->thread('prompting')->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame([[PromptingAgent::PROMPT . self::WORKFLOW_ID]], $this->conversationsSent());
    }

    #[DataProvider('backends')]
    public function test_the_stream_argument_leaves_the_event_an_agent_builds_for_itself_as_it_is(string $backend): void
    {
        $this->benchWithAgents($backend);

        $this->thread('prompting')->start(stream: true)->run();

        $this->provider->assertMethodCallCount('chat', 1);
    }

    #[DataProvider('refusedTurnsOnEveryBackend')]
    public function test_a_turn_that_breaks_a_rule_is_refused_as_invalid_start(string $backend, string $agent, Event|array|null $input): void
    {
        $this->benchWithAgents($backend);
        $thread = $this->thread($agent);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $thread->start($input));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('refusedTurnsOnEveryBackend')]
    public function test_a_refused_turn_announces_nothing_and_touches_no_store(string $backend, string $agent, Event|array|null $input): void
    {
        $bench = $this->benchWithAgents($backend);
        $thread = $this->thread($agent);

        self::thrownBy(static fn (): PendingSegment => $thread->start($input));

        self::assertSame([], $this->queue->dispatched(), 'Nothing was handed to the queue.');
        self::assertSame([], $bench->platform->received(), 'Nothing was sent to the platform.');
        self::assertSame([], $this->storeOperations);
    }

    #[DataProvider('refusedTurnsOnEveryBackend')]
    public function test_a_refused_turn_calls_no_provider(string $backend, string $agent, Event|array|null $input): void
    {
        $this->benchWithAgents($backend);
        $thread = $this->thread($agent);

        self::thrownBy(static fn (): PendingSegment => $thread->start($input));

        $this->provider->assertNothingSent();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function streamFlagsOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "stream: true, on {$store}" => [$backend, true];
            yield "stream: false, on {$store}" => [$backend, false];
        }
    }

    /**
     * @return iterable<string, array{string, string, Event|array<int, mixed>|null}> a backend, the agent, and
     *                                                                                the input start() is given
     */
    public static function refusedTurnsOnEveryBackend(): iterable
    {
        $message = new UserMessage('Refund order 123.');
        $posted = self::postedMessages();
        $refused = [
            'the start event of a plain workflow' => ['assistant', new StartEvent()],
            'an event of the application' => ['assistant', new OrderPlaced('123')],
            'an event of a subclass of the agent own' => ['assistant', new BriefingStartEvent([$message])],
            'an event of the parent class of the agent own' => ['briefing', new AgentStartEvent([$message])],
            'an agent start event with no messages' => ['assistant', new AgentStartEvent()],
            'an empty list of messages' => ['assistant', []],
            'a list that holds the text of a message where a Message belongs' => ['assistant', $posted],
            'a list that holds such a text after a Message' => ['assistant', [$message, ...$posted]],
            'an agent start event whose list holds such a text' => ['assistant', new AgentStartEvent($posted)],
            'no input, for an agent whose own event carries no message' => ['assistant', null],
        ];

        foreach (self::backends() as $store => [$backend]) {
            foreach ($refused as $what => [$agent, $input]) {
                yield "{$what}, on {$store}" => [$backend, $agent, $input];
            }
        }
    }

    /**
     * "assistant" is a plain agent; the hook of "briefing" declares a class and options of its own for the start
     * event; the hook of "prompting" builds the prompt from the thread ID. All three answer with the same provider.
     */
    protected function benchWithAgents(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $provider = $this->provider = new FakeAIProvider(new AssistantMessage('Done.'));

        $bench->cloud
            ->register('assistant', static fn (): Agent => Agent::make()->setAiProvider($provider))
            ->register('briefing', static fn (): Agent => (new BriefingAgent())->setAiProvider($provider))
            ->register('prompting', static fn (): Agent => (new PromptingAgent())->setAiProvider($provider));

        return $bench;
    }

    protected function thread(string $agent): ManagedWorkflow
    {
        return $this->bench->cloud->workflow($agent, self::WORKFLOW_ID);
    }

    /**
     * The messages of a chat request as its decoded body holds them, when the endpoint builds no Message from them.
     *
     * @return array<int, mixed>
     */
    protected static function postedMessages(): array
    {
        return json_decode('["Refund order 123."]', true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, string[]> the text of the messages of every request the provider received, in order
     */
    protected function conversationsSent(): array
    {
        return array_map(
            fn (RequestRecord $request): array => $this->contentsOf($request->messages),
            $this->provider->getRecorded(),
        );
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    protected function contentsOf(array $messages): array
    {
        return array_map(static fn (Message $message): string => (string) $message->getContent(), $messages);
    }
}
