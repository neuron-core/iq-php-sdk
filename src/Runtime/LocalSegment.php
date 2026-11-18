<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use Closure;
use Generator;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Verb;
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
     * @throws IQException segment_used; dispatch_failed, when the queue did not take the announcement: nothing ignited
     */
    public function run(?Closure $configure = null): Execution
    {
        $this->useOnce();
        $this->configure($configure);
        $this->announce();

        $execution = Execution::of($this->workflow, $this->request);
        $this->observe($execution->settlement);

        return $execution;
    }

    /**
     * Execute the segment in this process as a stream. The announcement is in
     * the queue when the generator is returned. Neuron admits the segment on
     * the first iteration, and the observe follows when the stream ends, when
     * its consumer stops before that, or when Neuron gives the first frame of
     * a segment that failed.
     *
     * @param (Closure(Workflow): mixed)|null $configure adjusts the workflow before it executes
     * @return Generator<int, object, mixed, WorkflowState> what Neuron's own events() yields, returns and throws
     * @throws IQException segment_used; dispatch_failed, when the queue did not take the announcement: nothing ignited
     */
    public function events(?Closure $configure = null): Generator
    {
        $this->useOnce();
        $this->configure($configure);
        $this->announce();

        return Execution::events($this->workflow, $this->request, $this->observe(...));
    }

    /**
     * Hand the segment to the queue, for a worker to execute.
     *
     * @return string the run ID
     * @throws RunInFlightException when a start meets a run that holds the workflow ID
     * @throws IQException segment_used; dispatch_failed, when the queue did not take it
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
     * @throws IQException segment_used
     */
    protected function useOnce(): void
    {
        if ($this->used) {
            throw new IQException(
                'This segment was already used by run(), events() or dispatch(): prepare a new one with start(), submitInputs() or signal().',
                Code::SegmentUsed,
            );
        }

        $this->used = true;
    }

    /**
     * @param (Closure(Workflow): mixed)|null $configure
     */
    protected function configure(?Closure $configure): void
    {
        if ($configure instanceof Closure) {
            $configure($this->workflow);
            // The callback adjusts how the segment streams: the store, the lease and the completion policy stay the SDK's.
            $this->builder->impose($this->workflow, $this->definition);
        }
    }

    /**
     * @throws IQException dispatch_failed
     */
    protected function announce(): void
    {
        $this->hand(Notices::announce(
            $this->invocation,
            Notices::announcement($this->invocation, $this->definition->leaseTimeout),
        ));
    }

    /**
     * Hand the queue the observe of the segment: a worker reads the store and
     * reports how it ended. A queue that does not take it changes nothing for
     * the caller.
     *
     * @param Settlement|null $settlement null for a stream its consumer stopped, unless Neuron had reported the segment as
     *                                    suspended, completed or failed: the observe then carries no outcome, and the
     *                                    report says "observed"
     */
    protected function observe(?Settlement $settlement): void
    {
        $observe = $settlement instanceof Settlement
            ? Notices::observe($this->invocation, $settlement->facts($this->invocation))
            : Invocation::observeOf($this->invocation);

        try {
            $this->dispatcher->dispatch($observe);
        } catch (Throwable) {
            // The caller still gets what Neuron answered: the platform asks when the announced lease has passed.
        }
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
     * @throws IQException dispatch_failed
     */
    protected function hand(Invocation $message): void
    {
        try {
            $this->dispatcher->dispatch($message);
        } catch (Throwable $undispatched) {
            throw new IQException(
                "The queue did not take the '{$message->verb->value}' of '{$message->definition}' for workflow ID "
                . "'{$message->workflowId}' (run ID '{$message->runId}'): nothing ran in this process.",
                Code::DispatchFailed,
                $undispatched,
            );
        }
    }
}
