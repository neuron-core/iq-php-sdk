<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\Cloud\Tests\Stub\Continuations\AwaitSellerNode;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * A dispatched answer or signal that the queue delivers twice, and the
 * expiresAt it carries: row 4 of section 7 and step 4 of section 9.7.
 */
class SecondDeliveryTest extends ContinuationTestCase
{
    protected const A_DAY = 86400;

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_delivered_twice_is_accepted_once_and_its_copy_is_refused_as_stale_attempt(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        [$first, $copy] = $this->work(deliveries: 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $copy);
        self::assertSame([Outcome::Suspended, 2], [$first->outcome, $first->executedAttempt]);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value, null], [$copy->outcome, $copy->code, $copy->executedAttempt]);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_the_copy_of_a_dispatched_continuation_does_not_reach_the_later_wait_of_the_same_kind(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        $this->work(deliveries: 2);

        self::assertSame(2, $this->storedRun()?->interrupt?->getId());
        self::assertSame(0, $executions->count(AwaitSellerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_the_copy_of_a_dispatched_continuation_that_completed_the_run_is_refused_as_stale_run(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $contract->submitInputs(self::BUYER)->dispatch();
        $this->work();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::SELLER)->dispatch();

        [$first, $copy] = $this->work(deliveries: 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $copy);
        self::assertSame([Outcome::Completed, 3], [$first->outcome, $first->executedAttempt]);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value, null], [$copy->outcome, $copy->code, $copy->executedAttempt]);
        self::assertSame(1, $executions->count(AwaitSellerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_the_platform_is_told_of_the_refused_copy(string $continuation, string $backend): void
    {
        $bench = $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        $this->work(deliveries: 2);

        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::StaleAttempt->value);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_carries_an_expires_at_24_hours_ahead(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $queued = $this->countQueued();
        $before = time();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        [$handedOver] = $this->queuedAfter($queued);
        self::assertGreaterThanOrEqual($before + self::A_DAY, $handedOver->expiresAt);
        self::assertLessThanOrEqual(time() + self::A_DAY, $handedOver->expiresAt);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_worker_executes_a_dispatched_continuation_whose_expires_at_has_passed(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $queued = $this->countQueued();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();
        [$handedOver] = $this->queuedAfter($queued);
        $heldUpForTwoDays = Invocation::fromArray(['expiresAt' => time() - self::A_DAY] + $handedOver->toArray());

        $report = $this->bench->cloud->invoke($heldUpForTwoDays);

        self::assertSame([Outcome::Suspended, 2], [$report->outcome, $report->executedAttempt]);
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }
}
