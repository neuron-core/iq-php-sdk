<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\InPlace\Encounter;
use NeuronCore\IQ\Tests\Support\InPlace\Holder;
use NeuronCore\IQ\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\InPlace\Stage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

use function time;

/**
 * Section 4 of the design: local calls throw what Neuron throws. The caller
 * of a segment run in place gets the very object a node or the engine threw:
 * nothing is wrapped, copied or rebuilt by the SDK.
 */
class ThrownByNeuronTest extends InPlaceTestCase
{
    /** @var array<string, Encounter> */
    protected static array $encounters = [];

    public static function tearDownAfterClass(): void
    {
        self::$encounters = [];
    }

    #[DataProvider('kinds')]
    public function test_the_caller_gets_the_very_exception_a_node_threw(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $failure = new NodeFailed('The node failed on a value only this test holds.');
        $stage->midBody->whenReached(static fn () => throw $failure);

        $played = $stage->play();

        self::assertSame($failure, $played->thrown);
    }

    #[DataProvider('holders')]
    public function test_a_start_that_meets_a_run_gets_the_run_in_flight_exception_the_engine_threw(string $backend, Holder $holder): void
    {
        $refusal = $this->startMeeting($backend, $holder)->start->thrown;

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(RunInFlightException::class, $refusal::class);
        self::assertSame(self::fileOf(WorkflowEngine::class), $refusal->getFile());
    }

    #[DataProvider('holders')]
    public function test_the_refusal_of_a_start_describes_the_run_that_holds_the_workflow_id(string $backend, Holder $holder): void
    {
        $encounter = $this->startMeeting($backend, $holder);
        $refusal = $encounter->start->thrown;

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(
            [
                Stage::WORKFLOW_ID,
                $encounter->heldBefore['runId'] ?? null,
                $holder->status(),
                $encounter->heldBefore['attempt'] ?? null,
                $encounter->heldBefore['leaseExpiresAt'] ?? null,
            ],
            [$refusal->workflowId, $refusal->runId, $refusal->status, $refusal->executionAttempt, $refusal->leaseExpiresAt],
        );
    }

    #[DataProvider('holders')]
    public function test_the_refusal_of_a_start_names_the_run_id_that_start_had_reserved(string $backend, Holder $holder): void
    {
        $start = $this->startMeeting($backend, $holder)->start;

        self::assertInstanceOf(RunInFlightException::class, $start->thrown);
        self::assertSame($start->announce()->runId, $start->thrown->reservedRunId);
        self::assertNotSame($start->thrown->runId, $start->thrown->reservedRunId);
    }

    #[DataProvider('backends')]
    public function test_the_refusal_of_a_start_that_meets_a_suspended_run_carries_the_wait_of_that_run(string $backend): void
    {
        $refusal = $this->startMeeting($backend, Holder::Suspended)->start->thrown;

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertInstanceOf(WaitForEventRequest::class, $refusal->interrupt);
    }

    #[DataProvider('backends')]
    public function test_the_refusal_of_a_start_that_meets_an_executing_run_shows_a_lease_that_has_not_passed(string $backend): void
    {
        $refusal = $this->startMeeting($backend, Holder::Running)->start->thrown;

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertGreaterThan(time(), $refusal->leaseExpiresAt);
    }

    #[DataProvider('backends')]
    public function test_the_refusal_of_a_start_that_meets_a_dead_run_shows_a_lease_that_has_passed(string $backend): void
    {
        $refusal = $this->startMeeting($backend, Holder::Dead)->start->thrown;

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(WorkflowStatus::Running, $refusal->status);
        self::assertLessThanOrEqual(time(), $refusal->leaseExpiresAt);
    }

    #[DataProvider('holders')]
    public function test_a_start_that_meets_a_run_executes_nothing(string $backend, Holder $holder): void
    {
        $start = $this->startMeeting($backend, $holder)->start;

        self::assertSame(0, $start->bodiesRun);
        self::assertSame([], $start->attemptsOpened);
    }

    #[DataProvider('holders')]
    public function test_a_start_that_meets_a_run_leaves_that_run_as_it_was(string $backend, Holder $holder): void
    {
        $encounter = $this->startMeeting($backend, $holder);

        self::assertNotNull($encounter->heldBefore);
        self::assertSame($encounter->heldBefore, $encounter->heldAfter);
    }

    #[DataProvider('holdersExecuting')]
    public function test_a_start_never_takes_over_a_run_that_is_executing_even_past_its_lease(string $backend, Holder $holder): void
    {
        $holding = $this->startMeeting($backend, $holder)->holder?->returned;

        self::assertInstanceOf(WorkflowState::class, $holding);
        self::assertSame(WorkflowStatus::Completed, $holding->getStatus());
        self::assertSame(1, $holding->getExecutionAttempt());
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('continuationsRefused')]
    public function test_an_answer_or_a_signal_neuron_refuses_throws_the_refusal_the_engine_threw(
        string $backend,
        Kind $kind,
        Ending $ending,
        RefusalReason $reason,
        string $class,
    ): void {
        $played = $this->stage($backend, $kind, $ending)->playOvertaken();

        self::assertInstanceOf(WorkflowRefusedException::class, $played->thrown);
        self::assertSame([$class, $reason], [$played->thrown::class, $played->thrown->reason]);
        self::assertSame(self::fileOf(WorkflowEngine::class), $played->thrown->getFile());
    }

