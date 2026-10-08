<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Stub\Local\PaidAmountTranslator;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use const NAN;

/**
 * Step 2 of section 9.7: an answer or a signal executes on the version its
 * run was started on, which is the number at the front of the run ID. In
 * these tests the first version of "contract" is the contract workflow, and
 * a later version is a workflow that completes at once: had the later one
 * executed the continuation, its own node would have run.
 */
class PinnedVersionTest extends ContinuationTestCase
{
    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_executes_the_version_the_run_was_started_on_not_the_current_one(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->contract()->start()->run();
        $contract = $this->managed('contract', static fn (): Workflow => new CompletingWorkflow($executions), version: 2);

        $state = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertSame(self::BUYER, $state->get('buyer'));
        self::assertSame([1, 0], [$executions->count(AwaitBuyerNode::class), $executions->count(CompleteNode::class)]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_worker_executes_a_dispatched_continuation_on_the_version_the_run_was_started_on(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->contractWaitingForTheBuyer();
        $contract = $this->managed('contract', static fn (): Workflow => new CompletingWorkflow($executions), version: 2);
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame([1, 0], [$executions->count(AwaitBuyerNode::class), $executions->count(CompleteNode::class)]);
    }

    #[DataProvider('backends')]
    public function test_the_snapshot_is_read_through_the_current_version_and_the_segment_is_built_on_the_pinned_one(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $built = [];
        $started = $this->managed('contract', static function (string $workflowId, ?string $runId) use ($executions, &$built): Workflow {
            $built[] = [1, $runId];

            return new ContractWorkflow($executions);
        })->start()->run();
        $contract = $this->managed('contract', static function (string $workflowId, ?string $runId) use ($executions, &$built): Workflow {
            $built[] = [2, $runId];

            return new CompletingWorkflow($executions);
        }, version: 2);
        $built = [];

        $contract->submitInputs(self::BUYER);

        self::assertSame([[2, null], [1, $started->getRunId()]], $built);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_run_in_place_is_announced_with_the_lease_of_the_version_the_run_was_started_on(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), leaseTimeout: 90)->start()->run();
        $contract = $this->managed('contract', static fn (): Workflow => new CompletingWorkflow($executions), version: 2, leaseTimeout: 900);
        $queued = $this->countQueued();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        [$announcement] = $this->queuedAfter($queued);
        self::assertSame(90, $announcement->body['leaseSeconds'] ?? null);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_dispatched_continuation_that_a_worker_of_an_older_deploy_aborted_stays_queued_for_a_worker_that_has_the_version(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2);
        $contract->start()->dispatch();
        $this->work();
        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->dispatch();

        [$aborted] = $this->queue->runAll($this->processThatOnlyHasVersion1());
        [$report] = $this->work();

        self::assertInstanceOf(CloudException::class, $aborted);
        self::assertSame(Code::UnknownVersion, $aborted->reason);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, 2], [$report->outcome, $report->executedAttempt]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_process_that_lacks_the_pinned_version_throws_unknown_version_before_anything_is_queued(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2)->start()->run();
        $queued = $this->countQueued();
        $contract = $this->processThatOnlyHasVersion1()->workflow('contract', self::WORKFLOW_ID);

        $thrown = self::cloudExceptionFrom(fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_a_process_that_lacks_the_pinned_version_throws_unknown_version_before_it_asks_the_translator(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2)->start()->run();
        $contract = $this->processThatOnlyHasVersion1()->workflow('contract', self::WORKFLOW_ID);
        $translator = new PaidAmountTranslator();

        $thrown = self::cloudExceptionFrom(static fn (): PendingSegment => $contract->submitInputs(['currency' => 'EUR'], $translator));

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
        self::assertNull($translator->translatedAgainst);
    }

    #[DataProvider('backends')]
    public function test_a_process_that_lacks_the_pinned_version_throws_unknown_version_before_it_matches_a_signal_against_the_wait(
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2)->start()->run();
        $contract = $this->processThatOnlyHasVersion1()->workflow('contract', self::WORKFLOW_ID);

        $thrown = self::cloudExceptionFrom(static fn (): PendingSegment => $contract->signal('contract.withdrawn'));

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_process_that_lacks_the_pinned_version_throws_unknown_version_before_it_checks_the_payload(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2)->start()->run();
        $contract = $this->processThatOnlyHasVersion1()->workflow('contract', self::WORKFLOW_ID);
        $notJsonCompatible = ['signer' => NAN];

        $thrown = self::cloudExceptionFrom(
            fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, $notJsonCompatible),
        );

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_run_started_outside_the_sdk_executes_on_the_lowest_version(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2);
        $contract = $this->managed('contract', static fn (): Workflow => new CompletingWorkflow($executions), version: 5);
        $started = $this->withoutTheSdk(new ContractWorkflow($executions))->run();

        $state = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertStringStartsWith('run_', (string) $started->getRunId());
        self::assertSame(self::BUYER, $state->get('buyer'));
        self::assertSame([1, 0], [$executions->count(AwaitBuyerNode::class), $executions->count(CompleteNode::class)]);
    }

    #[DataProvider('backends')]
    public function test_a_worker_executes_a_dispatched_answer_for_a_run_started_outside_the_sdk_on_the_lowest_version(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2);
        $contract = $this->managed('contract', static fn (): Workflow => new CompletingWorkflow($executions), version: 5);
        $this->withoutTheSdk(new ContractWorkflow($executions))->run();
        $contract->submitInputs(self::BUYER)->dispatch();

        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame([1, 0], [$executions->count(AwaitBuyerNode::class), $executions->count(CompleteNode::class)]);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_run_started_outside_the_sdk_under_a_run_id_that_looks_minted_is_taken_for_that_version_and_refused_as_unknown_version(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $this->withoutTheSdk(new ContractWorkflow($executions))->run(ExecutionRequest::start(runId: '2026-10-07-nightly'));

        $thrown = self::cloudExceptionFrom(fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::UnknownVersion, $thrown->reason);
        self::assertSame([], $this->queue->dispatched());
    }

    /**
     * A process of an older deploy, on the same store and the same queue: it has no version 2 of "contract".
     */
    protected function processThatOnlyHasVersion1(): NeuronCloud
    {
        $executions = $this->bench->executions;

        return $this->anotherProcess()->register('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 1);
    }
}
