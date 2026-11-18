<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Concurrency;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Concurrency\PrepareNode;
use NeuronCore\IQ\Tests\Stub\Concurrency\StallNode;
use NeuronCore\IQ\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\IQ\Tests\Support\Concurrency\ProcessTestCase;

/**
 * Row 8 of "When things go wrong": a queue worker that stays inside a node
 * body for longer than its lease while it is alive, so that the wake of
 * another worker takes its run over.
 */
class SlowWorkerTest extends ProcessTestCase
{
    public function test_a_slow_worker_taken_for_dead_has_its_next_write_refused_as_stale_attempt_and_the_node_runs_in_both_workers(): void
    {
        $slow = $this->bench->deliver($this->start(ProcessBench::STALLING), lease: 1);
        $this->bench->awaitExecutions(StallNode::class, 1);
        $overdue = $this->bench->awaitExpiredLease(self::WORKFLOW_ID);

        $taker = $this->bench->deliver($this->wake(ProcessBench::STALLING, $overdue->runId, $overdue->executionAttempt));
        $this->bench->awaitExecutions(StallNode::class, 2);
        $this->bench->gate->open();

        $lost = $slow->report();
        self::assertSame(Outcome::Refused->value, $lost['outcome']);
        self::assertSame(RefusalReason::StaleAttempt->value, $lost['code']);
        self::assertSame(1, $lost['executedAttempt']);
        self::assertSame(Outcome::Completed->value, $taker->report()['outcome']);
        self::assertSame(1, $this->bench->executions->count(PrepareNode::class), 'A committed step is not executed again.');
        self::assertSame(2, $this->bench->executions->count(StallNode::class));
    }
}
