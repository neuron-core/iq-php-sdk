<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Units\RunOverridingWorkflow;
use NeuronCore\IQ\Tests\Support\Local\LocalTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;

/**
 * A class of the application may override Workflow::run(). Whatever executes
 * a segment at once goes through that method, as a direct call to Neuron
 * does: a segment run in the calling process, recover() and a queue job.
 */
class OverriddenRunTest extends LocalTestCase
{
    public function test_a_segment_run_in_the_calling_process_goes_through_the_run_its_class_overrides(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $state = $this->workflow(failures: 0)->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $bench->executions->count(RunOverridingWorkflow::class));
    }

    public function test_recover_goes_through_the_run_the_class_overrides(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $thread = $this->workflow(failures: 1);
        try {
            $thread->start()->run();
            self::fail('The node did not throw.');
        } catch (NodeFailed) {
            $callsBeforeTheRecovery = $bench->executions->count(RunOverridingWorkflow::class);
        }

        $recovered = $thread->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertSame([1, 2], [$callsBeforeTheRecovery, $bench->executions->count(RunOverridingWorkflow::class)]);
    }

    public function test_a_queue_job_goes_through_the_run_the_class_overrides(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $this->workflow(failures: 0)->start()->dispatch();
        $callsInTheRequest = $bench->executions->count(RunOverridingWorkflow::class);

        [$report] = $this->queue->runAll($bench->iq);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([0, 1], [$callsInTheRequest, $bench->executions->count(RunOverridingWorkflow::class)]);
    }

    /**
     * "audited" counts the calls of its own run(); its node throws $failures times before it completes the run.
     */
    protected function workflow(int $failures): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->bench->iq
            ->register('audited', static fn (): Workflow => new RunOverridingWorkflow($executions, $failures))
            ->workflow('audited', self::WORKFLOW_ID);
    }
}
