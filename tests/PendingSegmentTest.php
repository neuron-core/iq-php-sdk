<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Local\LocalTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

class PendingSegmentTest extends LocalTestCase
{
    public function test_run_executes_the_segment_in_this_process_and_returns_its_state(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $state = $this->workflow('greeting')->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(self::WORKFLOW_ID, $state->getWorkflowId());
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_run_throws_the_exception_of_a_node_as_it_is(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $start = $this->workflow('flaky')->start();

        $this->expectExceptionObject(new NodeFailed('The flaky node failed on purpose.'));

        $start->run();
    }

    public function test_run_throws_the_refusal_of_neuron_as_it_is(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $holder = $this->workflow('order')->start()->run();
        $second = $this->workflow('order')->start();

        try {
            $second->run();
            self::fail('A start replaced the run that holds the workflow ID.');
        } catch (RunInFlightException $refusal) {
            self::assertSame($holder->getRunId(), $refusal->runId);
        }
    }

    public function test_run_executes_the_workflow_the_configure_callback_adjusted(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $endedUnderTheCallback = false;
        $thread = $this->workflow('greeting')->configure(static function (Workflow $workflow) use (&$endedUnderTheCallback): void {
            $workflow->subscribe(WorkflowEnd::class, static function () use (&$endedUnderTheCallback): void {
                $endedUnderTheCallback = true;
            });
        });

        $thread->start()->run();

        self::assertTrue($endedUnderTheCallback);
    }

    public function test_dispatch_leaves_the_configure_callback_uncalled(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $calls = 0;
        $thread = $this->workflow('greeting')->configure(static function () use (&$calls): void {
            $calls++;
        });

        $thread->start()->dispatch();

        self::assertSame(0, $calls);
    }

    public function test_dispatch_returns_the_run_id_the_worker_then_runs(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $runId = $this->workflow('order')->start()->dispatch();

        self::assertSame(0, $bench->executions->total(), 'Nothing runs in the process that dispatches.');
        [$report] = $this->queue->runAll($bench->cloud);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame($runId, $report->run['runId']);
    }

    #[DataProvider('twoUsesOfOneSegment')]
    public function test_a_second_use_of_a_segment_is_refused_as_segment_used(string $first, string $second): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $start = $this->workflow('greeting')->start();
        $start->{$first}();

        $reason = self::reasonThrownBy(static fn (): mixed => $start->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
    }

    #[DataProvider('twoUsesOfOneSegment')]
    public function test_a_second_use_of_a_segment_queues_and_executes_nothing(string $first, string $second): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $start = $this->workflow('greeting')->start();
        $start->{$first}();
        $queued = $this->verbsQueued();
        $executions = $bench->executions->total();

        self::thrownBy(static fn (): mixed => $start->{$second}());

        self::assertSame($queued, $this->verbsQueued());
        self::assertSame($executions, $bench->executions->total());
    }

    public function test_a_segment_whose_only_use_failed_is_used_all_the_same(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->cloud->setDispatcher(new UnreachableQueue());
        $start = $this->workflow('greeting')->start();
        self::assertSame(Code::DispatchFailed, self::reasonThrownBy(static fn (): mixed => $start->run()));

        $reason = self::reasonThrownBy(static fn (): mixed => $start->run());

        self::assertSame(Code::SegmentUsed, $reason);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('twoUsesOfOneSegment')]
    public function test_a_copy_made_before_the_first_use_of_a_segment_is_used_once_the_segment_is(string $first, string $second): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $start = $this->workflow('greeting')->start();
        $copy = clone $start;
        $start->{$first}();

        $reason = self::reasonThrownBy(static fn (): mixed => $copy->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
    }

    public function test_a_used_segment_does_not_stop_a_new_one_of_the_same_workflow(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $thread = $this->workflow('greeting');
        $thread->start()->run();

        $state = $thread->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame([Verb::Announce, Verb::Observe, Verb::Announce, Verb::Observe], $this->verbsQueued());
    }

    /**
     * @return array<string, array{string, string}> the method of the first use, and of the second
     */
    public static function twoUsesOfOneSegment(): array
    {
        return [
            'run, then run' => ['run', 'run'],
            'run, then dispatch' => ['run', 'dispatch'],
            'dispatch, then run' => ['dispatch', 'run'],
            'dispatch, then dispatch' => ['dispatch', 'dispatch'],
        ];
    }

    /**
     * "greeting" completes in one segment, "order" waits for an event, and the node of "flaky" throws once.
     */
    protected function workflow(string $name): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->bench->cloud
            ->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions))
            ->register('order', static fn (): Workflow => new WaitingWorkflow($executions))
            ->register('flaky', static fn (): Workflow => new FlakyWorkflow($executions))
            ->workflow($name, self::WORKFLOW_ID);
    }
}
