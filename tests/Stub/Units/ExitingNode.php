<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Streams one word, then ends the process with exit(), as a node does when
 * code it calls gives up on the whole request.
 */
class ExitingNode extends Node
{
    /**
     * @return Generator<int, TextChunk, mixed, StopEvent>
     */
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        yield new TextChunk('farewell', 'Goodbye.');

        exit(0);
    }
}
