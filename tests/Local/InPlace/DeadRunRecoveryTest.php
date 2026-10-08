<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Played;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 9.7 of the design: recover() also executes its wake, in the
 * calling process, for a run left running under a lease that has passed. The
 * dead worker is a start of this process that outlives its lease inside its
 * first node, and recover() is called from there: a workflow keeps nothing of
 * its segments, so the call meets the persisted run as another process would.
 */
class DeadRunRecoveryTest extends InPlaceTestCase
{
    /** @var array<string, Played> */
    protected static array $recoveries = [];

    public static function tearDownAfterClass(): void
    {
        self::$recoveries = [];
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_a_run_whose_lease_has_passed_and_returns_its_state(string $backend): void
    {
        $recovered = $this->recoveryOfADeadRun($backend)->returned;

        self::assertInstanceOf(WorkflowState::class, $recovered);
        self::assertSame([WorkflowStatus::Completed, 2], [$recovered->getStatus(), $recovered->getExecutionAttempt()]);
    }

    #[DataProvider('backends')]
    public function test_the_wake_of_a_dead_run_is_announced_with_the_attempt_the_dead_worker_held(string $backend): void
    {
        $announcement = $this->recoveryOfADeadRun($backend)->announce()->body ?? [];

        self::assertSame([Verb::Wake->value, 1, 1], [$announcement['verb'], $announcement['attempt'], $announcement['leaseSeconds']]);
    }

    #[DataProvider('backends')]
    public function test_the_wake_of_a_dead_run_is_observed_under_the_attempt_it_took_the_run_over_with(string $backend): void
    {
        $recovery = $this->recoveryOfADeadRun($backend);

        self::assertSame([Verb::Announce, Verb::Observe], $recovery->verbsQueued());
        self::assertSame(
            [Verb::Wake->value, Outcome::Completed->value, 2],
            [
                $recovery->observe()->body['verb'] ?? null,
                $recovery->observe()->body['outcome'] ?? null,
                $recovery->observe()->body['executedAttempt'] ?? null,
            ],
        );
    }

    #[DataProvider('backends')]
    public function test_recover_leaves_alone_a_run_that_is_executing_under_its_lease(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $recovery = null;
        $stage->midBody->whenReached(static function () use ($stage, &$recovery): void {
            $recovery = $stage->play($stage->thread->recover(...));
        });

        $holder = $stage->play();

        self::assertInstanceOf(Played::class, $recovery);
        self::assertSame([null, null, []], [$recovery->returned, $recovery->thrown, $recovery->queued]);
        self::assertInstanceOf(WorkflowState::class, $holder->returned);
    }

    /**
     * What recover() left behind when it met a run of a one-second lease whose worker was still in its first node
     * after that second. Waiting takes real time, so it is played once per backend for all the tests that read it.
     */
    protected function recoveryOfADeadRun(string $backend): Played
    {
        return self::$recoveries[$backend] ??= $this->recoverADeadRun($backend);
    }

    protected function recoverADeadRun(string $backend): Played
    {
        $stage = $this->stage($backend, Kind::Start, lease: 1);
        $recovery = null;
        $stage->midBody->whenReached(static function () use ($stage, &$recovery): void {
            $stage->outliveTheLease();
            $recovery = $stage->play($stage->thread->recover(...));
        });

        $stage->play();

        self::assertInstanceOf(Played::class, $recovery);

        return $recovery;
    }
}
