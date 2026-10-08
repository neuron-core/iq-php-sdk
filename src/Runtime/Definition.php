<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Workflow\Workflow;

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
}
