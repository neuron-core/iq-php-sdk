<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Concurrency;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Concurrency\PrepareNode;
use NeuronCore\Cloud\Tests\Stub\Concurrency\StallNode;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessTestCase;

use function array_column;
use function array_fill;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_sum;
use function array_values;
use function json_encode;

/**
 * Copies of one wake that reach several queue workers, each worker a process
 * of its own. The run waits for a timer that is due, then commits a step and
 * enters its second node.
 */
class RacingWakesTest extends ProcessTestCase
{
    protected const COPIES = 48;

    /**
     * How a copy that lost may end: it meets the run under its new attempt, or no run once the winner has
     * completed it. The abort is a narrow window in Neuron, which OvertakenWakeTest plays on purpose: a copy that
     * read the control record of the run and then misses its start record, because the winner completed the run
     * in between, gets a WorkflowException where a refusal was due.
     */
    protected const OUTCOMES_OF_A_COPY_THAT_LOST = [
        'refused: stale_attempt',
        'refused: stale_run',
        'aborted: not_executed',
    ];

    public function test_a_second_copy_of_a_wake_delivered_while_the_first_is_inside_a_node_is_refused_as_stale_and_executes_nothing(): void
    {
        $wake = $this->wakeOfSuspendedRun();
        $first = $this->bench->deliver($wake);
        $this->bench->awaitExecutions(StallNode::class, 1);

        $copy = $this->bench->deliver($wake)->report();

        self::assertSame(Outcome::Refused->value, $copy['outcome']);
        self::assertSame(RefusalReason::StaleAttempt->value, $copy['code']);
        self::assertNull($copy['executedAttempt']);
        self::assertSame(2, $copy['run']['attempt'], 'The refusal carries the run as the first copy holds it.');

        $this->bench->gate->open();
        self::assertSame(Outcome::Completed->value, $first->report()['outcome']);
        self::assertSame(2, $this->bench->executions->count(PrepareNode::class), 'Once to reach its timer, once to pass it.');
        self::assertSame(1, $this->bench->executions->count(StallNode::class));
    }

    public function test_of_many_copies_of_a_wake_delivered_at_once_one_executes_and_no_other_opens_a_segment(): void
    {
        $wake = $this->wakeOfSuspendedRun();
        $this->bench->gate->open();

        $reports = $this->bench->deliverAtOnce(...array_fill(0, self::COPIES, $wake));

        self::assertSame([2], array_values(array_filter(array_column($reports, 'executedAttempt'))), 'One copy opens a segment.');
        $outcomes = $this->bench->platform->outcomesOf($wake->runId);
        self::assertSame(1, $outcomes[Outcome::Completed->value] ?? 0);
        self::assertSame(
            self::COPIES - 1,
            array_sum(array_intersect_key($outcomes, array_flip(self::OUTCOMES_OF_A_COPY_THAT_LOST))),
            'Every other copy ends as a copy that lost: ' . json_encode($outcomes),
        );
        self::assertSame(2, $this->bench->executions->count(PrepareNode::class), 'Once to reach its timer, once to pass it.');
        self::assertSame(1, $this->bench->executions->count(StallNode::class));
    }

    /**
     * Start a run that suspends on a timer that is due.
     *
     * @return Invocation the wake the platform sends for that timer
     */
    protected function wakeOfSuspendedRun(): Invocation
    {
        $suspended = $this->bench->deliver($this->start(ProcessBench::STALLING_AFTER_TIMER))->report();

        return $this->wake(ProcessBench::STALLING_AFTER_TIMER, $suspended['run']['runId'], $suspended['run']['attempt']);
    }
}
