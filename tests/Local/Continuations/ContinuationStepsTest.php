<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Continuations;

use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\IQ\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\IQ\Tests\Support\Continuations\ContinuationTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_unique;
use function count;

/**
 * The five steps of section 9.7 for an answer or a signal the run is waiting
 * for: the snapshot is read, the continuation is fenced with it, and it runs
 * in the request or on the queue. No step calls the platform.
 */
class ContinuationStepsTest extends ContinuationTestCase
{
    #[DataProvider('continuationsOnBackends')]
    public function test_preparing_a_continuation_only_reads_the_store(string $continuation, string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->contractWaitingForTheBuyer();
        $operations = [];
        $record = static function (string $operation) use (&$operations): void {
            $operations[] = $operation;
        };
        $bench->iq->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(($bench->persistence)(), $record));

        $this->prepare($continuation, $bench->iq->workflow('contract', self::WORKFLOW_ID), ContractWorkflow::EVENT, self::BUYER);

        self::assertSame(['get'], array_unique($operations));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_that_is_prepared_and_never_used_queues_nothing_and_executes_nothing(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $queued = $this->countQueued();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER);

        self::assertSame([], $this->queuedAfter($queued));
        self::assertSame(0, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_resumes_the_wait_with_its_payload_and_returns_the_state_of_the_run(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();

        $state = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertSame(self::BUYER, $state->get('buyer'));
        self::assertSame([WorkflowStatus::Suspended, 2], [$state->getStatus(), $state->getExecutionAttempt()]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_hands_the_queue_its_announcement_then_its_observe(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $waiting = $this->storedRun();
        $queued = $this->countQueued();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        [$announcement, $observe] = $this->queuedAfter($queued);
        self::assertSame([Verb::Announce, Verb::Observe], [$announcement->verb, $observe->verb]);
        self::assertSame([$this->verbOf($continuation)->value, $waiting?->runId, 1], self::announced($announcement));
        self::assertSame(['suspended', null, 2], self::settled($observe));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_calls_no_platform_from_the_calling_process(string $continuation, string $backend): void
    {
        $bench = $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $received = count($bench->platform->received());

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertCount($received, $bench->platform->received());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_returns_the_run_id_and_executes_nothing_in_the_request(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $waiting = $this->storedRun();

        $runId = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        self::assertSame($waiting?->runId, $runId);
        self::assertSame(0, $executions->count(AwaitBuyerNode::class));
        self::assertSame([WorkflowStatus::Suspended, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_hands_the_queue_one_message_fenced_with_the_run_id_and_the_attempt_of_the_snapshot(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $waiting = $this->storedRun();
        $queued = $this->countQueued();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        self::assertCount(1, $this->queuedAfter($queued));
        [$handedOver] = $this->queuedAfter($queued);
        self::assertSame(
            [$this->verbOf($continuation), Origin::APPLICATION, 'contract', self::WORKFLOW_ID, $waiting?->runId, 1, self::BUYER],
            [
                $handedOver->verb,
                $handedOver->origin,
                $handedOver->definition,
                $handedOver->workflowId,
                $handedOver->runId,
                $handedOver->attempt,
                $handedOver->payload,
            ],
        );
        self::assertSame($continuation === self::SIGNAL ? ContractWorkflow::EVENT : null, $handedOver->signal);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_calls_no_platform_from_the_calling_process(string $continuation, string $backend): void
    {
        $bench = $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $received = count($bench->platform->received());

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        self::assertCount($received, $bench->platform->received());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_the_worker_that_executes_a_dispatched_continuation_announces_and_reports_it_to_the_platform(
        string $continuation,
        string $backend,
    ): void {
        $bench = $this->benchOn($backend);
        $contract = $this->contractWaitingForTheBuyer();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        $this->work();

        $bench->platform->assertAnnounced(self::WORKFLOW_ID, $this->verbOf($continuation));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Suspended);
        self::assertSame(2, $this->storedRun()?->interrupt?->getId());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_the_queue_does_not_take_is_dispatch_failed_and_nothing_was_written(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $this->contractWaitingForTheBuyer();
        $prepared = $this->prepare($continuation, $this->contractWhileTheQueueIsDown(), ContractWorkflow::EVENT, self::BUYER);

        $thrown = self::cloudExceptionFrom(static fn (): string => $prepared->dispatch());

        self::assertSame(IQException::class, $thrown::class);
        self::assertSame(Code::DispatchFailed, $thrown->reason);
        self::assertSame([WorkflowStatus::Suspended, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_executes_nothing_when_the_queue_does_not_take_its_announcement(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->contractWaitingForTheBuyer();
        $prepared = $this->prepare($continuation, $this->contractWhileTheQueueIsDown(), ContractWorkflow::EVENT, self::BUYER);

        $thrown = self::cloudExceptionFrom(static fn (): WorkflowState => $prepared->run());

        self::assertSame(IQException::class, $thrown::class);
        self::assertSame(Code::DispatchFailed, $thrown->reason);
        self::assertSame(0, $executions->count(AwaitBuyerNode::class));
        self::assertSame([WorkflowStatus::Suspended, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_the_wait_of_a_continuation_the_queue_did_not_take_is_still_open_when_the_queue_is_back(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contractWaitingForTheBuyer();
        $lost = $this->prepare($continuation, $this->contractWhileTheQueueIsDown(), ContractWorkflow::EVENT, self::BUYER);
        self::thrownFrom(static fn (): string => $lost->dispatch());

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();
        $this->work();

        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    /**
     * The contract of these tests as a request sees it while the queue is down.
     */
    protected function contractWhileTheQueueIsDown(): ManagedWorkflow
    {
        $down = $this->bench->iq->setDispatcher(new UnreachableQueue())->workflow('contract', self::WORKFLOW_ID);
        $this->bench->iq->setDispatcher($this->queue);

        return $down;
    }

    protected function verbOf(string $continuation): Verb
    {
        return $continuation === self::SIGNAL ? Verb::Signal : Verb::Answer;
    }
}
