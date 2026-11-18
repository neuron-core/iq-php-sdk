<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;

/**
 * A workflow whose class overrides run(), as a class of an application may:
 * it counts the calls, then runs as Neuron does. Its only node fails a number
 * of times before it completes the run.
 */
class RunOverridingWorkflow extends FlakyWorkflow
{
    public function run(?ExecutionRequest $request = null): WorkflowState
    {
        $this->executions->record(self::class);

        return parent::run($request);
    }
}
