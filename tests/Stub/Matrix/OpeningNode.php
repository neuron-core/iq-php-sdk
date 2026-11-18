<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Matrix;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class OpeningNode extends Node
{
    public function __construct(
        protected ExecutionLog $executions,
        protected Opening $opening,
        protected MidBody $midBody,
    ) {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): Opened
    {
        $this->executions->record(self::class);

        if ($this->opening === Opening::AwaitsEvent) {
            $this->awaitEvent(MatrixWorkflow::EVENT);
        }

        if ($this->opening === Opening::Sleeps) {
            $this->sleepUntil(new DateTimeImmutable('@0'));
        }

        // Past its wait, the node is in the middle of its work.
        $this->midBody->reached();

        // The log is the only memory that survives a failed step, and another process.
        if ($this->opening === Opening::FailsOnce && $this->executions->count(self::class) === 1) {
            throw new NodeFailed('The opening node failed on purpose.');
        }

        return new Opened();
    }
}
