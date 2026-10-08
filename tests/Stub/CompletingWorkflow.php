<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

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
