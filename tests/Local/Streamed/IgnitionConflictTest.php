<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 2 of section 9.6 of the design, for a stream: Neuron answers
 * "conflict" to a start that found the workflow ID taken and, on reading it,
 * free again. The SDK tries once more, at the same first iteration and only
 * when no segment opened: Neuron yields nothing before it opens one, so the
 * consumer has received nothing of the first try.
 */
class IgnitionConflictTest extends StreamedTestCase
{
    protected const RIVAL_CONTROL = 'the control record of a run that ends at once';

    /** How many times Neuron asked the store to create a run for the workflow ID. */
    protected int $ignitionsAsked = 0;

    #[DataProvider('backends')]
    public function test_a_stream_whose_ignition_meets_a_conflict_is_tried_once_more_and_gives_every_word_once(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $taken = $stage->take($stage->stream());

        self::assertSame([...TaleWorkflow::OPENING, ...TaleWorkflow::CLOSING], $taken->words());
        self::assertSame(2, $this->ignitionsAsked);
    }

    #[DataProvider('backends')]
    public function test_a_stream_tried_again_after_a_conflict_returns_its_state(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $taken = $stage->take($stage->stream());

        self::assertSame(WorkflowStatus::Completed, $taken->returned?->getStatus());
    }

    #[DataProvider('backends')]
    public function test_the_second_try_happens_at_the_first_step_of_the_consumer(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);
        $events = $stage->stream();
        $ignitionsAskedBefore = $this->ignitionsAsked;

        $events->valid();

        self::assertSame([0, 2], [$ignitionsAskedBefore, $this->ignitionsAsked]);
        self::assertSame('running', $stage->stored()['status'] ?? null);
    }

    #[DataProvider('backends')]
    public function test_a_stream_tried_again_after_a_conflict_is_announced_once_and_observed_once(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $stage->take($stage->stream());

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(
            [Outcome::Completed->value, 1],
            [$stage->observe()->body['outcome'] ?? null, $stage->observe()->body['executedAttempt'] ?? null],
        );
    }

    #[DataProvider('backends')]
    public function test_a_stream_tried_again_after_a_conflict_runs_each_node_body_once(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 1);

        $stage->take($stage->stream());

        self::assertSame(2, $stage->bodiesRun());
        self::assertSame([1], $stage->attemptsOpened());
    }

    #[DataProvider('backends')]
    public function test_a_stream_whose_ignition_meets_a_conflict_twice_throws_the_refusal_of_neuron_at_its_first_step(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);
        $events = $stage->stream();

        try {
            $events->valid();
            self::fail('The second conflict was not thrown.');
        } catch (WorkflowRefusedException $refusal) {
            self::assertSame(RefusalReason::Conflict, $refusal->reason);
        }
    }

    #[DataProvider('backends')]
    public function test_a_conflict_is_tried_again_once_and_no_more(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);

        $stage->take($stage->stream());

        self::assertSame(2, $this->ignitionsAsked);
    }

    #[DataProvider('backends')]
    public function test_a_stream_refused_for_a_conflict_is_observed_as_refused_and_runs_no_node_body(string $backend): void
    {
        $stage = $this->stageWhereARivalRunEndsDuringIgnitions($backend, 3);

        $stage->take($stage->stream());

        self::assertSame(
            [Outcome::Refused->value, RefusalReason::Conflict->value, null],
            [
                $stage->observe()->body['outcome'] ?? null,
                $stage->observe()->body['code'] ?? null,
                $stage->observe()->body['executedAttempt'] ?? null,
            ],
        );
        self::assertSame(0, $stage->bodiesRun());
    }

    #[DataProvider('kinds')]
    public function test_a_conflict_that_a_node_throws_in_the_middle_of_its_stream_is_not_tried_again(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $conflict = new WorkflowRefusedException('A run this node started changed under it.', RefusalReason::Conflict);
        $stage->midStream->whenReached(static fn () => throw $conflict);

        $taken = $stage->take($stage->stream());

        self::assertSame($conflict, $taken->thrown);
        self::assertCount(1, $taken->frames, 'The words the consumer already holds must not be streamed a second time.');
        self::assertSame([1, [$kind->attempt()]], [$stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('kinds')]
    public function test_a_conflict_that_a_node_throws_is_observed_as_a_failure_of_the_stream(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->midStream->whenReached(static fn () => throw new WorkflowRefusedException('A run this node started changed under it.', RefusalReason::Conflict));

        $stage->take($stage->stream());

        self::assertSame(
            [Outcome::Failed->value, null, WorkflowRefusedException::class, $kind->attempt()],
            [
                $stage->observe()->body['outcome'] ?? null,
                $stage->observe()->body['code'] ?? null,
                $stage->observe()->body['errorClass'] ?? null,
                $stage->observe()->body['executedAttempt'] ?? null,
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
