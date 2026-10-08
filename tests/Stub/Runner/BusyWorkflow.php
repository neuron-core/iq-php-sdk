<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use Closure;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * Completes in a single segment, and lets a test act while that segment is executing.
 */
class BusyWorkflow extends Workflow
{
    /**
     * @param Closure(): void $whileExecuting called from inside the node, in the same process
     */
    public function __construct(protected ExecutionLog $executions, protected Closure $whileExecuting)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new BusyNode($this->executions, $this->whileExecuting)];
    }
}
