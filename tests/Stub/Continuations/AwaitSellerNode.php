<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Continuations;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class AwaitSellerNode extends Node
{
    public function __construct(protected ExecutionLog $executions)
    {
    }

    public function __invoke(BuyerSigned $event, WorkflowState $state): StopEvent
    {
        $signature = $this->awaitEvent(ContractWorkflow::EVENT);

        // Recorded past the wait: the log counts the signatures that reached it.
        $this->executions->record(self::class);
        $state->set('seller', $signature);

        return new StopEvent();
    }
}
