<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\EndpointFlows;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\Concurrency\Gate;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Secrets;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\Flows\Transcript;

/**
 * The workflow of the plain PHP example as the platform starts it: with no
 * input, so it builds its start event from its workflow ID. Its first node
 * waits for the event "payment.received" and its second for a timer; the node
 * after the timer can fail, and stays inside its body until the gate opens.
 */
class OrderWorkflow extends Workflow
{
    public const EVENT = 'payment.received';

    /**
     * @param int $shipsAfter seconds between the payment and the moment the timer is due
     * @param int $shippingFailures how many times the node after the timer throws before it ships
     */
    public function __construct(
        protected ExecutionLog $executions,
        protected Transcript $transcript,
        protected Gate $gate,
        protected int $shipsAfter,
        protected int $shippingFailures,
    ) {
        parent::__construct();
    }

    protected function startEvent(): Event
    {
        return new OrderPlaced((string) $this->getWorkflowId(), Secrets::START_EVENT);
    }

    protected function nodes(): array
    {
        return [
            new AwaitPaymentNode($this->executions, $this->transcript),
            new ShipOrderNode($this->executions, $this->transcript, $this->gate, $this->shipsAfter, $this->shippingFailures),
        ];
    }
}
