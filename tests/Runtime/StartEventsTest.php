<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use ErrorException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\StartEvents;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;
use NeuronCore\IQ\Tests\Stub\Runner\HookedWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\HookFailed;
use NeuronCore\IQ\Tests\Stub\Runner\UnstorableStart;
use NeuronCore\IQ\Tests\Stub\Units\BriefingStartEvent;
use NeuronCore\IQ\Tests\Stub\Units\OrderPlaced;
use NeuronCore\IQ\Tests\Stub\Units\OrderWorkflow;
use NeuronCore\IQ\Tests\Stub\Units\PromptingAgent;
use NeuronCore\IQ\Tests\Stub\Units\RecordingPersistence;
use NeuronCore\IQ\Tests\Stub\Units\UnreadableOrderWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Tests\Support\Units\Queued;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

use function base64_encode;
use function json_decode;
use function json_encode;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

class StartEventsTest extends IQTestCase
{
    use CatchesIQExceptions;

    protected const WORKFLOW_ID = 'order:123';

    protected const THREAD_ID = 'thread:42';

    public function test_a_start_that_carries_no_event_uses_the_one_the_hook_of_the_workflow_builds(): void
    {
        $event = StartEvents::resolve($this->orders(), null);

        self::assertEquals(new OrderPlaced(self::WORKFLOW_ID), $event);
    }

    public function test_a_start_that_carries_no_event_uses_the_one_set_on_the_workflow(): void
    {
        $set = new OrderPlaced('order:set');

        $event = StartEvents::resolve($this->orders()->setStartEvent($set), null);

        self::assertSame($set, $event);
    }

    public function test_a_carried_event_is_rebuilt_and_used_as_given(): void
    {
        $event = StartEvents::resolve($this->orders(), self::carried(new OrderPlaced('order:carried')));

        self::assertEquals(new OrderPlaced('order:carried'), $event);
    }

    public function test_a_carried_event_is_rebuilt_from_a_start_that_went_through_a_json_queue(): void
    {
        $dispatched = new Invocation(
            'inv_start',
            Verb::Start,
            'application',
            'order',
            self::WORKFLOW_ID,
            Queued::RUN_ID,
            event: self::carried(new OrderPlaced('order:carried')),
        );
        $queued = json_encode($dispatched->toArray(), JSON_THROW_ON_ERROR);

        $received = Invocation::fromArray(json_decode($queued, true, flags: JSON_THROW_ON_ERROR));

        self::assertEquals(new OrderPlaced('order:carried'), StartEvents::resolve($this->orders(), $received->event));
    }

    public function test_checking_a_carried_event_leaves_the_start_event_of_the_workflow_alone(): void
    {
        $workflow = $this->orders();

        StartEvents::resolve($workflow, self::carried(new OrderPlaced('order:carried')));

        self::assertEquals(new OrderPlaced(self::WORKFLOW_ID), $workflow->getStartEvent());
    }

