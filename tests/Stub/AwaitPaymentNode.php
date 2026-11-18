<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class AwaitPaymentNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected ?DateTimeImmutable $deadline)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): PaymentSettled
    {
        $this->executions->record(self::class);

        // Null when the deadline passed with no event.
        $payment = $this->awaitEvent(WaitingWorkflow::EVENT, $this->deadline);
        $state->set('payment', $payment);

        return new PaymentSettled();
    }
}
