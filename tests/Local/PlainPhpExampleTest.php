<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\Local\AwaitOrderPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Local\OrderFulfilment;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Units\OrderPlaced;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The local calls of the plain PHP example of the design, played against the
 * fake platform and the fake queue: the checkout starts an order, the payment
 * webhook delivers its event, and a failed order is finished with recover().
 */
class PlainPhpExampleTest extends CloudTestCase
{
    protected const ORDER_ID = '123';

    protected const WORKFLOW_ID = 'order:123';

    protected const PAYMENT = ['amount' => 49.9];

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    #[DataProvider('backends')]
    public function test_the_checkout_runs_the_first_segment_in_its_request_and_gets_the_state(string $backend): void
    {
        $bench = $this->shop($backend);

        $state = $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->run();

        self::assertSame(WorkflowStatus::Suspended, $state->getStatus());
        self::assertSame(self::ORDER_ID, $state->get('orderId'));
        self::assertSame(1, $bench->executions->count(AwaitOrderPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_start_run_in_the_request_calls_no_platform_and_leaves_its_two_notices_in_the_queue(string $backend): void
    {
        $bench = $this->shop($backend);

        $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->run();

        self::assertSame([], $bench->platform->received());
        $bench->dispatcher->assertDispatched(Verb::Announce, self::WORKFLOW_ID);
        $bench->dispatcher->assertDispatched(Verb::Observe, self::WORKFLOW_ID);
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_of_a_start_run_in_the_request_when_a_worker_drains_the_queue(string $backend): void
    {
        $bench = $this->shop($backend);
        $state = $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->run();

        [, $report] = $bench->dispatcher->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Verb::Start, Origin::APPLICATION, Outcome::Suspended], [$report->verb, $report->origin, $report->outcome]);
        self::assertSame($state->getRunId(), $report->run['runId']);
        self::assertSame(OrderFulfilment::EVENT, $report->run['wait']['eventName']);
        self::assertFalse($report->claimed, 'A start run in the request exists in one copy: it is not entered in the ledger.');
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Suspended);
    }

    #[DataProvider('backends')]
    public function test_the_checkout_dispatches_a_start_that_a_worker_runs_under_the_run_id_it_was_given(string $backend): void
    {
        $bench = $this->shop($backend);

        $runId = $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->dispatch();

        self::assertSame(0, $bench->executions->total(), 'Nothing runs in the request that dispatches.');
        $bench->dispatcher->assertDispatched(Verb::Start, self::WORKFLOW_ID);
        [$report] = $bench->dispatcher->runAll($bench->cloud);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame($runId, $report->run['runId']);
        self::assertTrue($report->claimed);
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Suspended);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_delivered_twice_runs_once(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->dispatch();

        [$first, $second] = $bench->dispatcher->runAll($bench->cloud, 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame(Outcome::Suspended, $first->outcome);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$second->outcome, $second->code]);
        self::assertSame($first->run, $second->run);
        self::assertSame(1, $bench->executions->count(AwaitOrderPaymentNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Rejected, Code::AlreadyClaimed->value);
    }

    #[DataProvider('backends')]
    public function test_a_second_checkout_of_an_order_that_is_running_is_refused_in_the_request(string $backend): void
    {
        $bench = $this->shop($backend);
        $runId = $this->orderWaitingForPayment($bench);

        try {
            $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->dispatch();
            self::fail('A second start was dispatched for a workflow ID that a run holds.');
        } catch (RunInFlightException $refusal) {
            self::assertSame($runId, $refusal->runId);
        }

        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_the_payment_webhook_dispatches_a_signal_that_a_worker_delivers_to_the_waiting_run(string $backend): void
    {
        $bench = $this->shop($backend);
        $runId = $this->orderWaitingForPayment($bench);

        $signalled = $this->order($bench)->signal(OrderFulfilment::EVENT, self::PAYMENT)->dispatch();

        self::assertSame($runId, $signalled);
        self::assertSame(0, $bench->executions->count(DeliverNode::class), 'Nothing runs in the request that dispatches.');
        [$report] = $bench->dispatcher->runAll($bench->cloud);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Verb::Signal, Outcome::Suspended, 2], [$report->verb, $report->outcome, $report->executedAttempt]);
        self::assertSame('sleep_until', $report->run['wait']['type'], 'The run went on to its timer.');
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Signal);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_signal_delivered_twice_continues_the_run_once(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForPayment($bench);
        $this->order($bench)->signal(OrderFulfilment::EVENT, self::PAYMENT)->dispatch();

        [$first, $second] = $bench->dispatcher->runAll($bench->cloud, 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame(Outcome::Suspended, $first->outcome);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value], [$second->outcome, $second->code]);
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_payment_for_an_order_that_was_never_started_is_refused_in_the_request_and_not_queued(string $backend): void
    {
        $bench = $this->shop($backend);

        self::assertInstanceOf(InputTranslationException::class, $this->refusalOfPayment($bench));
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_a_payment_for_an_order_that_is_past_that_wait_is_refused_in_the_request_and_not_queued(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForPayment($bench);
        $this->order($bench)->signal(OrderFulfilment::EVENT, self::PAYMENT)->dispatch();
        $bench->dispatcher->runAll($bench->cloud);

        $refusal = $this->refusalOfPayment($bench);

        self::assertInstanceOf(WorkflowRefusedException::class, $refusal);
        self::assertSame(RefusalReason::NotAwaited, $refusal->reason);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_in_the_request_an_order_whose_first_segment_failed(string $backend): void
    {
        $bench = $this->shop($backend, failures: 1);
        $this->failTheCheckout($bench);

        $state = $this->order($bench)->recover();

        self::assertInstanceOf(WorkflowState::class, $state);
        self::assertSame(WorkflowStatus::Suspended, $state->getStatus(), 'The order reached its wait for the payment.');
        self::assertSame(self::ORDER_ID, $state->get('orderId'));
        self::assertSame(2, $bench->executions->count(AwaitOrderPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_of_the_failure_and_of_its_recovery_when_a_worker_drains_the_queue(string $backend): void
    {
        $bench = $this->shop($backend, failures: 1);
        $this->failTheCheckout($bench);
        $bench->dispatcher->runAll($bench->cloud);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Failed);

        $this->order($bench)->recover();
        [, $report] = $bench->dispatcher->runAll($bench->cloud);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Verb::Wake, Outcome::Suspended, 2], [$report->verb, $report->outcome, $report->executedAttempt]);
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
    }

    #[DataProvider('backends')]
    public function test_recover_has_nothing_to_do_for_an_order_that_waits_for_its_payment(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForPayment($bench);

        self::assertNull($this->order($bench)->recover());
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
        self::assertSame(1, $bench->executions->count(AwaitOrderPaymentNode::class));
    }

    /**
     * The bootstrap of the example: one store, one queue, and "order-fulfilment" registered.
     *
     * @param int $failures how many times the first node of an order throws before it reaches its wait
     */
    protected function shop(string $backend, int $failures = 0): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->cloud->register(
            'order-fulfilment',
            static fn (): Workflow => new OrderFulfilment($executions, failures: $failures),
        );

        return $bench;
    }

    protected function order(TestBench $bench): ManagedWorkflow
    {
        return $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID);
    }

    /**
     * The checkout dispatched the start and a worker ran it: the order waits for "payment.received".
     *
     * @return string the run ID
     */
    protected function orderWaitingForPayment(TestBench $bench): string
    {
        $runId = $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->dispatch();
        $bench->dispatcher->runAll($bench->cloud);

        return $runId;
    }

    /**
     * The checkout runs the first segment in its request, and the first node throws.
     */
    protected function failTheCheckout(TestBench $bench): void
    {
        try {
            $this->order($bench)->start(new OrderPlaced(self::ORDER_ID))->run();
            self::fail('The first node did not throw.');
        } catch (NodeFailed) {
        }
    }

    /**
     * The payment webhook of the example: what it catches to answer 409.
     */
    protected function refusalOfPayment(TestBench $bench): InputTranslationException|WorkflowRefusedException|null
    {
        try {
            $this->order($bench)->signal(OrderFulfilment::EVENT, self::PAYMENT)->dispatch();
        } catch (InputTranslationException|WorkflowRefusedException $refusal) {
            return $refusal;
        }

        return null;
    }
}
