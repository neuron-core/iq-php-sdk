<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Starts;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\Cloud\Tests\Stub\Flows\PaymentReceived;
use NeuronCore\Cloud\Tests\Stub\Flows\ShipOrderNode;
use NeuronCore\Cloud\Tests\Stub\Runner\MisroutedWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\UnstorableStart;
use NeuronCore\Cloud\Tests\Stub\Runner\UnstorableStartNode;
use NeuronCore\Cloud\Tests\Support\Flows\StartTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The start event of a workflow that is not an agent (rules 1, 3 and 4 of
 * section 9.3 of the design), as ManagedWorkflow::start() applies them.
 */
class StartInputTest extends StartTestCase
{
    #[DataProvider('backends')]
    public function test_a_start_with_no_input_ignites_the_run_with_the_event_the_workflow_builds(string $backend): void
    {
        $this->benchOn($backend);

        $state = $this->order()->start()->run();

        self::assertSame(WorkflowStatus::Suspended, $state->getStatus());
        self::assertSame(['placed:' . self::WORKFLOW_ID], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_start_ignites_the_run_with_the_event_it_is_given_instead_of_the_one_the_workflow_builds(string $backend): void
    {
        $this->benchOn($backend);

        $state = $this->order()->start(new OrderPlaced('777', 'Leave it with the neighbour.'))->run();

        self::assertSame(['placed:777'], $this->transcript->lines());
        self::assertSame('Leave it with the neighbour.', $state->get('note'));
    }

    #[DataProvider('backends')]
    public function test_an_event_no_node_handles_is_refused_as_invalid_start(string $backend): void
    {
        $this->benchOn($backend);
        $order = $this->order();

        $refusal = self::thrownBy(static fn (): PendingSegment => $order->start(new StartEvent()));

        self::assertInstanceOf(CloudException::class, $refusal);
        self::assertSame(Code::InvalidStart, $refusal->reason);
        self::assertStringContainsString(StartEvent::class, $refusal->getMessage(), 'The refusal names the event.');
    }

    /**
     * Rule 4 proves that some node handles the event, not that the event is
     * the one the graph begins with.
     */
    #[DataProvider('backends')]
    public function test_an_event_only_a_later_node_handles_is_accepted_and_ignites_the_run_at_that_node(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $state = $this->order()->start(new PaymentReceived())->run();

        self::assertSame(WorkflowStatus::Suspended, $state->getStatus());
        self::assertSame([0, 1], [$bench->executions->count(AwaitPaymentNode::class), $bench->executions->count(ShipOrderNode::class)]);
    }

    #[DataProvider('backends')]
    public function test_a_workflow_whose_own_event_no_node_handles_cannot_start_with_no_input(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register('misrouted', static fn (): Workflow => new MisroutedWorkflow($executions));
        $misrouted = $bench->cloud->workflow('misrouted', self::WORKFLOW_ID);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $misrouted->start());

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('backends')]
    public function test_a_start_event_neuron_cannot_store_is_refused_as_invalid_start(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->cloud->register('unstorable', static fn (): Workflow => (new Workflow())->addNode(new UnstorableStartNode()));
        $unstorable = $bench->cloud->workflow('unstorable', self::WORKFLOW_ID);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $unstorable->start(new UnstorableStart()));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('messagesForAWorkflowOnEveryBackend')]
    public function test_messages_given_to_a_workflow_that_is_not_an_agent_are_refused_as_invalid_start(string $backend, UserMessage|array $messages): void
    {
        $this->benchOn($backend);
        $order = $this->order();

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $order->start($messages));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('refusedStartsOnEveryBackend')]
    public function test_a_refused_start_announces_nothing(string $backend, string $refused): void
    {
        $bench = $this->benchOn($backend);

        $this->refuse($refused);

        self::assertSame([], $this->queue->dispatched(), 'Nothing was handed to the queue.');
        self::assertSame([], $bench->platform->received(), 'Nothing was sent to the platform.');
    }

    #[DataProvider('refusedStartsOnEveryBackend')]
    public function test_a_refused_start_neither_reads_nor_writes_the_store(string $backend, string $refused): void
    {
        $this->benchOn($backend);

        $this->refuse($refused);

        self::assertSame([], $this->storeOperations);
        self::assertNull($this->holder(), 'A bad start event must never create a run.');
    }

    #[DataProvider('refusedStartsOnEveryBackend')]
    public function test_a_refused_start_executes_no_node(string $backend, string $refused): void
    {
        $bench = $this->benchOn($backend);

        $this->refuse($refused);

        self::assertSame(0, $bench->executions->total());
        self::assertSame([], $this->transcript->lines());
    }

    /**
     * @return iterable<string, array{string, string}> a backend, and the start that breaks a rule
     */
    public static function refusedStartsOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            foreach (['an event no node handles', 'an event Neuron cannot store', 'a message'] as $refused) {
                yield "{$refused}, on {$store}" => [$backend, $refused];
            }
        }
    }

    /**
     * @return iterable<string, array{string, UserMessage|array<int, UserMessage>}>
     */
    public static function messagesForAWorkflowOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "a message, on {$store}" => [$backend, new UserMessage('Where is my order?')];
            yield "a list of messages, on {$store}" => [$backend, [new UserMessage('Where is my order?')]];
            yield "an empty list, on {$store}" => [$backend, []];
        }
    }

    /**
     * Call start() with an input that breaks a rule, and let the refusal go.
     */
    protected function refuse(string $refused): void
    {
        $cloud = $this->bench->cloud;
        $cloud->register('unstorable', static fn (): Workflow => (new Workflow())->addNode(new UnstorableStartNode()));

        $reason = self::reasonThrownBy(fn (): PendingSegment => match ($refused) {
            'an event no node handles' => $this->order()->start(new StartEvent()),
            'an event Neuron cannot store' => $cloud->workflow('unstorable', self::WORKFLOW_ID)->start(new UnstorableStart()),
            default => $this->order()->start(new UserMessage('Where is my order?')),
        });

        self::assertSame(Code::InvalidStart, $reason, "The start with {$refused} was not refused.");
    }
}
