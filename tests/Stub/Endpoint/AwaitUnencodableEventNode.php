<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Endpoint;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Waits for an event whose name is not UTF-8: no JSON body can hold that name
 * as it is.
 */
class AwaitUnencodableEventNode extends Node
{
    public const EVENT = "payment\xFFreceived";

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->awaitEvent(self::EVENT);

        return new StopEvent();
    }
}
