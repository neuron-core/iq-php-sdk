<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use Closure;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Runtime\LocalSegment;
use Throwable;

/**
 * One segment that the application's own code prepared: run it in this
 * process, or dispatch it to the queue. It is used once.
 */
final class PendingSegment
{
    /**
     * @internal ManagedWorkflow builds it
     *
     * @param (Closure(Workflow): mixed)|null $configure adjusts the workflow that run() executes
     */
    public function __construct(protected LocalSegment $segment, protected ?Closure $configure)
    {
    }

    /**
     * Execute the segment in this process. The platform hears of it through
     * the dispatcher: with a queue behind it, no call to the platform sits on
     * the caller.
     *
     * @throws CloudException segment_used; dispatch_failed, when the queue did not take the announcement: nothing ran
     * @throws Throwable what Neuron throws: a refusal, or the exception of a node
     */
    public function run(): WorkflowState
    {
        return $this->segment->run($this->configure)->state();
    }

    /**
     * Hand the segment to the queue, for a worker to execute.
     *
     * @return string the run ID
     * @throws RunInFlightException when a start meets a run that holds the workflow ID
     * @throws CloudException segment_used; dispatch_failed, when the queue did not take the segment
     */
    public function dispatch(): string
    {
        return $this->segment->dispatch();
    }
}
