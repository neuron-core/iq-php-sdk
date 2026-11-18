<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\InPlace\Stage;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 2 of section 9.6 of the design: Neuron answers "conflict" to a start
 * that found the workflow ID taken and, on reading it, free again. It retries
 * such a start itself only when no run ID is reserved, and the SDK always
 * reserves one: so the SDK tries once more, and only when no segment opened.
 */
class IgnitionConflictTest extends InPlaceTestCase
{
    protected const RIVAL_CONTROL = 'the control record of a run that ends at once';

    /** How many times Neuron asked the store to create a run for the workflow ID. */
    protected int $ignitionsAsked = 0;

    #[DataProvider('backends')]
    public function test_a_start_whose_ignition_meets_a_conflict_is_tried_once_more_and_returns_its_state(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(WorkflowStatus::Completed, $played->returned->getStatus());
        self::assertSame(2, $this->ignitionsAsked);
    }

    #[DataProvider('backends')]
    public function test_a_start_tried_again_after_a_conflict_is_announced_once_and_observed_once(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $played = $stage->play();

        self::assertSame([Verb::Announce, Verb::Observe], $played->verbsQueued());
        self::assertSame(
            [Outcome::Completed->value, 1],
            [$played->observe()->body['outcome'] ?? null, $played->observe()->body['executedAttempt'] ?? null],
        );
    }

    #[DataProvider('backends')]
    public function test_a_start_tried_again_after_a_conflict_runs_each_node_body_once(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $played = $stage->play();

        self::assertSame(2, $played->bodiesRun);
        self::assertSame([1], $played->attemptsOpened);
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_ignition_meets_a_conflict_twice_throws_the_refusal_of_neuron(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowRefusedException::class, $played->thrown);
        self::assertSame(RefusalReason::Conflict, $played->thrown->reason);
    }

    #[DataProvider('backends')]
    public function test_a_conflict_is_tried_again_once_and_no_more(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);

        $stage->play();

        self::assertSame(2, $this->ignitionsAsked);
    }

    #[DataProvider('backends')]
    public function test_a_start_refused_for_a_conflict_is_observed_as_refused_and_runs_no_node_body(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);

        $played = $stage->play();

        self::assertSame(
            [Outcome::Refused->value, RefusalReason::Conflict->value, null],
            [
                $played->observe()->body['outcome'] ?? null,
                $played->observe()->body['code'] ?? null,
                $played->observe()->body['executedAttempt'] ?? null,
            ],
        );
        self::assertSame(0, $played->bodiesRun);
    }

    #[DataProvider('kinds')]
    public function test_a_conflict_that_a_node_throws_is_not_tried_again(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $conflict = new WorkflowRefusedException('A run this node started changed under it.', RefusalReason::Conflict);
        $stage->midBody->whenReached(static fn () => throw $conflict);

        $played = $stage->play();

        self::assertSame($conflict, $played->thrown);
        self::assertSame(1, $played->bodiesRun);
        self::assertCount(1, $played->attemptsOpened);
    }

    #[DataProvider('kinds')]
    public function test_a_conflict_that_a_node_throws_is_observed_as_a_failure_of_the_segment(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->midBody->whenReached(static fn () => throw new WorkflowRefusedException('A run this node started changed under it.', RefusalReason::Conflict));

        $played = $stage->play();

        self::assertSame(
            [Outcome::Failed->value, null, WorkflowRefusedException::class, $kind->continuesARun() ? 2 : 1],
            [
                $played->observe()->body['outcome'] ?? null,
                $played->observe()->body['code'] ?? null,
                $played->observe()->body['errorClass'] ?? null,
                $played->observe()->body['executedAttempt'] ?? null,
            ],
        );
    }

    /**
     * A stage for a start, on a store in which a rival run takes the workflow
     * ID just before each of the next $ignitions and is gone when Neuron
     * reads who holds it.
     */
    protected function stageWhereARivalRunEndsDuringIgnitions(string $backend, int $ignitions): Stage
    {
        $stage = $this->stage($backend, Kind::Start);
        $store = ($stage->bench->persistence)();

        $stage->interceptStore(function (string $operation, string $partition) use ($store, &$ignitions): void {
            if ($partition !== Stage::WORKFLOW_ID) {
                return;
            }

            if ($operation === 'initializeIfAbsent') {
                $this->ignitionsAsked++;

                if ($ignitions-- > 0) {
                    $store->initializeIfAbsent(Stage::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
                }
            }

            if ($operation === 'get') {
                $store->deleteIfUnchanged(Stage::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
            }
        });

        return $stage;
    }
}
