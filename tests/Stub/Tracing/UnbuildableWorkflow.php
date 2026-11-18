<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Tracing;

use NeuronAI\Workflow\Workflow;
use RuntimeException;

/**
 * Its graph cannot be built: Neuron fails the segment before it reports its start.
 */
class UnbuildableWorkflow extends Workflow
{
    protected function nodes(): array
    {
        throw new RuntimeException('The graph cannot be built.');
    }
}
