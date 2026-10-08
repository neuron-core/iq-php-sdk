<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class CompleteNode extends Node
{
    public function __construct(protected ExecutionLog $executions)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        return new StopEvent();
    }
}
