<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Concurrency;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

class PrepareNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected ?DateTimeImmutable $timer)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): Prepared
    {
        $this->executions->record(self::class);

        if ($this->timer instanceof DateTimeImmutable) {
            $this->sleepUntil($this->timer);
        }

        return new Prepared();
    }
}
