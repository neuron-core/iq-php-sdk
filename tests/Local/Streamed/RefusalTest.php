<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use Closure;
use Generator;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Streamed\FirstStep;
use NeuronCore\IQ\Tests\Support\Streamed\Holder;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

use function time;

/**
 * Example (b) of section 5 of the design: Neuron admits a stream at its first
 * iteration, so that is where a refusal is thrown, and an endpoint that calls
 * valid() before it sends anything can still answer with an HTTP status.
 * What is thrown is Neuron's own refusal. The segment was announced, so it is
 * observed, and nothing of the workflow runs.
 */
class RefusalTest extends StreamedTestCase
{
    #[DataProvider('refusalsAndFirstSteps')]
    public function test_the_refusal_of_neuron_is_thrown_at_the_first_step_whatever_that_step_is(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
        RefusalReason $reason,
        FirstStep $step,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        $refusal = self::refusalThrownBy(static fn () => $step->takeOn($events));

        self::assertSame($reason, $refusal?->reason);
    }

    #[DataProvider('refusals')]
    public function test_events_returns_its_generator_and_queues_the_announcement_for_a_segment_that_neuron_will_refuse(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);

        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        self::assertSame([Verb::Announce], $stage->verbsQueued());
        self::assertNotNull(self::refusalThrownBy($events->valid(...)));
    }

    #[DataProvider('refusals')]
    public function test_the_refusal_is_the_exception_neuron_built_with_nothing_of_the_sdk_around_it(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
        RefusalReason $reason,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        $refusal = self::refusalThrownBy($events->valid(...));

        self::assertInstanceOf(WorkflowRefusedException::class, $refusal);
        self::assertSame(
            match ($reason) {
                RefusalReason::RunInFlight => RunInFlightException::class,
                RefusalReason::StaleRun => StaleWorkflowRunException::class,
                default => WorkflowRefusedException::class,
            },
            $refusal::class,
        );
        self::assertSame((new ReflectionClass(WorkflowEngine::class))->getFileName(), $refusal->getFile(), 'The refusal was not built by the engine of Neuron.');
        self::assertNull($refusal->getPrevious());
    }

    #[DataProvider('refusals')]
    public function test_a_refused_stream_runs_no_node_body_and_opens_no_segment(string $backend, Kind $kind, Ending $ending, ?Holder $holder): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);
        $bodiesRun = $stage->bodiesRun();
        $opened = $stage->attemptsOpened();

        self::refusalThrownBy($events->valid(...));

        self::assertSame([$bodiesRun, $opened], [$stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('refusals')]
    public function test_a_refused_stream_leaves_the_store_as_it_was(string $backend, Kind $kind, Ending $ending, ?Holder $holder): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);
        $held = $stage->stored();

        self::refusalThrownBy($events->valid(...));

        self::assertSame($held, $stage->stored());
    }

    #[DataProvider('refusals')]
    public function test_a_refused_stream_has_queued_one_announcement_and_one_observe_when_the_refusal_reaches_the_consumer(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        self::refusalThrownBy($events->valid(...));

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('refusals')]
    public function test_the_observe_of_a_refused_stream_carries_the_reason_and_no_executed_attempt(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
        RefusalReason $reason,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        self::refusalThrownBy($events->valid(...));

        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Refused->value,
            'code' => $reason->value,
            'errorClass' => null,
            'executedAttempt' => null,
        ], $stage->observe()->body);
    }

    #[DataProvider('refusals')]
    public function test_a_stream_that_threw_its_refusal_is_over_and_queues_nothing_more_when_it_is_released(
        string $backend,
        Kind $kind,
        Ending $ending,
        ?Holder $holder,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);
        self::refusalThrownBy($events->valid(...));

        $stillGiving = $events->valid();
        unset($events);

        self::assertFalse($stillGiving);
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('holders')]
    public function test_the_refusal_of_a_start_names_the_run_that_holds_the_workflow_id(string $backend, Holder $holder): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);
        $held = $stage->stored();

        $refusal = self::refusalThrownBy($events->valid(...));

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(
            [Stage::WORKFLOW_ID, $held['runId'] ?? null, $holder->status(), $held['attempt'] ?? null, $held['leaseExpiresAt'] ?? null],
            [$refusal->workflowId, $refusal->runId, $refusal->status, $refusal->executionAttempt, $refusal->leaseExpiresAt],
        );
    }

    #[DataProvider('holders')]
    public function test_the_refusal_of_a_start_names_the_run_id_the_start_had_reserved_and_announced(string $backend, Holder $holder): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $events = self::streamThatNeuronWillRefuse($stage, $holder);

        $refusal = self::refusalThrownBy($events->valid(...));

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame($stage->announce()->runId, $refusal->reservedRunId);
        self::assertNotSame($refusal->runId, $refusal->reservedRunId);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_meets_a_run_left_running_under_a_lease_that_has_passed_is_refused_all_the_same(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, lease: Holder::Dead->lease());
        $events = self::streamThatNeuronWillRefuse($stage, Holder::Dead);

        $refusal = self::refusalThrownBy($events->valid(...));

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(Holder::Dead->status(), $refusal->status);
        self::assertLessThanOrEqual(time(), $refusal->leaseExpiresAt);
    }

    #[DataProvider('backends')]
    public function test_a_start_never_replaces_a_run_whose_lease_has_passed_and_runs_no_node_body(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, lease: Holder::Dead->lease());
        $events = self::streamThatNeuronWillRefuse($stage, Holder::Dead);
        $held = $stage->stored();
        $bodiesRun = $stage->bodiesRun();

        self::refusalThrownBy($events->valid(...));

        self::assertSame([$held, $bodiesRun], [$stage->stored(), $stage->bodiesRun()]);
    }

    #[DataProvider('continuations')]
    public function test_an_answer_or_a_signal_for_a_run_that_completed_is_refused_as_a_stale_run_that_names_the_run_it_expected(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind, Ending::Completes);
        $expected = $stage->stored()['runId'] ?? null;
        $events = self::streamThatNeuronWillRefuse($stage, null);

        $refusal = self::refusalThrownBy($events->valid(...));

        self::assertInstanceOf(StaleWorkflowRunException::class, $refusal);
        self::assertSame([Stage::WORKFLOW_ID, $expected, null], [$refusal->workflowId, $refusal->expectedRunId, $refusal->actualRunId]);
    }

    /**
     * @return array<string, array{string, Kind, Ending, Holder|null, RefusalReason}> a kind of segment, how the
     *         last node ends the segment of whoever got to the workflow ID first, the run a start meets, and
     *         what Neuron answers
     */
    public static function refusals(): array
    {
        return self::onBothBackends(self::refusalRows());
    }

    /**
     * @return array<string, array{string, Kind, Ending, Holder|null, RefusalReason, FirstStep}>
     */
    public static function refusalsAndFirstSteps(): array
    {
        $rows = [];
        foreach (self::refusalRows() as $label => $row) {
            foreach (FirstStep::cases() as $step) {
                $rows["{$label}, first taken with {$step->label()}"] = [...$row, $step];
            }
        }

        return self::onBothBackends($rows);
    }

    /**
     * @return array<string, array{string, Holder}> every run a start can meet without waiting for a lease to pass
     */
    public static function holders(): array
    {
        return self::onBothBackends([
            Holder::Suspended->label() => [Holder::Suspended],
            Holder::Failed->label() => [Holder::Failed],
            Holder::Running->label() => [Holder::Running],
            Holder::Completed->label() => [Holder::Completed],
        ]);
    }

    /**
     * @return array<string, array{Kind, Ending, Holder|null, RefusalReason}>
     */
    protected static function refusalRows(): array
    {
        $rows = [];
        foreach ([Holder::Suspended, Holder::Failed, Holder::Running, Holder::Completed] as $holder) {
            $rows["a start that meets {$holder->label()}"] = [Kind::Start, Ending::Completes, $holder, RefusalReason::RunInFlight];
        }

        foreach ([Kind::Answer, Kind::Signal] as $kind) {
            $rows["{$kind->label()} another request gave first, which reached the next wait"] = [$kind, Ending::Waits, null, RefusalReason::StaleAttempt];
            $rows["{$kind->label()} another request gave first, which failed the run"] = [$kind, Ending::Fails, null, RefusalReason::StaleAttempt];
            $rows["{$kind->label()} another request gave first, which completed the run"] = [$kind, Ending::Completes, null, RefusalReason::StaleRun];
        }

        return $rows;
    }

    /**
     * The stream of the segment of the stage, with the store set so that
     * Neuron refuses it: a start finds its workflow ID held by a run; an
     * answer or a signal is given by another request between the
     * announcement and the first step.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    protected static function streamThatNeuronWillRefuse(Stage $stage, ?Holder $holder): Generator
    {
        if ($holder instanceof Holder) {
            $stage->hold($holder);

            return $stage->stream();
        }

        $events = $stage->stream();
        $stage->elsewhere()->submitInputs(Stage::ANSWER)->dispatch();

        return $events;
    }

    /**
     * @param Closure(): mixed $step
     * @return WorkflowRefusedException|null null when the step threw nothing
     */
    protected static function refusalThrownBy(Closure $step): ?WorkflowRefusedException
    {
        try {
            $step();
        } catch (WorkflowRefusedException $refusal) {
            return $refusal;
        }

        return null;
    }
}
