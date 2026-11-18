<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use Closure;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Observability\IQListener;

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
     * @param (Closure(Definition): IQListener)|null $tracer builds the listener that traces one workflow; null: no
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

        // Only a workflow built to execute gets a listener, one of its own: it names its spans after the definition,
        // and claims the segments it hears. It is subscribed here and not in impose(), which a segment calls once more
        // after configure().
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
