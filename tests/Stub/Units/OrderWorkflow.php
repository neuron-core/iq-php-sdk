<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

/**
 * Starts with an event of its own class, which its startEvent() hook builds
 * from the workflow ID, as a workflow the platform starts must.
 */
class OrderWorkflow extends Workflow
{
    protected function startEvent(): Event
    {
        return new OrderPlaced((string) $this->getWorkflowId());
    }

    protected function nodes(): array
    {
        return [new ShipOrderNode()];
    }
}
