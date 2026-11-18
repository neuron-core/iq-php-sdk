<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ShipOrderNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }
}
