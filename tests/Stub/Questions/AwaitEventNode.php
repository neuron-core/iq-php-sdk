<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Questions;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Waits for an event whose name the test chose: Neuron lets it hold any bytes.
 */
class AwaitEventNode extends Node
{
    public function __construct(protected string $eventName)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->awaitEvent($this->eventName);

        return new StopEvent();
    }
}
