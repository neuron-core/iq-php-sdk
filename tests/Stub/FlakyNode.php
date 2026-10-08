<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class FlakyNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected int $failures)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        // The log is the only memory that survives a failed step, and another process.
        if ($this->executions->count(self::class) <= $this->failures) {
            throw new NodeFailed('The flaky node failed on purpose.');
        }

        return new StopEvent();
    }
}
