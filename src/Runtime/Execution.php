<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Code;
use Throwable;

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

    public static function of(Workflow $workflow, ExecutionRequest $request): self
    {
        // Neuron reports every segment it opens: no event means this call opened none. The first event is kept,
        // because a listener of the application that throws on a later one stops it from reaching this listener.
        $opened = null;
        $workflow->subscribe(ObservabilityEvent::class, static function (ObservabilityEvent $event) use (&$opened): void {
            $opened ??= $event->execution;
        });

        try {
            $state = self::run($workflow, $request, $opened);

            return new self(Settlement::ran($state->getStatus(), $opened?->executionAttempt), $state);
        } catch (WorkflowRefusedException $refused) {
            // Inside an open segment Neuron refuses only the write of a segment that lost its run:
            // any other refusal escaped from a node, and Neuron has failed the run for it.
            return new self(
                $opened instanceof ExecutionContext && $refused->reason !== RefusalReason::StaleAttempt
                    ? Settlement::failed($refused, $opened->executionAttempt)
                    : Settlement::refused($refused->reason, $opened?->executionAttempt),
                $refused,
            );
        } catch (Throwable $error) {
            return new self(
                $opened instanceof ExecutionContext
                    ? Settlement::failed($error, $opened->executionAttempt)
                    : Settlement::aborted(Code::NotExecuted, $error),
                $error,
            );
        }
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
     * @param ExecutionContext|null $opened the segment this call opened, set by the listener while Neuron runs
     * @throws WorkflowRefusedException
     * @throws Throwable
     */
    protected static function run(Workflow $workflow, ExecutionRequest $request, ?ExecutionContext &$opened): WorkflowState
    {
        try {
            return $workflow->run($request);
        } catch (WorkflowRefusedException $refused) {
            // A conflict that escaped from a node is not one of the ignition: the run has already executed.
            if ($refused->reason !== RefusalReason::Conflict || $opened instanceof ExecutionContext) {
                throw $refused;
            }
        }

        // Neuron retries a conflict itself only for a start that reserves no run ID, and the SDK always reserves one.
        return $workflow->run($request);
    }
}
