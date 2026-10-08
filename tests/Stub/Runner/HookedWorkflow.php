<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use Closure;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowResources;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * Completes in a single segment, and lets a test act inside the hooks Neuron
 * calls while the start event and the graph are built.
 */
class HookedWorkflow extends CompletingWorkflow
{
    /**
     * @param Closure(string $hook): void $inHook called with the name of the hook, from inside each of them
     */
    public function __construct(ExecutionLog $executions, protected Closure $inHook)
    {
        parent::__construct($executions);
    }

    protected function startEvent(): Event
    {
        ($this->inHook)(__FUNCTION__);

        return parent::startEvent();
    }

    protected function resources(): WorkflowResources
    {
        ($this->inHook)(__FUNCTION__);

        return parent::resources();
    }

    protected function nodes(): array
    {
        ($this->inHook)(__FUNCTION__);

        return parent::nodes();
    }
}
