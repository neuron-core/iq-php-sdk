<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Concurrency;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\Concurrency\PrepareNode;
use NeuronCore\IQ\Tests\Stub\Concurrency\StallNode;
use NeuronCore\IQ\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\IQ\Tests\Support\Concurrency\ProcessTestCase;

use function array_fill;
use function array_map;
use function base64_encode;
use function range;
use function serialize;

/**
 * Starts that reach several queue workers at the same instant, each worker a
 * process of its own.
 */
class RacingStartsTest extends ProcessTestCase
{
    protected const COPIES = 48;

    protected const ROUNDS = 3;

    public function test_of_many_copies_of_a_platform_start_delivered_at_once_one_executes_and_the_others_are_rejected_as_already_claimed(): void
    {
        // Every round is a new run of the same workflow ID, so the copies also race on a ledger record that exists.
        foreach (range(1, self::ROUNDS) as $round) {
            $start = $this->start(ProcessBench::COMPLETING, "1-round-{$round}");

            $this->bench->deliverAtOnce(...array_fill(0, self::COPIES, $start));

            self::assertSame(
                [Outcome::Completed->value => 1, 'rejected: ' . Code::AlreadyClaimed->value => self::COPIES - 1],
                $this->bench->platform->outcomesOf($start->runId),
            );
            self::assertSame($round, $this->bench->executions->count(CompleteNode::class), 'The node body runs once per start.');
        }
    }

    public function test_of_many_copies_of_a_dispatched_start_delivered_at_once_one_executes_and_the_others_are_rejected_as_already_claimed(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $start = $this->start(
                ProcessBench::COMPLETING,
                "1-round-{$round}",
                origin: Origin::APPLICATION,
                event: base64_encode(serialize(new StartEvent())),
            );

            $this->bench->deliverAtOnce(...array_fill(0, self::COPIES, $start));

            self::assertSame(
                [Outcome::Completed->value => 1, 'rejected: ' . Code::AlreadyClaimed->value => self::COPIES - 1],
                $this->bench->platform->outcomesOf($start->runId),
            );
            self::assertSame($round, $this->bench->executions->count(CompleteNode::class), 'The node body runs once per start.');
        }
    }

    public function test_a_second_copy_of_a_start_delivered_while_the_first_is_inside_a_node_is_rejected_as_already_claimed_and_executes_nothing(): void
    {
        $start = $this->start(ProcessBench::STALLING);
        $first = $this->bench->deliver($start);
        $this->bench->awaitExecutions(StallNode::class, 1);

        $copy = $this->bench->deliver($start)->report();

        self::assertSame(Outcome::Rejected->value, $copy['outcome']);
        self::assertSame(Code::AlreadyClaimed->value, $copy['code']);
        self::assertTrue($copy['claimed']);
        self::assertSame(WorkflowStatus::Running->value, $copy['run']['status'], 'The rejection carries the run as the first copy holds it.');

        $this->bench->gate->open();
        self::assertSame(Outcome::Completed->value, $first->report()['outcome']);
        self::assertSame(1, $this->bench->executions->count(PrepareNode::class));
        self::assertSame(1, $this->bench->executions->count(StallNode::class));
    }

    public function test_of_two_starts_for_one_workflow_id_delivered_at_once_one_runs_and_the_other_is_refused_as_run_in_flight(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $first = $this->start(ProcessBench::WAITING, "1-first-{$round}", "order:{$round}");
            $second = $this->start(ProcessBench::WAITING, "1-second-{$round}", "order:{$round}");

            $this->bench->deliverAtOnce($first, $second);

            self::assertSame(
                ['refused: ' . RefusalReason::RunInFlight->value => 1, Outcome::Suspended->value => 1],
                $this->bench->platform->outcomesOf($first->runId, $second->runId),
            );
            self::assertSame($round, $this->bench->executions->count(AwaitPaymentNode::class), 'Only the start that won runs.');
            self::assertTrue($this->bench->claimed($first->workflowId, $first->runId));
            self::assertTrue($this->bench->claimed($second->workflowId, $second->runId), 'The start that lost can never run later.');
        }
    }

    public function test_the_starts_for_one_workflow_id_delivered_at_once_are_all_entered_in_the_ledger(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            // Five at most: a claim gives up after five lost writes, and each start can lose one to each of the others.
            $starts = array_map(
                fn (int $rival): Invocation => $this->start(ProcessBench::WAITING, "1-rival-{$rival}-{$round}", "order:{$round}"),
                range(1, 5),
            );

            $this->bench->deliverAtOnce(...$starts);

            foreach ($starts as $start) {
                self::assertTrue(
                    $this->bench->claimed($start->workflowId, $start->runId),
                    "The claim of run ID '{$start->runId}' was lost to another start of the same workflow ID.",
                );
            }
        }
    }
}
