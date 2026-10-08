<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use Closure;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class BusyNode extends Node
{
    /**
     * @param Closure(): void $whileExecuting
     */
    public function __construct(protected ExecutionLog $executions, protected Closure $whileExecuting)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        ($this->whileExecuting)();

        return new StopEvent();
    }
}
