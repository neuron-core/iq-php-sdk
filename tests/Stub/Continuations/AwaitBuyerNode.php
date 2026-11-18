<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Continuations;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

class AwaitBuyerNode extends Node
{
    public function __construct(protected ExecutionLog $executions)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): BuyerSigned
    {
        $signature = $this->awaitEvent(ContractWorkflow::EVENT);

        // Recorded past the wait: the log counts the signatures that reached it.
        $this->executions->record(self::class);
        $state->set('buyer', $signature);

        return new BuyerSigned();
    }
}
