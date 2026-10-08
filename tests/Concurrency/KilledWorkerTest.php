<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Concurrency;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Concurrency\PrepareNode;
use NeuronCore\Cloud\Tests\Stub\Concurrency\StallNode;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessTestCase;
use NeuronCore\Cloud\Verb;

use function array_column;
use function time;

use const SIGKILL;

/**
 * Rows 7 and 18 of "When things go wrong": a queue worker killed with SIGKILL
 * inside a node body, and one that dies between the claim of a start and
 * Neuron's first write.
 */
class KilledWorkerTest extends ProcessTestCase
{
    public function test_a_worker_killed_inside_a_node_reports_nothing_and_leaves_its_run_running_under_its_lease(): void
    {
        $start = $this->start(ProcessBench::STALLING);

        $this->killInsideStallNode($start, lease: 600);

        $left = $this->bench->run(self::WORKFLOW_ID);
        self::assertInstanceOf(WorkflowRunSnapshot::class, $left);
        self::assertSame($start->runId, $left->runId);
        self::assertSame(WorkflowStatus::Running, $left->status);
        self::assertSame(1, $left->executionAttempt);
        self::assertGreaterThan(time(), $left->leaseExpiresAt);
        self::assertSame([Verb::Start->value], array_column($this->bench->platform->announcements(), 'verb'));
        self::assertSame([], $this->bench->platform->reports());
    }

    public function test_a_wake_delivered_before_the_lease_of_a_killed_worker_has_passed_is_refused_as_executing_and_runs_nothing(): void
    {
        $start = $this->start(ProcessBench::STALLING);
        $this->killInsideStallNode($start, lease: 600);
        $ranBeforeTheKill = $this->bench->executions->total();
        // A wake that executed in spite of the lease would then run through instead of stalling.
        $this->bench->gate->open();

        $report = $this->bench->deliver($this->wake(ProcessBench::STALLING, $start->runId, 1))->report();

        self::assertSame(Outcome::Refused->value, $report['outcome']);
        self::assertSame(RefusalReason::Executing->value, $report['code']);
        self::assertNull($report['executedAttempt']);
        self::assertSame(WorkflowStatus::Running->value, $report['run']['status']);
        self::assertSame(1, $report['run']['attempt']);
        self::assertSame($ranBeforeTheKill, $this->bench->executions->total());
    }

    public function test_a_wake_delivered_after_the_lease_of_a_killed_worker_has_passed_finishes_the_run_and_runs_again_only_the_node_in_flight(): void
    {
        $start = $this->start(ProcessBench::STALLING);
        $this->killInsideStallNode($start, lease: 1);
        $dead = $this->bench->awaitExpiredLease(self::WORKFLOW_ID);
        $this->bench->gate->open();

        $report = $this->bench->deliver($this->wake(ProcessBench::STALLING, $dead->runId, $dead->executionAttempt))->report();

        self::assertSame(Outcome::Completed->value, $report['outcome']);
        self::assertSame(2, $report['executedAttempt']);
        self::assertNull($report['run'], 'A run that completes under the SDK is gone.');
        self::assertNull($this->bench->run(self::WORKFLOW_ID));
        self::assertSame(1, $this->bench->executions->count(PrepareNode::class), 'A committed step is not executed again.');
        self::assertSame(2, $this->bench->executions->count(StallNode::class), 'Only the node in flight runs again.');
        self::assertSame([Outcome::Completed->value => 1], $this->bench->platform->outcomesOf($start->runId));
    }

    public function test_a_start_whose_worker_dies_between_the_claim_and_the_first_write_of_neuron_never_runs(): void
    {
        $start = $this->start(ProcessBench::COMPLETING);

        $worker = $this->bench->deliver($start, diesAfterClaim: true);

        self::assertSame(SIGKILL, $worker->killedBy());
        self::assertSame(0, $this->bench->executions->total());
        self::assertNull($this->bench->run(self::WORKFLOW_ID), 'The store holds no run.');
        self::assertTrue($this->bench->claimed(self::WORKFLOW_ID, $start->runId), 'The claim is never undone.');
        self::assertSame([Verb::Start->value], array_column($this->bench->platform->announcements(), 'verb'));
        self::assertSame([], $this->bench->platform->reports());
    }

    public function test_a_second_copy_of_a_start_whose_worker_died_after_the_claim_is_rejected_as_already_claimed_and_runs_nothing(): void
    {
        $start = $this->start(ProcessBench::COMPLETING);
        self::assertSame(SIGKILL, $this->bench->deliver($start, diesAfterClaim: true)->killedBy());

        $report = $this->bench->deliver($start)->report();

        self::assertSame(Outcome::Rejected->value, $report['outcome']);
        self::assertSame(Code::AlreadyClaimed->value, $report['code']);
        self::assertTrue($report['claimed']);
        self::assertTrue($report['snapshotRead']);
        self::assertNull($report['run']);
        self::assertSame(0, $this->bench->executions->total());
        self::assertSame(
            ['rejected: ' . Code::AlreadyClaimed->value => 1],
            $this->bench->platform->outcomesOf($start->runId),
        );
    }

    /**
     * Start a run in a worker and kill that worker once it stands inside the body of the second node.
     *
     * @param int $lease the lease the run is left under, in seconds
     */
    protected function killInsideStallNode(Invocation $start, int $lease): void
    {
        $worker = $this->bench->deliver($start, $lease);
        $this->bench->awaitExecutions(StallNode::class, 1);
        $worker->kill();
    }
}
