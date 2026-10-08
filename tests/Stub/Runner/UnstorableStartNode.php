<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class UnstorableStartNode extends Node
{
    public function __invoke(UnstorableStart $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }
}
