<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Flows;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Support\Concurrency\Gate;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\Flows\Transcript;

/**
 * The workflow of the plain PHP example: its start event carries the order,
 * its first node waits for the event "payment.received" and its second for a
 * timer, then the run completes. Started with no input, it builds the event
 * from its workflow ID, as a workflow the platform starts must.
 */
class OrderWorkflow extends Workflow
{
    public const EVENT = 'payment.received';

    /**
     * @param DateTimeImmutable $shipsAt when the timer is due
     * @param int $shippingFailures how many times the node after the timer throws before it completes the run
     * @param Gate|null $gate when given, the first node stays inside its body until the gate opens
     */
    public function __construct(
        protected ExecutionLog $executions,
        protected Transcript $transcript,
        protected DateTimeImmutable $shipsAt = new DateTimeImmutable('@0'),
        protected int $shippingFailures = 0,
        protected ?Gate $gate = null,
    ) {
        parent::__construct();
    }

    protected function startEvent(): Event
    {
        return new OrderPlaced((string) $this->getWorkflowId());
    }

    protected function nodes(): array
    {
        return [
            new AwaitPaymentNode($this->executions, $this->transcript, $this->gate),
            new ShipOrderNode($this->executions, $this->transcript, $this->shipsAt, $this->shippingFailures),
        ];
    }
}
