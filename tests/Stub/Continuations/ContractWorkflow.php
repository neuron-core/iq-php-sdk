<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Continuations;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * A contract that two parties sign: it waits for the event "contract.signed"
 * from the buyer, then for the same event from the seller, and completes. A
 * run therefore has two waits of the same kind, one after the other.
 */
class ContractWorkflow extends Workflow
{
    public const EVENT = 'contract.signed';

    public function __construct(protected ExecutionLog $executions)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new AwaitBuyerNode($this->executions),
            new AwaitSellerNode($this->executions),
        ];
    }
}
