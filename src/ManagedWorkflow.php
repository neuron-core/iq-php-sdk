<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use Closure;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\LocalSegment;
use NeuronCore\Cloud\Runtime\Registry;
use NeuronCore\Cloud\Runtime\Settlement;
use NeuronCore\Cloud\Runtime\StartEvents;
use NeuronCore\Cloud\Runtime\WorkflowBuilder;
use Throwable;

use function in_array;
use function preg_match;
use function time;

/**
 * A registered workflow bound to one workflow ID: what the application's own
 * code starts, answers and recovers, so that the platform hears of its runs.
 */
final class ManagedWorkflow
{
    /** The workflow IDs the protocol carries, which is Neuron's own rule. */
    protected const WORKFLOW_ID = '/^(?!__)[^\x00-\x1F\x7F]{1,255}$/uD';

    /** @var (Closure(Workflow): mixed)|null */
    protected ?Closure $configure = null;

    /**
     * @internal NeuronCloud::workflow() builds it
     *
     * @throws CloudException unknown_definition for a name that is not registered; invalid_request for a workflow ID
     *                        the protocol cannot carry: Neuron would refuse it only after a segment was announced
     */
    public function __construct(
        protected string $name,
        protected string $workflowId,
        protected Registry $registry,
        protected WorkflowBuilder $builder,
        protected DispatcherInterface $dispatcher,
    ) {
        // A name that was never registered fails here, not at the first call.
        $registry->current($name);

        if (preg_match(self::WORKFLOW_ID, $workflowId) !== 1) {
            throw new CloudException(
                "The workflow ID given for '{$name}' cannot be used: use 1 to 255 characters, with no control character "
                . "and no leading '__'.",
                Code::InvalidRequest,
            );
        }
    }

    /**
     * The callback applies to the segments prepared after this call; dispatch()
     * and recover() never apply it. The persistence, the lease and the
     * completion policy stay the SDK's, whatever it sets.
     *
     * @param Closure(Workflow): mixed $callback adjusts the copy that run() executes
     */
    public function configure(Closure $callback): static
    {
        $this->configure = $callback;

        return $this;
    }

    /**
     * Prepare a new run on the current version. The start event is checked
     * and the run ID is minted here; nothing is written before the segment
     * is run or dispatched.
     *
     * @param Event|Message|Message[]|null $input an event; for an agent, the messages of the turn; null: the workflow's own start event
     * @param bool $stream for messages only: whether the provider streams its answer
     * @throws CloudException invalid_start, when the event could never ignite a run
     * @throws Throwable what a hook of the workflow threw while its event or its graph was built
     */
    public function start(Event|Message|array|null $input = null, bool $stream = false): PendingSegment
    {
        $definition = $this->registry->current($this->name);
        $runId = UniqueIdGenerator::generateId($definition->version . '-');
        $workflow = $this->builder->build($definition, $this->workflowId, $runId);
        $request = StartEvents::request(StartEvents::from($workflow, $input, $stream), $runId);

        return new PendingSegment(
            new LocalSegment(Verb::Start, $definition, $this->workflowId, $workflow, $request, $this->dispatcher, $this->builder),
            $this->configure,
        );
    }

