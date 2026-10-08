<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use Closure;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Local\ReviewedWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Step 1 of section 9.7: an answer or a signal is prepared only for a run
 * that is suspended on a wait. The SDK is stricter here than Neuron, which
 * stages an answer for any run with a wait attached and decides when it runs.
 */
class RunStateRefusalTest extends ContinuationTestCase
{
    protected const APPROVED = ['approved' => true];

    protected const DECLINED = ['approved' => false];

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_workflow_id_that_no_run_holds_is_an_input_translation_exception(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contract();

        $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame('There is no persisted run to continue.', $refusal->getMessage());
        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_run_that_is_running_is_an_input_translation_exception(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $refusal = null;
        $whileRunning = function () use ($continuation, &$refusal): void {
            $busy = $this->bench->cloud->workflow('busy', self::WORKFLOW_ID);
            $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $busy, ContractWorkflow::EVENT, self::BUYER));
        };

        $this->managed('busy', static fn (): Workflow => new BusyWorkflow($executions, $whileRunning))->start()->run();

        self::assertInstanceOf(Throwable::class, $refusal);
        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertStringContainsString("'running'", $refusal->getMessage());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_run_that_failed_is_an_input_translation_exception(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $flaky = $this->managed('flaky', static fn (): Workflow => new FlakyWorkflow($executions));
        self::thrownFrom(static fn (): WorkflowState => $flaky->start()->run());
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $flaky, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertStringContainsString("'failed'", $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_run_that_completed_and_is_gone_is_an_input_translation_exception(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $greeting = $this->managed('greeting', static fn (): Workflow => new CompletingWorkflow($executions));
        $greeting->start()->run();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $greeting, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame('There is no persisted run to continue.', $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_failed_run_whose_answered_wait_is_still_attached_is_an_input_translation_exception(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $review = $this->reviewThatFailedOnceAnswered();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $review, ReviewedWorkflow::EVENT, self::APPROVED));

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertStringContainsString("'failed'", $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_stages_the_same_answer_for_that_failed_run_and_retries_the_run_with_it(string $backend): void
    {
        $this->benchOn($backend);
        $this->reviewThatFailedOnceAnswered();
        $review = $this->withoutTheSdk(new ReviewedWorkflow(static function (): void {
        }));

        $state = $review->submitInputs(self::APPROVED)->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(self::APPROVED, $state->get('review'));
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_stages_another_answer_for_that_failed_run_and_refuses_it_as_not_awaited_when_it_runs(string $backend): void
    {
        $this->benchOn($backend);
        $this->reviewThatFailedOnceAnswered();
        $review = $this->withoutTheSdk(new ReviewedWorkflow(static function (): void {
        }));

        $staged = $review->submitInputs(self::DECLINED);
        $refusal = self::refusalFrom(static fn (): WorkflowState => $staged->run());

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_for_a_running_run_whose_answered_wait_is_still_attached_is_an_input_translation_exception(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $refusal = null;
        $attached = null;
        $onceAnswered = function () use ($continuation, &$refusal, &$attached): void {
            $attached = $this->storedRun()?->interrupt;
            $review = $this->bench->cloud->workflow('review', self::WORKFLOW_ID);
            $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $review, ReviewedWorkflow::EVENT, self::APPROVED));
        };

        $this->answerAReview($onceAnswered);

        self::assertInstanceOf(InterruptRequest::class, $attached);
        self::assertInstanceOf(Throwable::class, $refusal);
        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertStringContainsString("'running'", $refusal->getMessage());
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_stages_an_answer_for_that_running_run_and_refuses_it_as_executing_when_it_runs(string $backend): void
    {
        $this->benchOn($backend);
        $staged = null;
        $refusal = null;
        $onceAnswered = function () use (&$staged, &$refusal): void {
            $review = $this->withoutTheSdk(new ReviewedWorkflow(static function (): void {
            }));
            $staged = $review->submitInputs(self::APPROVED);
            $refusal = self::thrownFrom(static fn (): WorkflowState => $staged->run());
        };

        $this->answerAReview($onceAnswered);

        self::assertInstanceOf(PendingExecution::class, $staged);
        self::assertInstanceOf(WorkflowRefusedException::class, $refusal);
        self::assertSame(RefusalReason::Executing, $refusal->reason);
    }

    /**
     * A review that was answered and whose node then threw: the run is failed, with its answered wait still attached.
     */
    protected function reviewThatFailedOnceAnswered(): ManagedWorkflow
    {
        $review = $this->managed('review', static fn (): Workflow => new ReviewedWorkflow(static function (): never {
            throw new NodeFailed('The review could not be filed.');
        }));
        $review->start()->run();
        self::thrownFrom(static fn (): WorkflowState => $review->submitInputs(self::APPROVED)->run());

        self::assertSame(WorkflowStatus::Failed, $this->storedRun()?->status);
        self::assertInstanceOf(InterruptRequest::class, $this->storedRun()?->interrupt);

        return $review;
    }

    /**
     * Answer a review in this process: $onceAnswered is called from inside the node, while the run is
     * running with its answered wait still attached.
     *
     * @param Closure(): void $onceAnswered
     */
    protected function answerAReview(Closure $onceAnswered): void
    {
        $review = $this->managed('review', static fn (): Workflow => new ReviewedWorkflow($onceAnswered));
        $review->start()->run();
        $review->submitInputs(self::APPROVED)->run();
    }
}
