<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use Closure;
use Generator;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Runtime\LocalSegment;
use Throwable;

/**
 * One segment that the application's own code prepared: run it in this
 * process, at once or as a stream, or dispatch it to the queue. It is used once.
 */
final class PendingSegment
{
    /**
     * @internal ManagedWorkflow builds it
     *
     * @param (Closure(Workflow): mixed)|null $configure adjusts the workflow that run() and events() execute
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
     * Execute the segment in this process and take its events one by one, as
     * Neuron's own events() gives them: a chat turn streamed in a web request.
     * The segment is announced before the generator is returned and admitted
     * on the first iteration: call valid() first, and a refusal is thrown
     * before anything is sent to the browser. A consumer that stops before
     * the end makes Neuron fail a run it was still executing. The queue is
     * handed the observe when the stream ends or its consumer stops. For a
     * consumer that stopped it says how the segment ended only when Neuron
     * had reported it as suspended or completed: otherwise the platform is
     * told what the store shows. A generator that is never iterated, or a
     * connection PHP aborts, hands none: the platform asks when the
     * announced lease has passed.
     *
     * @return Generator<int, object, mixed, WorkflowState> it throws what Neuron throws: a refusal, or the exception of a node
     * @throws CloudException segment_used; dispatch_failed, when the queue did not take the announcement: nothing ran
     */
    public function events(): Generator
    {
        return $this->segment->events($this->configure);
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