    public function test_a_carried_event_no_node_handles_is_an_invalid_start(): void
    {
        $workflow = $this->orders();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($workflow, self::carried(new PaymentSettled())));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_event_of_the_workflow_that_no_node_handles_is_an_invalid_start(): void
    {
        $workflow = $this->orders()->setStartEvent(new PaymentSettled());

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($workflow, null));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('eventsToCheck')]
    public function test_checking_an_event_touches_no_store(?Event $carried): void
    {
        $store = new RecordingPersistence();
        $workflow = $this->orders()->setPersistence($store);

        self::thrownBy(static fn (): Event => StartEvents::resolve($workflow, $carried instanceof Event ? self::carried($carried) : null));

        self::assertSame([], $store->operations());
    }

    #[DataProvider('carriedValuesThatAreNoEvent')]
    public function test_a_carried_value_that_is_not_an_event_is_an_invalid_start(string $carried): void
    {
        $workflow = $this->orders();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($workflow, $carried));

        self::assertSame(Code::InvalidStart, $reason);
    }

    /**
     * unserialize() raises a PHP warning for such bytes, which Laravel and Symfony turn into an exception.
     */
    public function test_carried_bytes_that_were_never_serialized_are_an_invalid_start_where_warnings_are_exceptions(): void
    {
        $workflow = $this->orders();
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($workflow, base64_encode('never serialized')));
        } finally {
            restore_error_handler();
        }

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_what_a_start_event_hook_throws_is_let_through_as_it_is(): void
    {
        $workflow = (new UnreadableOrderWorkflow())->for(self::WORKFLOW_ID);

        $this->expectExceptionObject(new RuntimeException('The orders table cannot be read.'));

        StartEvents::resolve($workflow, null);
    }

    public function test_what_a_hook_throws_while_the_graph_is_built_is_let_through_as_it_is(): void
    {
        $executions = $this->benchOn(TestBench::IN_MEMORY)->executions;
        $workflow = (new HookedWorkflow($executions, static function (string $hook): void {
            if ($hook === 'nodes') {
                throw new HookFailed('A node needs a client that cannot connect.');
            }
        }))->for(self::WORKFLOW_ID);

        $this->expectException(HookFailed::class);

        StartEvents::resolve($workflow, null);
    }

    public function test_an_agent_starts_with_the_event_its_hook_builds(): void
    {
        $event = StartEvents::resolve($this->promptingAgent(), null);

        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertSame(PromptingAgent::PROMPT . self::THREAD_ID, $event->messages[0]->getContent());
    }

    public function test_an_agent_starts_with_a_carried_event_of_the_class_of_its_own(): void
    {
        $carried = new AgentStartEvent([new UserMessage('Summarise the thread.')]);

        $event = StartEvents::resolve($this->promptingAgent(), self::carried($carried));

        self::assertEquals($carried, $event);
    }

    public function test_an_agent_whose_own_event_has_no_messages_is_an_invalid_start(): void
    {
        $agent = $this->agent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($agent, null));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_agent_that_is_carried_an_event_with_no_messages_is_an_invalid_start(): void
    {
        $agent = $this->promptingAgent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($agent, self::carried(new AgentStartEvent())));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('eventsOfAnotherClassThanTheAgentsOwn')]
    public function test_an_agent_that_is_carried_an_event_of_another_class_than_its_own_is_an_invalid_start(Event $own, Event $carried): void
    {
        $agent = $this->agent()->setStartEvent($own);

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($agent, self::carried($carried)));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_agent_whose_own_event_is_not_an_agent_start_event_is_an_invalid_start(): void
    {
        $agent = $this->agent()->setStartEvent(new StartEvent());

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($agent, null));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_the_event_of_an_agent_is_checked_without_its_provider(): void
    {
        $withoutProvider = Agent::make()->for(self::THREAD_ID);
        $carried = new AgentStartEvent([new UserMessage('Summarise the thread.')]);

        $event = StartEvents::resolve($withoutProvider, self::carried($carried));

        self::assertEquals($carried, $event);
    }

    public function test_a_queued_start_ignites_an_agent_with_the_event_its_hook_builds(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $provider = new FakeAIProvider(new AssistantMessage('Reconciled.'));
        $bench->iq->register('assistant', static fn (): Agent => (new PromptingAgent())->setAiProvider($provider));

        $report = $bench->iq->invoke(Queued::start('assistant', workflowId: self::THREAD_ID));

        self::assertSame(Outcome::Completed, $report->outcome);
        $provider->assertCallCount(1);
        $provider->assertSent(
            static fn (RequestRecord $request): bool => $request->messages[0]->getContent() === PromptingAgent::PROMPT . self::THREAD_ID,
        );
    }

    public function test_a_queued_start_of_an_agent_with_no_messages_is_rejected_before_anything_is_announced_or_claimed(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $provider = new FakeAIProvider(new AssistantMessage('Never asked.'));
        $bench->iq->register('assistant', static fn (): Agent => Agent::make()->setAiProvider($provider));

        $report = $bench->iq->invoke(Queued::start('assistant', workflowId: self::THREAD_ID));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::InvalidStart->value, $report->code);
        self::assertNull($report->claimed);
        self::assertNull($report->run);
        self::assertCount(1, $bench->platform->received(), 'Only the report is sent: the start is refused before it is announced.');
        $provider->assertNothingSent();
    }

    public function test_a_start_of_the_application_with_no_input_uses_the_event_of_the_workflow(): void
    {
        $event = StartEvents::from($this->orders(), null, false);

        self::assertEquals(new OrderPlaced(self::WORKFLOW_ID), $event);
    }

    public function test_a_start_of_the_application_uses_the_event_it_is_given(): void
    {
        $given = new OrderPlaced('order:given');

        self::assertSame($given, StartEvents::from($this->orders(), $given, false));
    }

    public function test_an_event_given_by_the_application_that_no_node_handles_is_an_invalid_start(): void
    {
        $workflow = $this->orders();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($workflow, new PaymentSettled(), false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_event_given_to_an_agent_must_be_of_the_class_of_its_own(): void
    {
        $agent = $this->agent();
        $briefing = new BriefingStartEvent([new UserMessage('Summarise the thread.')]);

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($agent, $briefing, false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_event_given_to_an_agent_keeps_its_own_options_whatever_the_stream_argument(): void
    {
        $given = new AgentStartEvent([new UserMessage('Summarise the thread.')]);

        $event = StartEvents::from($this->agent(), $given, true);

        self::assertSame($given, $event);
        self::assertFalse($given->options->stream);
    }

    public function test_a_message_starts_an_agent_with_its_own_event_and_that_message(): void
    {
        $message = new UserMessage('Summarise the thread.');

        $event = StartEvents::from($this->agent(), $message, false);

        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertSame([$message], $event->messages);
        self::assertFalse($event->options->stream);
    }

    public function test_a_list_of_messages_starts_an_agent_with_all_of_them_in_order(): void
    {
        $conversation = [new UserMessage('Hello.'), new AssistantMessage('How can I help?'), new UserMessage('Summarise the thread.')];

        $event = StartEvents::from($this->agent(), $conversation, false);

        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertSame($conversation, $event->messages);
    }

    public function test_the_stream_argument_is_set_on_the_event_that_carries_the_messages(): void
    {
        $event = StartEvents::from($this->agent(), new UserMessage('Summarise the thread.'), true);

        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertTrue($event->options->stream);
    }

    public function test_messages_replace_those_of_the_event_the_start_event_hook_builds(): void
    {
        $message = new UserMessage('Summarise the thread.');

        $event = StartEvents::from($this->promptingAgent(), $message, false);

        self::assertInstanceOf(AgentStartEvent::class, $event);
        self::assertSame([$message], $event->messages);
    }

    public function test_messages_are_set_on_a_copy_that_leaves_the_event_the_agent_holds_as_it_was(): void
    {
        $held = new BriefingStartEvent();
        $agent = $this->agent()->setStartEvent($held);

        $event = StartEvents::from($agent, new UserMessage('Summarise the thread.'), true);

        self::assertInstanceOf(BriefingStartEvent::class, $event);
        self::assertNotSame($held, $event);
        self::assertSame([], $held->messages);
        self::assertFalse($held->options->stream);
    }

    public function test_messages_given_to_a_workflow_that_is_not_an_agent_are_an_invalid_start(): void
    {
        $workflow = $this->orders();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($workflow, new UserMessage('Ship it.'), false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_messages_given_to_an_agent_whose_own_event_is_not_an_agent_start_event_are_an_invalid_start(): void
    {
        $agent = $this->agent()->setStartEvent(new StartEvent());

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($agent, new UserMessage('Summarise the thread.'), false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_empty_list_of_messages_is_an_invalid_start(): void
    {
        $agent = $this->promptingAgent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($agent, [], false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    /**
     * @param array<int, mixed> $messages
     */
    #[DataProvider('listsThatHoldSomethingThatIsNoMessage')]
    public function test_a_list_that_holds_something_that_is_no_message_is_an_invalid_start(array $messages): void
    {
        $agent = $this->agent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($agent, $messages, false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    /**
     * @param array<int, mixed> $messages
     */
    #[DataProvider('listsThatHoldSomethingThatIsNoMessage')]
    public function test_an_event_given_to_an_agent_whose_list_holds_something_that_is_no_message_is_an_invalid_start(array $messages): void
    {
        $agent = $this->agent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::from($agent, new AgentStartEvent($messages), false));

        self::assertSame(Code::InvalidStart, $reason);
    }

    /**
     * @param array<int, mixed> $messages
     */
    #[DataProvider('listsThatHoldSomethingThatIsNoMessage')]
    public function test_an_agent_that_is_carried_an_event_whose_list_holds_something_that_is_no_message_is_an_invalid_start(array $messages): void
    {
        $agent = $this->promptingAgent();

        $reason = self::reasonThrownBy(static fn (): Event => StartEvents::resolve($agent, self::carried(new AgentStartEvent($messages))));

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_the_request_of_a_start_reserves_its_run_id_and_never_recovers_a_failed_run(): void
    {
        $event = new OrderPlaced('order:carried');

        $request = StartEvents::request($event, Queued::RUN_ID, 'order-fulfilment');

        self::assertTrue($request->starting);
        self::assertSame(Queued::RUN_ID, $request->runId);
        self::assertFalse($request->recoverFailed);
        self::assertEquals($event, $request->event());
    }

    public function test_the_request_of_a_start_carries_the_name_of_its_definition_as_the_tag_of_the_run(): void
    {
        $request = StartEvents::request(new OrderPlaced('order:carried'), Queued::RUN_ID, 'order-fulfilment');

        self::assertSame('order-fulfilment', $request->tag);
    }

    public function test_an_event_neuron_cannot_store_with_a_run_is_an_invalid_start(): void
    {
        $reason = self::reasonThrownBy(
            static fn (): ExecutionRequest => StartEvents::request(new UnstorableStart(), Queued::RUN_ID, 'order-fulfilment'),
        );

        self::assertSame(Code::InvalidStart, $reason);
    }

    public function test_an_encoded_event_is_rebuilt_as_the_event_a_queued_start_carries(): void
    {
        $event = new OrderPlaced('order:carried');

        self::assertEquals($event, StartEvents::resolve($this->orders(), StartEvents::encode($event)));
    }

    /**
     * @return array<string, array{Event|null}>
     */
    public static function eventsToCheck(): array
    {
        return [
            'the event of the workflow' => [null],
            'a carried event a node handles' => [new OrderPlaced('order:carried')],
            'a carried event no node handles' => [new PaymentSettled()],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function carriedValuesThatAreNoEvent(): array
    {
        $unknownClass = 'NeuronCore\IQ\Tests\Stub\Units\RemovedByADeploy';

        return [
            'a string' => [base64_encode(serialize('order:123'))],
            'an array' => [base64_encode(serialize(['orderId' => 'order:123']))],
            'an object that is not an event' => [base64_encode(serialize(new stdClass()))],
            'an object of a class this process lacks' => [base64_encode(sprintf('O:%d:"%s":0:{}', strlen($unknownClass), $unknownClass))],
            'no value at all' => [''],
            'characters outside base64' => ['%%%'],
        ];
    }

    /**
     * @return array<string, array{array<int, mixed>}>
     */
    public static function listsThatHoldSomethingThatIsNoMessage(): array
    {
        return [
            'the text of a message' => [['Summarise the thread.']],
            'a message as the array a request body gives' => [[['role' => 'user', 'content' => 'Summarise the thread.']]],
            'nothing, after a message' => [[new UserMessage('Summarise the thread.'), null]],
        ];
    }

    /**
     * @return array<string, array{Event, Event}> the agent's own start event, and the one a start carries
     */
    public static function eventsOfAnotherClassThanTheAgentsOwn(): array
    {
        $messages = [new UserMessage('Summarise the thread.')];

        return [
            'an event that does not start an agent' => [new AgentStartEvent(), new StartEvent()],
            'a subclass of the own event' => [new AgentStartEvent(), new BriefingStartEvent($messages)],
            'the parent class of the own event' => [new BriefingStartEvent(), new AgentStartEvent($messages)],
        ];
    }

    protected static function carried(Event $event): string
    {
        return base64_encode(serialize($event));
    }

    protected function orders(): Workflow
    {
        return (new OrderWorkflow())->for(self::WORKFLOW_ID);
    }

    /**
     * An agent whose own start event carries no message, like every agent that declares no hook.
     */
    protected function agent(): Agent
    {
        return Agent::make()->for(self::THREAD_ID)->setAiProvider(new FakeAIProvider());
    }

    protected function promptingAgent(): Agent
    {
        return (new PromptingAgent())->for(self::THREAD_ID)->setAiProvider(new FakeAIProvider());
    }
}
