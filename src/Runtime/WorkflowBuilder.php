<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Observability\CloudListener;

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
     * @param (Closure(Definition): CloudListener)|null $tracer builds the listener that traces one workflow; null: no
     *                                                          segment is traced
     */
    public function __construct(protected Closure $persistence, protected ?Closure $tracer = null)
    {
    }

    /**
     * @param string|null $runId null when the workflow is built only to read the store
     */
    public function build(Definition $definition, string $workflowId, ?string $runId): Workflow
    {
        $workflow = ($definition->factory)($workflowId, $runId)->for($workflowId);

        // A listener holds the trace of one run, so each workflow gets its own, and only one that may execute.
        // It is subscribed here and not in impose(), which a segment calls once more after configure().
        if ($this->tracer instanceof Closure && $runId !== null) {
            $workflow->subscribe(ObservabilityEvent::class, ($this->tracer)($definition));
        }

        return $this->impose($workflow, $definition);
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
