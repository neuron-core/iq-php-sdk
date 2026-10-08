<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Matrix;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class EndingNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected Ending $ending)
    {
    }

    public function __invoke(Opened $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        if ($this->ending === Ending::Fails) {
            throw new NodeFailed('The ending node failed on purpose.');
        }

        if ($this->ending === Ending::Waits) {
            $this->awaitEvent(MatrixWorkflow::EVENT, new DateTimeImmutable('@0'));
        }

        return new StopEvent();
    }
}
