<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

/**
 * Every segment, snapshot and management call builds its workflow here, so
 * the SDK's persistence and lease always replace the class's own.
 *
 * @internal
 */
final class WorkflowBuilder
{
    /**
     * @param Closure(): PersistenceInterface $persistence
     */
    public function __construct(protected Closure $persistence)
    {
    }

    /**
     * @param string|null $runId null when the workflow is built only to read the store
     */
    public function build(Definition $definition, string $workflowId, ?string $runId): Workflow
    {
        return $this->impose(($definition->factory)($workflowId, $runId)->for($workflowId), $definition);
    }

    /**
     * What the SDK decides for every workflow it executes, whatever the class
     * or a callback of the application set before.
     */
    public function impose(Workflow $workflow, Definition $definition): Workflow
    {
        return $workflow
            ->setPersistence(($this->persistence)())
            // Neuron takes the lease with each admission and does not store it with the run.
            ->setLeaseTimeout($definition->leaseTimeout)
            ->retainCompletionUntilAcknowledged(false);
    }
}
