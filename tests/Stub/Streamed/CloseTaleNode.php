<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Streamed;

use DateTimeImmutable;
use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class CloseTaleNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected Ending $ending)
    {
    }

    /**
     * @return Generator<int, TextChunk, mixed, StopEvent>
     */
    public function __invoke(TaleOpened $event, WorkflowState $state): Generator
    {
        $this->executions->record(self::class);

        [$first, $last] = TaleWorkflow::CLOSING;

        yield new TextChunk(TaleWorkflow::MESSAGE_ID, $first);

        if ($this->ending === Ending::Fails) {
            throw new NodeFailed('The tale broke off on purpose.');
        }

        if ($this->ending === Ending::Refuses) {
            throw new WorkflowRefusedException('A workflow this node drives refused it.', RefusalReason::NotAwaited);
        }

        if ($this->ending === Ending::Waits) {
            $this->awaitEvent(TaleWorkflow::EVENT, new DateTimeImmutable('@0'));
        }

        yield new TextChunk(TaleWorkflow::MESSAGE_ID, $last);

        $state->set('told', true);

        return new StopEvent();
    }
}
