<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use DateTimeImmutable;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Stub\Continuations\LookupWorkflow;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;

/**
 * Step 3 of section 9.7, for a run that is suspended on another wait than
 * the one the caller means, or on a wait that rejects what it is given. The
 * rule of the wait is applied in the request, as Neuron applies it when it
 * admits an answer: what the wait rejects is refused before anything is queued.
 */
class WaitMismatchTest extends ContinuationTestCase
{
    protected const PAYMENT = ['amount' => 100];

    #[DataProvider('backends')]
    public function test_a_signal_with_another_name_than_the_awaited_one_is_refused_as_not_awaited(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $queued = $this->countQueued();

        $refusal = self::refusalFrom(static fn (): PendingSegment => $contract->signal('contract.withdrawn', self::BUYER));

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_a_signal_for_a_run_that_waits_on_a_timer_is_refused_as_not_awaited(string $backend): void
    {
        $this->benchOn($backend);
        $order = $this->orderWaitingOnItsTimer();
        $queued = $this->countQueued();

        $refusal = self::refusalFrom(static fn (): PendingSegment => $order->signal(WaitingWorkflow::EVENT, self::PAYMENT));

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_an_answer_for_a_run_that_waits_on_a_timer_is_refused_as_not_awaited_when_it_is_prepared(string $backend): void
    {
        $this->benchOn($backend);
        $order = $this->orderWaitingOnItsTimer();
        $queued = $this->countQueued();

        $refusal = self::refusalFrom(static fn (): PendingSegment => $order->submitInputs(self::PAYMENT));

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_an_answer_for_a_run_that_waits_on_a_timer_is_refused_in_the_words_of_neuron_when_it_admits_one(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $order = $this->orderWaitingOnItsTimer();
        $staged = $this->withoutTheSdk(new WaitingWorkflow($executions))->submitInputs(self::PAYMENT);
        $atAdmission = self::refusalFrom(static fn (): WorkflowState => $staged->run());

        $refusal = self::refusalFrom(static fn (): PendingSegment => $order->submitInputs(self::PAYMENT));

        self::assertSame($atAdmission->getMessage(), $refusal->getMessage());
        self::assertInstanceOf(WorkflowException::class, $refusal->getPrevious(), 'The rejection of the wait is kept as the previous exception.');
    }

    #[DataProvider('backends')]
    public function test_an_answer_refused_when_it_is_prepared_leaves_the_run_on_its_timer_under_the_same_attempt(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $order = $this->orderWaitingOnItsTimer();
        $before = $this->storedRun();

        self::refusalFrom(static fn (): PendingSegment => $order->submitInputs(self::PAYMENT));

        $after = $this->storedRun();
        self::assertInstanceOf(SleepUntilRequest::class, $after?->interrupt);
        self::assertSame($before?->executionAttempt, $after->executionAttempt);
        self::assertSame(1, $executions->count(DeliverNode::class));
    }

    #[DataProvider('backends')]
    public function test_with_no_dispatcher_an_answer_for_a_run_that_waits_on_a_timer_is_refused_and_the_platform_hears_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->orderWaitingOnItsTimer();
        $order = $this->orderOfAProcessWithNoDispatcher();
        $heard = count($bench->platform->received());

        $refusal = self::refusalFrom(static fn (): PendingSegment => $order->submitInputs(self::PAYMENT));

        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertCount($heard, $bench->platform->received());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_tool_results_the_wait_does_not_expect_are_refused_as_not_awaited_before_anything_is_queued(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $lookup = $this->lookupWaitingForItsResult();
        $queued = $this->countQueued();
        $results = ['call_of_another_turn' => ['result' => 'Rome']];

        $refusal = self::refusalFrom(fn (): PendingSegment => $this->prepare($continuation, $lookup, ToolResultsRequest::EVENT_NAME, $results));

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_tool_results_the_wait_expects_continue_the_run(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $lookup = $this->lookupWaitingForItsResult();
        $results = [LookupWorkflow::CALL_ID => ['result' => 'Rome']];

        $state = $this->prepare($continuation, $lookup, ToolResultsRequest::EVENT_NAME, $results)->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame($results, $state->get('results'));
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_stages_an_answer_for_a_run_that_waits_on_a_timer_and_refuses_it_as_not_awaited_when_it_runs(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->orderWaitingOnItsTimer();
        $order = $this->withoutTheSdk(new WaitingWorkflow($executions));

        $staged = $order->submitInputs(self::PAYMENT);
        $refusal = self::refusalFrom(static fn (): WorkflowState => $staged->run());

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
    }

    #[DataProvider('backends')]
    public function test_a_signal_for_a_run_at_a_later_wait_of_another_name_is_refused_although_it_was_awaited_before(string $backend): void
    {
        $this->benchOn($backend);
        $order = $this->orderWaitingOnItsTimer();

        $refusal = self::refusalFrom(static fn (): PendingSegment => $order->signal(ContractWorkflow::EVENT));

        self::assertSame(WorkflowRefusedException::class, $refusal::class);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
    }

    /**
     * "order": it waits for the event "payment.received", then for a timer that is due in an hour. The
     * payment has arrived and a worker has posted the notices of both segments: the run is suspended on the timer.
     */
    protected function orderWaitingOnItsTimer(): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $order = $this->managed(
            'order',
            static fn (): Workflow => new WaitingWorkflow($executions, deliveryAt: new DateTimeImmutable('+1 hour')),
        );
        $order->start()->run();
        $order->signal(WaitingWorkflow::EVENT, self::PAYMENT)->run();
        $this->work();

        self::assertInstanceOf(SleepUntilRequest::class, $this->storedRun()?->interrupt);

        return $order;
    }

    /**
     * "order" as a script holds it: a process on the store and the platform of the bench, with no queue, so
     * that every message it issues runs in place.
     */
    protected function orderOfAProcessWithNoDispatcher(): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return (new NeuronCloud(TestBench::API_KEY, httpClient: $this->bench->platform))
            ->setPersistence($this->bench->persistence)
            ->register('order', static fn (): Workflow => new WaitingWorkflow($executions))
            ->workflow('order', self::WORKFLOW_ID);
    }

    /**
     * "lookup": it waits for the result of one tool call on Neuron's own ToolResultsRequest, which refuses a
     * result of a call it does not hold. The run is suspended on that wait.
     */
    protected function lookupWaitingForItsResult(): ManagedWorkflow
    {
        $lookup = $this->managed('lookup', static fn (): Workflow => new LookupWorkflow());
        $lookup->start()->run();

        return $lookup;
    }
}
