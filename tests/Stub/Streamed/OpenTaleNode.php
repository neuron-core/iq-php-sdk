<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Streamed;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\Matrix\MidBody;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class OpenTaleNode extends Node
{
    public function __construct(
        protected ExecutionLog $executions,
        protected bool $awaitsReader,
        protected MidBody $midStream,
    ) {
    }

    /**
     * @return Generator<int, TextChunk, mixed, TaleOpened>
     */
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        $this->executions->record(self::class);

        if ($this->awaitsReader) {
            $state->set('reader', $this->awaitEvent(TaleWorkflow::EVENT));
        }

        [$first, $second] = TaleWorkflow::OPENING;

        yield new TextChunk(TaleWorkflow::MESSAGE_ID, $first);

        // Its consumer holds the first word and has asked for the next: the node is in the middle of its stream.
        $this->midStream->reached();

        yield new TextChunk(TaleWorkflow::MESSAGE_ID, $second);

        return new TaleOpened();
    }
}
