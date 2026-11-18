<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class DeliverNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected DateTimeImmutable $deliveryAt)
    {
    }

    public function __invoke(PaymentSettled $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        $this->sleepUntil($this->deliveryAt);

        return new StopEvent();
    }
}
