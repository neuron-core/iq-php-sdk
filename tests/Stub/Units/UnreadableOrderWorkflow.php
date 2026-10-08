<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\Workflow\Events\Event;
use RuntimeException;

/**
 * Its startEvent() hook cannot load what the event is built from.
 */
class UnreadableOrderWorkflow extends OrderWorkflow
{
    protected function startEvent(): Event
    {
        throw new RuntimeException('The orders table cannot be read.');
    }
}