    /**
     * Prepare the answer to the wait the run is suspended on. The run ID and
     * the attempt are captured here, so a second delivery of the answer is
     * refused and cannot reach a later wait.
     *
     * @param array<array-key, mixed> $payload
     * @throws InputTranslationException when no run is suspended on a wait, or the translator refuses the payload
     * @throws WorkflowRefusedException not_awaited, when the wait rejects the answer
     * @throws WorkflowException when Neuron could not hold the answer: it is not JSON-compatible
     * @throws CloudException unknown_version, when this process lacks the version the run is pinned to
     */
    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null): PendingSegment
    {
        $run = $this->suspendedRun();
        $definition = $this->registry->pinned($this->name, $run->runId);
        $answer = $translator?->translate($payload, $run->interrupt) ?? $payload;
        $this->assertAccepted($run->interrupt, $answer);

        return new PendingSegment(
            $this->continuation(Verb::Answer, $definition, ExecutionRequest::resume($answer, $run->runId, $run->executionAttempt)),
            $this->configure,
        );
    }

    /**
     * Prepare the delivery of an event to the run that waits for it. An event
     * that arrives before its wait is refused and not kept.
     *
     * @param array<string, mixed> $payload
     * @throws InputTranslationException when no run is suspended on a wait
     * @throws WorkflowRefusedException not_awaited, when the run waits for something else, or the wait rejects the payload
     * @throws WorkflowException when Neuron could not hold the payload: it is not JSON-compatible
     * @throws CloudException unknown_version, when this process lacks the version the run is pinned to
     */
    public function signal(string $event, array $payload = []): PendingSegment
    {
        $run = $this->suspendedRun();
        $definition = $this->registry->pinned($this->name, $run->runId);

        if (!$run->interrupt instanceof WaitForEventRequest || $run->interrupt->getEventName() !== $event) {
            throw new WorkflowRefusedException(
                "The current interruption is not waiting for signal '{$event}'.",
                RefusalReason::NotAwaited,
            );
        }

        $this->assertAccepted($run->interrupt, $payload);

        return new PendingSegment(
            $this->continuation(Verb::Signal, $definition, ExecutionRequest::signal($event, $payload, $run->runId, $run->executionAttempt)),
            $this->configure,
        );
    }

    /**
     * Finish a failed or dead run now, in this process.
     *
     * @return WorkflowState|null null when there was nothing to do, or when another process got to the run first
     * @throws CloudException dispatch_failed, when the queue did not take the announcement: nothing ran;
     *                        unknown_version, when this process lacks the version the run is pinned to
     * @throws Throwable what Neuron throws: the exception of a node that fails again
     */
    public function recover(): ?WorkflowState
    {
        $run = $this->snapshot();

        if (!$run instanceof WorkflowRunSnapshot || !$this->isFailedOrDead($run)) {
            return null;
        }

        $definition = $this->registry->pinned($this->name, $run->runId);
        $wake = ExecutionRequest::resume(null, $run->runId, $run->executionAttempt);
        $execution = $this->continuation(Verb::Wake, $definition, $wake)->run();

        return $this->wasTakenByAnother($execution->settlement) ? null : $execution->state();
    }

    /**
     * The run holding the workflow ID, as the store shows it now.
     */
    protected function snapshot(): ?WorkflowRunSnapshot
    {
        return $this->builder->build($this->registry->current($this->name), $this->workflowId, null)->inspect();
    }

    /**
     * Stricter than Neuron's own submitInputs(), which stages an answer for a
     * run that is running or failed and decides when it executes: a queued
     * answer must be known to be awaited before it is queued.
     *
     * @throws InputTranslationException
     */
    protected function suspendedRun(): WorkflowRunSnapshot
    {
        $run = $this->snapshot();

        if (!$run instanceof WorkflowRunSnapshot) {
            throw new InputTranslationException('There is no persisted run to continue.');
        }

        if ($run->status !== WorkflowStatus::Suspended || !$run->interrupt instanceof InterruptRequest) {
            throw new InputTranslationException("The run is '{$run->status->value}', not suspended on a wait: there is nothing to answer.");
        }

        return $run;
    }

    /**
     * Neuron applies the rule of the wait only when it admits the answer, which for
     * a dispatched one is in a worker: it is applied here, before anything is queued.
     *
     * @param array<array-key, mixed> $payload
     * @throws WorkflowException when the payload is not JSON-compatible
     * @throws WorkflowRefusedException not_awaited, when the wait rejects the payload
     */
    protected function assertAccepted(InterruptRequest $wait, array $payload): void
    {
        $input = ResumeInput::event($wait, $payload);

        try {
            $wait->validate($input);
        } catch (WorkflowException $rejected) {
            throw new WorkflowRefusedException($rejected->getMessage(), RefusalReason::NotAwaited, $rejected);
        }
    }

    /**
     * A segment of the run a snapshot showed, on the version its run ID pins.
     *
     * @param Definition $definition resolved before the answer is looked at: a process that lacks the version cannot judge it
     * @param ExecutionRequest $request fenced with the run ID and the attempt of the snapshot, which are never read again
     */
    protected function continuation(Verb $verb, Definition $definition, ExecutionRequest $request): LocalSegment
    {
        $workflow = $this->builder->build($definition, $this->workflowId, $request->runId);

        return new LocalSegment($verb, $definition, $this->workflowId, $workflow, $request, $this->dispatcher, $this->builder);
    }

    protected function isFailedOrDead(WorkflowRunSnapshot $run): bool
    {
        return $run->status === WorkflowStatus::Failed
            || ($run->status === WorkflowStatus::Running && $run->leaseExpiresAt !== null && $run->leaseExpiresAt <= time());
    }

    /**
     * Whether Neuron refused the wake because another process got to the run
     * first. A refusal that escaped from a node settles as a failure, with no
     * code: it is thrown like any exception of a node.
     */
    protected function wasTakenByAnother(Settlement $settlement): bool
    {
        return in_array(
            $settlement->code,
            [RefusalReason::StaleRun->value, RefusalReason::StaleAttempt->value, RefusalReason::Executing->value],
            true,
        );
    }
}
