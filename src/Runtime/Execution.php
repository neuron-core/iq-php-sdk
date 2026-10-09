<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use Generator;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use Throwable;
use WeakReference;

use function getmypid;

/**
 * One request given to Neuron on a built workflow: how the call ended, for the
 * platform, and what Neuron returned or threw, for a caller in the same process.
 *
 * @internal
 */
final class Execution
{
    protected function __construct(
        public readonly Settlement $settlement,
        protected WorkflowState|Throwable $result,
    ) {
    }

    /**
     * The request given to Neuron's run(), which a class of the application
     * may override.
     */
    public static function of(Workflow $workflow, ExecutionRequest $request): self
    {
        $settlement = null;
        $call = self::classified($workflow, $request, self::eagerly(...), static function (?Settlement $ended) use (&$settlement): void {
            $settlement = $ended;
        });

        try {
            // Nothing is yielded to this caller: the first step is the whole call.
            $call->valid();

            return new self($settlement, $call->getReturn());
        } catch (Throwable $thrown) {
            return new self($settlement, $thrown);
        }
    }

    /**
     * The request as a stream: the generator yields, returns and throws what
     * Neuron's own events() does, and Neuron admits the request on the first
     * iteration. When the caller stops before the end, Neuron's generator is
     * destroyed before $onSettled is told, so the store holds how the segment
     * ended by then. A generator that is never iterated asks Neuron nothing
     * and tells nothing.
     *
     * @param Closure(Settlement|null): void $onSettled told once how the call ended, before the caller gets the result:
     *                                                  null when the caller stopped and Neuron had not reported the
     *                                                  segment as suspended or completed. A forked child that only
     *                                                  releases its copy of a stream it did not end tells nothing
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable Neuron's refusal, or what a node threw
     */
    public static function events(Workflow $workflow, ExecutionRequest $request, Closure $onSettled): Generator
    {
        return self::classified($workflow, $request, self::relayed(...), $onSettled);
    }

    /**
     * The result of the call, as a direct call to Neuron would have given it.
     *
     * @throws Throwable Neuron's refusal, or what a node threw
     */
    public function state(): WorkflowState
    {
        return $this->result instanceof WorkflowState ? $this->result : throw $this->result;
    }

    /**
     * What Neuron gives for the request, and how the call ended.
     *
     * @param Closure(Workflow, ExecutionRequest): Generator<int, object, mixed, WorkflowState> $ask how Neuron is asked
     * @param Closure(Settlement|null): void $onSettled
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable
     */
    protected static function classified(Workflow $workflow, ExecutionRequest $request, Closure $ask, Closure $onSettled): Generator
    {
        // Neuron reports every segment it opens: no event means this call opened none. The first event is kept,
        // because a listener of the application that throws on a later one stops it from reaching this listener.
        $opened = null;
        // How Neuron said the segment ended. A node may pass on the events of a workflow it runs itself: only the
        // end the given workflow reports is the end of this segment.
        $closed = null;
        // Held weakly: the workflow keeps this listener, and a listener that kept the workflow would keep both
        // alive until PHP collects cycles, with the store connection the workflow holds.
        $given = WeakReference::create($workflow);
        $workflow->subscribe(ObservabilityEvent::class, static function (ObservabilityEvent $event) use ($given, &$opened, &$closed): void {
            $opened ??= $event->execution;

            if ($event instanceof WorkflowEnd && $event->source === $given->get()) {
                $closed = $event->state->getStatus();
            }
        });

        $settlement = null;
        $process = getmypid();

        try {
            // No variable holds the delegated generators: when the caller stops, PHP destroys them, and Neuron's own
            // with them, before it runs the finally block below, and still runs that block when Neuron's generator
            // throws while it is destroyed. A variable released with unset() in the block would skip the rest of it then.
            $state = yield from self::retryingConflict($workflow, $request, $ask, $opened);
            $settlement = Settlement::ran($state->getStatus(), $opened?->executionAttempt);

            return $state;
        } catch (WorkflowRefusedException $refused) {
            // Inside an open segment Neuron refuses only the write of a segment that lost its run:
            // any other refusal escaped from a node, and Neuron has failed the run for it.
            $settlement = $opened instanceof ExecutionContext && $refused->reason !== RefusalReason::StaleAttempt
                ? Settlement::failed($refused, $opened->executionAttempt)
                : Settlement::refused($refused->reason, $opened?->executionAttempt);

            throw $refused;
        } catch (Throwable $error) {
            $settlement = $opened instanceof ExecutionContext
                ? Settlement::failed($error, $opened->executionAttempt)
                : Settlement::aborted(Code::NotExecuted, $error);

            throw $error;
        } finally {
            // A forked child that releases its copy of a stream still open tells nothing, as Neuron settles nothing
            // there: the segment is its parent's to report.
            if ($settlement instanceof Settlement || $process === getmypid()) {
                // An end Neuron reported as suspended or completed stands, whatever was thrown after it. With neither
                // that nor a result, the caller stopped the stream and nothing here says how it ended: the store does.
                $onSettled($closed === WorkflowStatus::Completed || $closed === WorkflowStatus::Suspended
                    ? Settlement::ran($closed, $opened?->executionAttempt)
                    : $settlement);
            }
        }
    }

    /**
     * What Neuron gives for the request, with a conflict of the ignition tried once more.
     *
     * @param Closure(Workflow, ExecutionRequest): Generator<int, object, mixed, WorkflowState> $ask
     * @param ExecutionContext|null $opened the segment this call opened, set by the listener while Neuron runs
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws WorkflowRefusedException
     * @throws Throwable
     */
    protected static function retryingConflict(
        Workflow $workflow,
        ExecutionRequest $request,
        Closure $ask,
        ?ExecutionContext &$opened,
    ): Generator {
        try {
            return yield from $ask($workflow, $request);
        } catch (WorkflowRefusedException $refused) {
            // A conflict that escaped from a node is not one of the ignition: the run has already executed.
            if ($refused->reason !== RefusalReason::Conflict || $opened instanceof ExecutionContext) {
                throw $refused;
            }
        }

        // Neuron retries a conflict itself only for a start that reserves no run ID, and the SDK always reserves one.
        // It yields nothing before it opens a segment, so the caller has received nothing of the first try.
        return yield from $ask($workflow, $request);
    }

    /**
     * Neuron's run() as a generator that yields nothing and returns its state.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable
     */
    protected static function eagerly(Workflow $workflow, ExecutionRequest $request): Generator
    {
        yield from [];

        return $workflow->run($request);
    }

    /**
     * Neuron's events() taken one by one and yielded again. An exception the
     * caller throws into the stream is thrown into Neuron's.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     * @throws Throwable
     */
    protected static function relayed(Workflow $workflow, ExecutionRequest $request): Generator
    {
        // Held here and never delegated to with "yield from": on PHP 8.1 an exit() reached while Neuron runs under
        // such a delegation ends the process with a segmentation fault. When the caller stops, PHP destroys this
        // generator, and Neuron's with it, before classified() runs its finally block. A finally block here would
        // run before Neuron's generator is destroyed: nothing is told from here.
        $neuron = $workflow->events($request);

        while ($neuron->valid()) {
            try {
                yield $neuron->key() => $neuron->current();
            } catch (Throwable $thrownIn) {
                $neuron->throw($thrownIn);

                continue;
            }

            $neuron->next();
        }

        return $neuron->getReturn();
    }
}
