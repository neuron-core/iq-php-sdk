<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * Its only node fails a number of times, then succeeds and completes the run.
 */
class FlakyWorkflow extends Workflow
{
    /**
     * @param int $failures how many executions of the node throw before one succeeds
     */
    public function __construct(protected ExecutionLog $executions, protected int $failures = 1)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new FlakyNode($this->executions, $this->failures)];
    }
}
