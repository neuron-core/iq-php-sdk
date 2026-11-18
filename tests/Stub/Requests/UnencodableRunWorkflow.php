<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Requests;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * A workflow whose own inspect() shows a run under an ID that is not UTF-8.
 * Neuron refuses such a run ID, so no store holds one: this class makes one
 * up to bring the endpoint to a failure it does not expect, when the answer
 * that shows the run cannot be encoded.
 */
class UnencodableRunWorkflow extends Workflow
{
    public const RUN_ID = "1-\xFF";

    public function inspect(): ?WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot(self::RUN_ID, WorkflowStatus::Failed, 1, null, (string) $this->getWorkflowId(), new StartEvent());
    }
}
