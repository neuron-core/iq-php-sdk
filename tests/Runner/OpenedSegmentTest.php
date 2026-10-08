<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Step 6 of the runner: a segment opened when Neuron reported an event of it,
 * and what surfaces after that is a failure of the run, whatever a listener
 * of the application does and whatever the node let escape.
 */
class OpenedSegmentTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_node_failure_is_reported_as_failed_although_a_listener_of_the_application_throws_when_the_segment_ends(string $backend): void
    {
        $bench = $this->benchWithAuditedFlaky($backend, failures: 1);

        $report = $bench->cloud->invoke($this->start('audited'));

        self::assertSame(Outcome::Failed, $report->outcome);
        self::assertSame(NodeFailed::class, $report->errorClass);
        self::assertSame(1, $report->executedAttempt);
        self::assertSame('failed', $report->run['status']);
    }

    #[DataProvider('backends')]
    public function test_a_segment_that_completes_reports_its_attempt_although_a_listener_of_the_application_throws_when_it_ends(string $backend): void
    {
        $bench = $this->benchWithAuditedFlaky($backend, failures: 0);

        $report = $bench->cloud->invoke($this->start('audited'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $report->executedAttempt);
    }

    #[DataProvider('backends')]
    public function test_an_application_wake_whose_node_fails_is_reported_as_failed_and_not_thrown_although_a_listener_throws(string $backend): void
    {
        $bench = $this->benchWithAuditedFlaky($backend, failures: 2);
        $failed = $bench->cloud->invoke($this->start('audited'));

        $report = $bench->cloud->invoke($this->wake('audited', $failed, self::APPLICATION));

        self::assertSame(Outcome::Failed, $report->outcome);
        self::assertSame(2, $report->executedAttempt);
        self::assertSame(2, $bench->executions->count(FlakyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_refusal_that_escapes_from_a_node_is_reported_as_a_failure_of_the_run_with_its_class(string $backend): void
    {
        $bench = $this->benchWithNodeThatLetsEscape($backend, RefusalReason::RunInFlight);

        $report = $bench->cloud->invoke($this->start('escaping'));

        self::assertSame(Outcome::Failed, $report->outcome);
        self::assertNull($report->code);
        self::assertSame(WorkflowRefusedException::class, $report->errorClass);
        self::assertSame(1, $report->executedAttempt);
        self::assertSame('failed', $report->run['status']);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Failed);
    }

    #[DataProvider('backends')]
    public function test_a_conflict_that_escapes_from_the_node_of_a_start_is_a_failure_of_the_run_and_is_not_tried_again(string $backend): void
    {
        $bench = $this->benchWithNodeThatLetsEscape($backend, RefusalReason::Conflict);

        $report = $bench->cloud->invoke($this->start('escaping'));

        self::assertSame(Outcome::Failed, $report->outcome);
        self::assertSame(WorkflowRefusedException::class, $report->errorClass, 'A second try would be refused, as a run in flight.');
        self::assertSame(1, $report->executedAttempt);
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_conflict_that_escapes_from_the_node_of_a_wake_is_a_failure_of_the_run_and_is_not_tried_again(string $backend): void
    {
        $bench = $this->benchWithNodeThatLetsEscape($backend, RefusalReason::Conflict);
        $failed = $bench->cloud->invoke($this->start('escaping'));

        $report = $bench->cloud->invoke($this->wake('escaping', $failed));

        self::assertSame(Outcome::Failed, $report->outcome, 'A second try would be refused, as a stale attempt.');
        self::assertSame(WorkflowRefusedException::class, $report->errorClass);
        self::assertSame(2, $report->executedAttempt);
        self::assertSame(2, $report->run['attempt']);
        self::assertSame(2, $bench->executions->count(BusyNode::class));
    }

    /**
     * "audited" fails $failures times and then completes, under a listener of the application that throws
     * whenever a segment ends.
     */
    protected function benchWithAuditedFlaky(string $backend, int $failures): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->cloud->register('audited', static fn (): FlakyWorkflow => (new FlakyWorkflow($executions, $failures))->subscribe(
            WorkflowEnd::class,
            static function (): never {
                throw new RuntimeException('The audit log is unreachable.');
            },
        ));

        return $bench;
    }

    /**
     * At every execution, the node of "escaping" lets escape what Neuron refused another workflow it called with.
     */
    protected function benchWithNodeThatLetsEscape(string $backend, RefusalReason $reason): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->cloud->register('escaping', static fn (): BusyWorkflow => new BusyWorkflow($executions, static function () use ($reason): void {
            throw new WorkflowRefusedException('Neuron refused the workflow this node called.', $reason);
        }));

        return $bench;
    }
}
