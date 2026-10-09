<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class NarrateNode extends Node
{
    public function __construct(protected ExecutionLog $executions)
    {
    }

    /**
     * @return Generator<int, TextChunk, mixed, StopEvent>
     */
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        $this->executions->record(self::class);

        foreach (NarratingWorkflow::WORDS as $word) {
            yield new TextChunk('narration', $word);
        }

        return new StopEvent();
    }
}
