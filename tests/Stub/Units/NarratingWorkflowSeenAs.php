<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use Closure;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;

/**
 * Streams as NarratingWorkflow does, and shows of its run what a test says:
 * the run as another process had left it when the store was read.
 */
class NarratingWorkflowSeenAs extends NarratingWorkflow
{
    /** @var (Closure(WorkflowRunSnapshot): WorkflowRunSnapshot)|null what a reader is shown in place of the run the store holds */
    public ?Closure $seenAs = null;

    public function inspect(): ?WorkflowRunSnapshot
    {
        $held = parent::inspect();

        return $held instanceof WorkflowRunSnapshot && $this->seenAs instanceof Closure ? ($this->seenAs)($held) : $held;
    }
}
