<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Continuations;

use NeuronAI\Workflow\Workflow;

/**
 * Waits for the result of one tool call on Neuron's own ToolResultsRequest,
 * a wait that looks inside the answer it is given, and completes.
 */
class LookupWorkflow extends Workflow
{
    public const CALL_ID = 'call_lookup';

    protected function nodes(): array
    {
        return [new AwaitLookupNode()];
    }
}
