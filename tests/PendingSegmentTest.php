<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Local\LocalTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function iterator_to_array;

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

    public function test_events_execute_the_segment_in_this_process_and_give_what_neuron_yields_and_returns(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->narration()->start()->events();

        $words = array_map(static fn (TextChunk $chunk): string => $chunk->content, iterator_to_array($events));

        self::assertSame(NarratingWorkflow::WORDS, $words);
        self::assertSame(WorkflowStatus::Completed, $events->getReturn()->getStatus());
    }

    public function test_events_throw_the_refusal_of_a_start_that_meets_a_run_at_the_first_valid_and_not_before(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $holder = $this->workflow('order')->start()->run();
        $events = $this->workflow('order')->start()->events();

        try {
            $events->valid();
            self::fail('A start replaced the run that holds the workflow ID.');
        } catch (RunInFlightException $refusal) {
            self::assertSame($holder->getRunId(), $refusal->runId);
        }
    }

    public function test_events_throw_stale_attempt_at_the_first_valid_for_an_answer_that_lost(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $thread = $this->workflow('order');
        $thread->start()->run();
        $lost = $thread->signal(WaitingWorkflow::EVENT)->events();
        $thread->signal(WaitingWorkflow::EVENT)->run();
        $executions = $bench->executions->total();

        try {
            $lost->valid();
            self::fail('An answer that lost its run was executed.');
        } catch (WorkflowRefusedException $refusal) {
            self::assertSame(RefusalReason::StaleAttempt, $refusal->reason);
        }

        self::assertSame($executions, $bench->executions->total());
    }

    public function test_events_throw_the_exception_of_a_node_as_it_is(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $events = $this->workflow('flaky')->start()->events();

        $this->expectExceptionObject(new NodeFailed('The flaky node failed on purpose.'));

        $events->valid();
    }

    public function test_events_execute_the_workflow_the_configure_callback_adjusted(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $endedUnderTheCallback = false;
        $thread = $this->narration()->configure(static function (Workflow $workflow) use (&$endedUnderTheCallback): void {
            $workflow->subscribe(WorkflowEnd::class, static function () use (&$endedUnderTheCallback): void {
                $endedUnderTheCallback = true;
            });
        });

        iterator_to_array($thread->start()->events());

        self::assertTrue($endedUnderTheCallback);
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
            'events, then events' => ['events', 'events'],
            'events, then run' => ['events', 'run'],
            'events, then dispatch' => ['events', 'dispatch'],
            'run, then events' => ['run', 'events'],
            'dispatch, then events' => ['dispatch', 'events'],
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

    /**
     * "narration" streams three words, then completes.
     */
    protected function narration(): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->bench->cloud
            ->register('narration', static fn (): Workflow => new NarratingWorkflow($executions))
            ->workflow('narration', self::WORKFLOW_ID);
    }
}
