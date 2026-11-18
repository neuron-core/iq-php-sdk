<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Local;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;
use NeuronCore\IQ\Tests\Stub\Units\OrderPlaced;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class AwaitOrderPaymentNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected int $failures)
    {
    }

    public function __invoke(OrderPlaced $event, WorkflowState $state): PaymentSettled
    {
        $this->executions->record(self::class);

        // The log is the only memory that survives a failed step, and another process.
        if ($this->executions->count(self::class) <= $this->failures) {
            throw new NodeFailed('The stock of the order could not be reserved.');
        }

        $state->set('orderId', $event->orderId);
        $state->set('payment', $this->awaitEvent(OrderFulfilment::EVENT));

        return new PaymentSettled();
    }
}
