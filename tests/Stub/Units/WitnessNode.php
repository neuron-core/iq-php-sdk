<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use Closure;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class WitnessNode extends Node
{
    /**
     * @param Closure(): void $whileRunning what the test wants to see while the run is running
     */
    public function __construct(protected Closure $whileRunning)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        ($this->whileRunning)();

        return new StopEvent();
    }
}
