<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

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
