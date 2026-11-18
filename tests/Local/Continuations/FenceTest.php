<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Continuations;

use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\IQ\Tests\Stub\Continuations\AwaitSellerNode;
use NeuronCore\IQ\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\IQ\Tests\Stub\RacingPersistence;
use NeuronCore\IQ\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 4 of section 9.7: the run ID and the attempt are captured when an
 * answer or a signal is prepared, and never read again. A continuation that
 * is used late is therefore refused, and cannot reach a later wait.
 */
class FenceTest extends ContinuationTestCase
{
    protected const LATE = ['signer' => 'late@example.com'];

    #[DataProvider('continuationsOnBackends')]
    public function test_of_two_continuations_prepared_for_the_same_wait_the_second_one_run_is_refused_as_stale_attempt(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $first = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER);
        $second = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);

        $first->run();
        $refusal = self::refusalFrom(static fn (): WorkflowState => $second->run());

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::StaleAttempt, $refusal->reason);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_of_two_continuations_dispatched_for_the_same_wait_the_worker_refuses_the_second_as_stale_attempt(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE)->dispatch();

        [$first, $second] = $this->work();

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame([Outcome::Suspended, 2], [$first->outcome, $first->executedAttempt]);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value, null], [$second->outcome, $second->code, $second->executedAttempt]);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_of_two_continuations_racing_for_the_same_wait_the_one_that_loses_the_claim_is_refused_as_stale_attempt(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $winner = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER);
        $store = new RacingPersistence(($this->bench->persistence)(), 1, static function () use ($winner): void {
            $winner->run();
        });
        $outraced = $this->anotherProcess($store)
            ->register('contract', static fn (): Workflow => new ContractWorkflow($executions))
            ->workflow('contract', self::WORKFLOW_ID);
        $loser = $this->prepare($continuation, $outraced, ContractWorkflow::EVENT, self::LATE);

        $refusal = self::refusalFrom(static fn (): WorkflowState => $loser->run());

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::StaleAttempt, $refusal->reason);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_after_another_one_advanced_the_run_does_not_reach_the_later_wait_of_the_same_kind(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $late = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);
        $contract->submitInputs(self::BUYER)->run();

        self::thrownFrom(static fn (): WorkflowState => $late->run());

        $run = $this->storedRun();
        self::assertSame([WorkflowStatus::Suspended, 2, 2], [$run?->status, $run?->interrupt?->getId(), $run?->executionAttempt]);
        self::assertSame(0, $executions->count(AwaitSellerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_dispatched_after_another_one_advanced_the_run_does_not_reach_the_later_wait_of_the_same_kind(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $late = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);
        $contract->submitInputs(self::BUYER)->dispatch();
        $this->work();

        $late->dispatch();
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value], [$report->outcome, $report->code]);
        self::assertSame([2, 2], [$report->run['wait']['interruptId'] ?? null, $report->run['attempt'] ?? null]);
        self::assertSame(0, $executions->count(AwaitSellerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_dispatch_hands_over_the_attempt_read_when_the_continuation_was_prepared_not_the_one_the_run_has_by_then(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $late = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);
        $contract->submitInputs(self::BUYER)->dispatch();
        $this->work();
        $queued = $this->countQueued();

        $late->dispatch();

        [$handedOver] = $this->queuedAfter($queued);
        self::assertSame(2, $this->storedRun()?->executionAttempt);
        self::assertSame(1, $handedOver->attempt);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_wake_that_finds_nothing_due_between_the_preparation_and_the_use_of_a_continuation_does_not_spend_its_fence(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $prepared = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER);
        $this->thePlatformWakesTheRun('contract');

        $state = $prepared->run();

        self::assertSame(self::BUYER, $state->get('buyer'));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_prepared_for_a_run_and_run_once_that_run_is_gone_does_not_reach_the_next_run_of_the_workflow_id(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $late = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);
        $this->signAndStartTheNextContract($contract);

        $refusal = self::refusalFrom(static fn (): WorkflowState => $late->run());

        self::assertSame(StaleWorkflowRunException::class, $refusal::class);
        self::assertSame(RefusalReason::StaleRun, $refusal->reason);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class), 'Only the buyer of the first contract signed.');
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_prepared_for_a_run_and_dispatched_once_that_run_is_gone_does_not_reach_the_next_run_of_the_workflow_id(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $late = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::LATE);
        $next = $this->signAndStartTheNextContract($contract);
        $this->work();

        $late->dispatch();
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$report->outcome, $report->code]);
        self::assertSame([$next->getRunId(), 1], [$report->run['runId'] ?? null, $report->run['attempt'] ?? null]);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class), 'Only the buyer of the first contract signed.');
    }

    /**
     * Both parties sign, the run completes and is gone, and a new run of the same workflow ID is started: it
     * waits for its own buyer, under attempt 1 like the first one did.
     */
    protected function signAndStartTheNextContract(ManagedWorkflow $contract): WorkflowState
    {
        $contract->submitInputs(self::BUYER)->run();
        $contract->submitInputs(self::SELLER)->run();

        return $contract->start()->run();
    }
}