    #[DataProvider('recoveriesOvertaken')]
    public function test_recover_returns_null_when_another_process_got_to_the_run_first(string $backend, Ending $ending): void
    {
        $played = $this->stage($backend, Kind::Recovery, $ending)->playOvertaken();

        self::assertNull($played->thrown);
        self::assertNull($played->returned);
    }

    #[DataProvider('backends')]
    public function test_a_segment_that_lost_its_run_while_executing_throws_the_refusal_of_the_write_neuron_refused(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, lease: 1);
        $stage->midBody->whenReached(static function () use ($stage): void {
            $stage->outliveTheLease();
            $stage->elsewhere()->recover();
        });

        $played = $stage->play();

        self::assertInstanceOf(WorkflowRefusedException::class, $played->thrown);
        self::assertSame(RefusalReason::StaleAttempt, $played->thrown->reason);
        self::assertSame(self::fileOf(WorkflowRunStore::class), $played->thrown->getFile());
    }

    /**
     * @return array<string, array{string, Holder}>
     */
    public static function holders(): array
    {
        return self::holdersAmong(Holder::cases());
    }

    /**
     * @return array<string, array{string, Holder}>
     */
    public static function holdersExecuting(): array
    {
        return self::holdersAmong([Holder::Running, Holder::Dead]);
    }

    /**
     * @return array<string, array{string, Kind, Ending, RefusalReason, class-string}> what Neuron answers, and the
     *         class of the exception it answers with
     */
    public static function continuationsRefused(): array
    {
        $staleAttempt = [RefusalReason::StaleAttempt, WorkflowRefusedException::class];
        $staleRun = [RefusalReason::StaleRun, StaleWorkflowRunException::class];

        return self::onBothBackends([
            'an answer another request gave first' => [Kind::Answer, Ending::Waits, ...$staleAttempt],
            'an answer for a run another request completed' => [Kind::Answer, Ending::Completes, ...$staleRun],
            'a signal another request gave first' => [Kind::Signal, Ending::Waits, ...$staleAttempt],
            'a signal for a run another request completed' => [Kind::Signal, Ending::Completes, ...$staleRun],
        ]);
    }

    /**
     * @return array<string, array{string, Ending}> how the wake that got there first ended its segment
     */
    public static function recoveriesOvertaken(): array
    {
        return self::onBothBackends([
            'a wake that reached a wait' => [Ending::Waits],
            'a wake that completed the run' => [Ending::Completes],
            'a wake whose node failed again' => [Ending::Fails],
        ]);
    }

    /**
     * @param Holder[] $holders
     * @return array<string, array{string, Holder}>
     */
    protected static function holdersAmong(array $holders): array
    {
        $rows = [];
        foreach ($holders as $holder) {
            $rows["a start that meets {$holder->label()}"] = [$holder];
        }

        return self::onBothBackends($rows);
    }

    /**
     * A start run in place while a run holds the workflow ID. A dead holder
     * costs a second of real time, so each encounter is played once for all
     * the tests that read it.
     */
    protected function startMeeting(string $backend, Holder $holder): Encounter
    {
        return self::$encounters["{$holder->name} on {$backend}"] ??= $this->meet($backend, $holder);
    }

    /**
     * A holder at rest was started by another process. A holder that is
     * executing is a start of this process, still inside its first node when
     * the second start arrives: a workflow keeps nothing of its segments, so
     * the second meets the persisted run as another process would.
     */
    protected function meet(string $backend, Holder $holder): Encounter
    {
        $stage = $this->stage($backend, Kind::Start, $holder->ending(), $holder->lease());

        if (!$holder->isExecuting()) {
            $stage->elsewhere()->start()->dispatch();

            return self::encounter($stage);
        }

        $encounter = null;
        $stage->midBody->whenReached(static function () use ($stage, $holder, &$encounter): void {
            if ($holder === Holder::Dead) {
                $stage->outliveTheLease();
            }

            $encounter = self::encounter($stage);
        });
        $holding = $stage->play();

        self::assertInstanceOf(Encounter::class, $encounter);

        return new Encounter($encounter->start, $encounter->heldBefore, $encounter->heldAfter, $holding);
    }

    protected static function encounter(Stage $stage): Encounter
    {
        $heldBefore = $stage->stored();
        $start = $stage->play();

        return new Encounter($start, $heldBefore, $stage->stored(), null);
    }

    /**
     * @param class-string $class
     */
    protected static function fileOf(string $class): string
    {
        return (string) (new ReflectionClass($class))->getFileName();
    }
}
