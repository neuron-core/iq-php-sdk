<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;

/**
 * One registered version of a workflow or agent class.
 *
 * @internal
 */
final class Definition
{
    /**
     * @param Closure(string $workflowId, ?string $runId): Workflow $factory
     * @param int $leaseTimeout seconds
     */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly Closure $factory,
        public readonly int $leaseTimeout,
    ) {
    }

    /**
     * Every start gives its run the name of its definition as the run's tag, and only that definition may
     * execute the run, read it for the platform or discard it. A run with no tag was started outside the SDK,
     * or before the SDK set one: it is not checked.
     *
     * @param WorkflowRunSnapshot|null $run the run holding a workflow ID; null when none does
     */
    public function mayActOn(?WorkflowRunSnapshot $run): bool
    {
        return $run?->tag === null || $run->tag === $this->name;
    }
}
