<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * Completes in a single segment.
 */
class CompletingWorkflow extends Workflow
{
    public function __construct(protected ExecutionLog $executions)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new CompleteNode($this->executions)];
    }
}
