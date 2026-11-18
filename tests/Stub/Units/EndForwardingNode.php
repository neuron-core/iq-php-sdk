<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\WorkflowState;

/**
 * A node that runs a workflow of its own may pass on the events of that
 * workflow with emit(). This one passes on the end of an inner run that
 * completed, then streams one word of its own.
 */
class EndForwardingNode extends Node
{
    /**
     * @return Generator<int, TextChunk, mixed, StopEvent>
     */
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        $inner = new WorkflowState();
        $inner->clearInterrupt();
        $this->emit(new WorkflowEnd($inner));

        yield new TextChunk('outer', 'Done.');

        return new StopEvent();
    }
}
