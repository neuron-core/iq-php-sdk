<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Concurrency;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\Concurrency\Gate;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class StallNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected Gate $gate)
    {
    }

    public function __invoke(Prepared $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        $this->gate->pass();

        return new StopEvent();
    }
}
