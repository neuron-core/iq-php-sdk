<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Verb;
use Throwable;

use function time;

/**
 * The body of a queue job: it executes one segment and tells the platform,
 * or posts one of the SDK's own notices.
 *
 * @internal
 */
final class Runner
{
    public function __construct(
        protected Registry $registry,
        protected WorkflowBuilder $builder,
        protected Ledger $ledger,
        protected PlatformClient $platform,
        protected DispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @throws CloudException when the message was not dealt with: the reason tells the worker what to do
     */
    public function invoke(Invocation $invocation): Report
    {
        return match ($invocation->verb) {
            Verb::Announce => $this->postAnnouncement($invocation),
            Verb::Observe => $this->observe($invocation),
            default => $this->runSegment($invocation),
        };
    }

    /**
     * @throws CloudException
     */
    protected function runSegment(Invocation $invocation): Report
    {
        $this->assertFenced($invocation);

        $settlement = $this->settle($invocation);
        $report = $this->reportWithSnapshot($invocation, $settlement);
        $this->deliver($invocation, $settlement, $report);

        $this->assertDealtWith($invocation, $settlement);

        return $report;
    }

    /**
     * Neuron reads a missing attempt as "no fence": every copy of the message would then execute.
     *
     * @throws CloudException invalid_request
     */
    protected function assertFenced(Invocation $invocation): void
    {
        if ($invocation->verb !== Verb::Start && $invocation->attempt === null) {
            throw new CloudException(
                "The {$invocation->verb->value} '{$invocation->invocationId}' carries no attempt: nothing would fence its copies.",
                Code::InvalidRequest,
            );
        }
    }

    protected function settle(Invocation $invocation): Settlement
    {
        $starting = $invocation->verb === Verb::Start;

        try {
            $definition = $this->registry->pinned($invocation->definition, $invocation->runId);
        } catch (CloudException $unknown) {
            // A worker still running old code during a deploy is the usual cause.
            return Settlement::aborted($unknown->reason);
        }

        if ($starting && time() >= $this->useBy($invocation)) {
            return Settlement::rejected(Code::Expired);
        }

        try {
            $workflow = $this->builder->build($definition, $invocation->workflowId, $invocation->runId);
        } catch (Throwable $e) {
            return Settlement::aborted(Code::NotExecuted, $e);
        }

        try {
            $request = $this->request($invocation, $workflow);
        } catch (CloudException) {
            // Only a start can fail here: its event broke a rule, or cannot be stored with a run.
            return Settlement::rejected(Code::InvalidStart);
        } catch (Throwable $e) {
            // A hook of the workflow threw while the start event or the graph was built, or Neuron cannot hold
            // the input of an answer or a signal: nothing ran, as when the factory throws.
            return Settlement::aborted(Code::NotExecuted, $e);
        }

        $unannounced = $this->announce($invocation, $definition);
        if ($unannounced instanceof Throwable) {
            return Settlement::aborted(Code::AnnounceFailed, $unannounced);
        }

        return $starting
            ? $this->claimAndExecute($invocation, $workflow, $request)
            : $this->execute($invocation, $workflow, $request);
    }

    /**
     * Every queued start carries a use-by time: one without it counts as long past.
     */
    protected function useBy(Invocation $start): int
    {
        return $start->expiresAt ?? 0;
    }

    /**
     * The fence of a wake, an answer or a signal is the one the invocation
     * carries: it is never read again at job time.
     *
     * @throws CloudException invalid_start, when the event of a start cannot ignite a run
     * @throws Throwable what a hook of the workflow threw, or what Neuron threw for an input it cannot hold
     */
    protected function request(Invocation $invocation, Workflow $workflow): ExecutionRequest
    {
        return match ($invocation->verb) {
            Verb::Start => $this->startRequest(StartEvents::resolve($workflow, $invocation->event), $invocation->runId),
            Verb::Answer => ExecutionRequest::resume($invocation->payload ?? [], $invocation->runId, $invocation->attempt),
            Verb::Signal => ExecutionRequest::signal(
                (string) $invocation->signal,
                $invocation->payload ?? [],
                $invocation->runId,
                $invocation->attempt,
            ),
            // A wake has no input: Neuron decides whether a timer is due or a failed or dead run must be finished.
            default => ExecutionRequest::resume(null, $invocation->runId, $invocation->attempt),
        };
    }

    /**
     * @throws CloudException invalid_start, when Neuron cannot store the event or the run ID with a run
     */
    protected function startRequest(Event $event, string $runId): ExecutionRequest
    {
        try {
            // recoverFailed stays false: a start only ever creates a run, so a repeated start can never become a retry.
            return ExecutionRequest::start($event, $runId, recoverFailed: false);
        } catch (Throwable $e) {
            throw new CloudException('The start cannot be stored with a run: ' . $e->getMessage(), Code::InvalidStart, $e);
        }
    }

    /**
     * A notice, not a question: nothing depends on the platform's answer. A job
     * posts it once and, if that fails for any reason, leaves it to the queue.
     *
     * @return Throwable|null why the segment must not ignite: what the queue threw; null when it may
     */
    protected function announce(Invocation $invocation, Definition $definition): ?Throwable
    {
        $announcement = Notices::announcement($invocation, $definition->leaseTimeout);

        try {
            $this->platform->announce($announcement);

            return null;
        } catch (CloudException) {
            // Final or not, the answer changes nothing here: the announcement goes to the queue.
        }

        try {
            $this->dispatcher->dispatch(Notices::announce($invocation, $announcement));

            return null;
        } catch (Throwable $undispatched) {
            // The platform already knows a segment it ordered. It would never find one of the application's own.
            return $invocation->origin === Origin::PLATFORM ? null : $undispatched;
        }
    }

    /**
     * The claim is never undone, whatever happens next: nothing can then run a start twice.
     */
    protected function claimAndExecute(Invocation $start, Workflow $workflow, ExecutionRequest $request): Settlement
    {
        try {
            $claim = $this->ledger->claim($start->workflowId, $start->runId, $this->useBy($start));
        } catch (Throwable $e) {
            return Settlement::aborted(Code::Unavailable, $e);
        }

        return match ($claim) {
            Claim::Expired => Settlement::rejected(Code::Expired),
            Claim::AlreadyThere => Settlement::rejected(Code::AlreadyClaimed)->withClaim(),
            Claim::Written => $this->execute($start, $workflow, $request)->withClaim(),
        };
    }

    protected function execute(Invocation $invocation, Workflow $workflow, ExecutionRequest $request): Settlement
    {
        // Neuron reports every segment it opens: no event means this call opened none. The first event is kept,
        // because a listener of the application that throws on a later one stops it from reaching this listener.
        $opened = null;
        $workflow->subscribe(ObservabilityEvent::class, static function (ObservabilityEvent $event) use (&$opened): void {
            $opened ??= $event->execution;
        });

        try {
            if ($invocation->verb === Verb::Wake && $this->runsWithoutLease($workflow, $invocation)) {
                return Settlement::refused(RefusalReason::Executing);
            }

            return Settlement::ran($this->run($workflow, $request, $opened)->getStatus(), $opened?->executionAttempt);
        } catch (WorkflowRefusedException $refused) {
            // Inside an open segment Neuron refuses only the write of a segment that lost its run:
            // any other refusal escaped from a node, and Neuron has failed the run for it.
            return $opened instanceof ExecutionContext && $refused->reason !== RefusalReason::StaleAttempt
                ? Settlement::failed($refused, $opened->executionAttempt)
                : Settlement::refused($refused->reason, $opened?->executionAttempt);
        } catch (Throwable $error) {
            return $opened instanceof ExecutionContext
                ? Settlement::failed($error, $opened->executionAttempt)
                : Settlement::aborted(Code::NotExecuted, $error);
        }
    }

    /**
     * A run that is running with no lease was started outside the SDK, and a
     * wake would take it over while its process may be alive.
     */
    protected function runsWithoutLease(Workflow $workflow, Invocation $wake): bool
    {
        $snapshot = $workflow->inspect();

        // A wake whose fence is no longer current is left to Neuron, which refuses it as stale.
        return $snapshot instanceof WorkflowRunSnapshot
            && $snapshot->runId === $wake->runId
            && $snapshot->executionAttempt === $wake->attempt
            && $snapshot->status === WorkflowStatus::Running
            && $snapshot->leaseExpiresAt === null;
    }

    /**
     * @param ExecutionContext|null $opened the segment this call opened, set by the listener while Neuron runs
     * @throws WorkflowRefusedException
     * @throws Throwable
     */
    protected function run(Workflow $workflow, ExecutionRequest $request, ?ExecutionContext &$opened): WorkflowState
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

    /**
     * The snapshot is read after the outcome was committed, in its own try: a
     * store that cannot be read must not hide how the call ended.
     */
    protected function reportWithSnapshot(Invocation $invocation, Settlement $settlement): Report
    {
        try {
            $run = $this->readRun($invocation);
            $snapshotRead = true;
        } catch (Throwable) {
            $run = null;
            $snapshotRead = false;
        }

        return $this->buildReport($invocation, $settlement->facts($invocation), $run, $settlement->claimed, $snapshotRead);
    }

    /**
     * The run holding the workflow ID now, read through a newly built workflow.
     *
     * @return array<string, mixed>|null
     * @throws Throwable when the store cannot be read
     */
    protected function readRun(Invocation $invocation): ?array
    {
        $definition = $this->registry->reader($invocation->definition, $invocation->runId);
        $snapshot = $this->builder->build($definition, $invocation->workflowId, null)->inspect();

        return $snapshot instanceof WorkflowRunSnapshot ? RunProjection::of($snapshot) : null;
    }

    /**
     * A job posts its report once. An observe follows only when the platform
     * did not record it, whatever it answered: after a recorded report whose
     * snapshot could not be read, the platform asks by itself.
     */
    protected function deliver(Invocation $invocation, Settlement $settlement, Report $report): void
    {
        try {
            $this->platform->report($report);

            return;
        } catch (CloudException) {
            // Final or not, the answer changes nothing here: the outcome goes to the queue.
        }

        try {
            $this->dispatcher->dispatch(Notices::observe($invocation, $settlement->facts($invocation)));
        } catch (Throwable) {
            // The job still ends normally: the platform learns the state when it asks.
        }
    }

    /**
     * The platform sends its own aborted invocation again; nobody does that for
     * one the application issued, so its worker is told. A start that was
     * already claimed is the exception: it cannot run again.
     *
     * @throws CloudException
     */
    protected function assertDealtWith(Invocation $invocation, Settlement $settlement): void
    {
        $mayRunLater = $invocation->origin === Origin::APPLICATION
            && $settlement->outcome === Outcome::Aborted
            && $settlement->claimed !== true;

        if ($mayRunLater) {
            throw new CloudException(
                "The {$invocation->verb->value} of '{$invocation->definition}' for workflow ID '{$invocation->workflowId}' "
                . "(run ID '{$invocation->runId}') was aborted before anything ran ({$settlement->code}): "
                . 'give it to invoke() again later.',
                Code::from((string) $settlement->code),
                $settlement->cause,
            );
        }
    }

    /**
     * @throws CloudException report_undelivered, or the reason the platform refused the announcement for good
     */
    protected function postAnnouncement(Invocation $invocation): Report
    {
        $announcement = $invocation->body ?? [];
        $this->platform->announce($announcement);

        return $this->buildReport($invocation, $announcement);
    }

    /**
     * A worker reads the store as it is now and posts one report: with the
     * outcome the observe carries, or "observed" when it carries none. A store
     * that cannot be read does not hold the report back: the report says so,
     * and the platform asks by itself.
     *
     * @throws CloudException report_undelivered, or the reason the platform refused the report for good
     */
    protected function observe(Invocation $invocation): Report
    {
        $facts = $invocation->body ?? [];
        $ofStart = $facts['verb'] === Verb::Start->value;

        try {
            $run = $this->readRun($invocation);
            $claimed = $ofStart ? $this->ledger->has($invocation->workflowId, $invocation->runId) : null;
            $snapshotRead = true;
        } catch (Throwable) {
            $run = null;
            $claimed = null;
            $snapshotRead = false;
        }

        $report = $this->buildReport($invocation, $facts, $run, $claimed, $snapshotRead);
        $this->platform->report($report);

        return $report;
    }

    /**
     * @param Invocation $about the segment, or a notice that speaks for it
     * @param array<string, mixed> $facts the segment's invocation ID and verb, and how it ended when that is known
     * @param array<string, mixed>|null $run
     */
    protected function buildReport(
        Invocation $about,
        array $facts,
        ?array $run = null,
        ?bool $claimed = null,
        bool $snapshotRead = false,
    ): Report {
        return new Report(
            $facts['invocationId'],
            Verb::from($facts['verb']),
            $about->origin,
            $about->definition,
            $about->workflowId,
            $about->runId,
            Outcome::tryFrom($facts['outcome'] ?? '') ?? Outcome::Observed,
            $facts['code'] ?? null,
            $facts['errorClass'] ?? null,
            $facts['executedAttempt'] ?? null,
            $run,
            $claimed,
            $snapshotRead,
            time(),
        );
    }
}
