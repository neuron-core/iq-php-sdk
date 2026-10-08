<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Verb;
use Throwable;

use function time;

/**
 * A segment the application's own code issued: executed in the calling
 * process, or handed to the dispatcher for a worker. The two notices of a
 * segment it runs go to the dispatcher too: it never posts them itself.
 *
 * @internal
 */
final class LocalSegment
{
    /** Seconds a dispatched start may wait in the queue before it is refused. */
    protected const START_LIFETIME = 3600;

    /** Seconds after which a dispatched answer or signal that was aborted is no longer queued again. */
    protected const CONTINUATION_LIFETIME = 86400;

    /** The segment as the platform hears of it: its input and its use-by time exist only in the queue. */
    protected Invocation $invocation;

    /** Kept here, not on the PendingSegment: every copy of that object holds this segment, so this run ID. */
    protected bool $used = false;

    /**
     * @param Workflow $workflow built for the workflow ID, on the version the run is pinned to
     * @param ExecutionRequest $request what Neuron is asked: it holds the run ID and, for a continuation, the fence
     */
    public function __construct(
        Verb $verb,
        protected Definition $definition,
        string $workflowId,
        protected Workflow $workflow,
        protected ExecutionRequest $request,
        protected DispatcherInterface $dispatcher,
        protected WorkflowBuilder $builder,
    ) {
        $this->invocation = new Invocation(
            UniqueIdGenerator::generateId('inv_'),
            $verb,
            Origin::APPLICATION,
            $definition->name,
            $workflowId,
            (string) $request->runId,
            $request->executionAttempt,
        );
    }

    /**
     * Execute the segment in this process, between an announcement and an
     * observe handed to the queue.
     *
     * @param (Closure(Workflow): mixed)|null $configure adjusts the workflow before it executes
     * @throws CloudException segment_used; dispatch_failed, when the queue did not take the announcement: nothing ignited
     */
    public function run(?Closure $configure = null): Execution
    {
        $this->useOnce();

        if ($configure instanceof Closure) {
            $configure($this->workflow);
            // The callback adjusts how the segment streams: the store, the lease and the completion policy stay the SDK's.
            $this->builder->impose($this->workflow, $this->definition);
        }

        $this->hand(Notices::announce(
            $this->invocation,
            Notices::announcement($this->invocation, $this->definition->leaseTimeout),
        ));

        $execution = Execution::of($this->workflow, $this->request);

        try {
            $this->dispatcher->dispatch(Notices::observe($this->invocation, $execution->settlement->facts($this->invocation)));
        } catch (Throwable) {
            // The caller still gets what Neuron answered: the platform asks when the announced lease has passed.
        }

        return $execution;
    }

    /**
     * Hand the segment to the queue, for a worker to execute.
     *
     * @return string the run ID
     * @throws RunInFlightException when a start meets a run that holds the workflow ID
     * @throws CloudException segment_used; dispatch_failed, when the queue did not take it
     */
    public function dispatch(): string
    {
        $this->useOnce();

        if ($this->request->starting) {
            $this->assertNoRunHoldsTheWorkflowId();
        }

        $this->hand($this->queued());

        return $this->invocation->runId;
    }

    /**
     * A second use would execute the same run ID again.
     *
     * @throws CloudException segment_used
     */
    protected function useOnce(): void
    {
        if ($this->used) {
            throw new CloudException(
                'This segment was already run or dispatched: prepare a new one with start(), submitInputs() or signal().',
                Code::SegmentUsed,
            );
        }

        $this->used = true;
    }

    /**
     * A start never replaces a run, whatever state that run is in: the worker
     * would refuse it, so the caller is told now.
     *
     * @throws RunInFlightException
     */
    protected function assertNoRunHoldsTheWorkflowId(): void
    {
        $holder = $this->workflow->inspect();

        if ($holder instanceof WorkflowRunSnapshot) {
            throw new RunInFlightException(
                workflowId: $holder->workflowId,
                runId: $holder->runId,
                status: $holder->status,
                executionAttempt: $holder->executionAttempt,
                leaseExpiresAt: $holder->leaseExpiresAt,
                interrupt: $holder->interrupt,
                reservedRunId: $this->request->runId,
            );
        }
    }

    /**
     * The segment as a worker receives it: with its input, and with the time
     * after which the worker gives it up.
     */
    protected function queued(): Invocation
    {
        $event = $this->request->event();

        return new Invocation(
            $this->invocation->invocationId,
            $this->invocation->verb,
            $this->invocation->origin,
            $this->invocation->definition,
            $this->invocation->workflowId,
            $this->invocation->runId,
            $this->invocation->attempt,
            expiresAt: time() + ($this->request->starting ? self::START_LIFETIME : self::CONTINUATION_LIFETIME),
            signal: $this->request->signal,
            payload: $this->request->payload(),
            event: $event instanceof Event ? StartEvents::encode($event) : null,
        );
    }

    /**
     * @throws CloudException dispatch_failed
     */
    protected function hand(Invocation $message): void
    {
        try {
            $this->dispatcher->dispatch($message);
        } catch (Throwable $undispatched) {
            throw new CloudException(
                "The queue did not take the '{$message->verb->value}' of '{$message->definition}' for workflow ID "
                . "'{$message->workflowId}' (run ID '{$message->runId}'): nothing ran in this process.",
                Code::DispatchFailed,
                $undispatched,
            );
        }
    }
}
