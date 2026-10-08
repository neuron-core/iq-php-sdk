<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Flows;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Stub\Flows\ShippingFailed;
use NeuronCore\Cloud\Tests\Stub\Flows\ShipOrderNode;
use NeuronCore\Cloud\Tests\Support\Flows\Planted;
use NeuronCore\Cloud\Tests\Support\Flows\PlainPhpTestCase;
use NeuronCore\Cloud\Tests\Support\Flows\PlainPhpWorker;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_filter;

/**
 * Example (a) of section 5 of the design, from the checkout to the end of
 * the order: the three local calls of the application, the worker of the
 * document, and the wakes the platform sends for the timer and for a retry.
 */
class PlainPhpFlowTest extends PlainPhpTestCase
{
    #[DataProvider('backends')]
    public function test_an_order_whose_checkout_dispatches_the_start_runs_to_its_end_in_the_workers(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);

        $this->playAnOrderStartedOnTheQueue();

        self::assertNull($this->holder(self::WORKFLOW_ID), 'The run completed: nothing holds the workflow ID.');
        self::assertSame(['placed:123', 'shipped:123'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_every_segment_of_an_order_started_on_the_queue(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);

        $this->playAnOrderStartedOnTheQueue();

        self::assertSame(['start: suspended', 'signal: suspended', 'wake: failed', 'wake: completed'], $this->outcomesReported(self::WORKFLOW_ID));
        self::assertSame(
            [Verb::Start->value, Verb::Signal->value, Verb::Wake->value, Verb::Wake->value],
            array_column($this->announcementsOf(self::WORKFLOW_ID), 'verb'),
        );
    }

    #[DataProvider('backends')]
    public function test_the_platform_is_told_what_an_order_waits_for_how_it_failed_and_that_it_ended(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);

        $this->playAnOrderStartedOnTheQueue();

        [$started, $paid, $failed, $completed] = $this->reportsOf(self::WORKFLOW_ID);
        self::assertSame(['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => OrderWorkflow::EVENT, 'wakeAt' => null], $started['run']['wait']);
        self::assertSame(['interruptId' => 2, 'type' => 'sleep_until', 'eventName' => null, 'wakeAt' => 0], $paid['run']['wait']);
        self::assertSame([ShippingFailed::class, WorkflowStatus::Failed->value], [$failed['errorClass'], $failed['run']['status']]);
        self::assertNull($completed['run']);
    }

    #[DataProvider('backends')]
    public function test_an_order_whose_checkout_runs_the_first_segment_runs_to_its_end(string $backend): void
    {
        $this->shop($backend, shippingFailures: 2);

        $state = $this->playAnOrderStartedInTheRequest();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertNull($this->holder(self::WORKFLOW_ID), 'The run completed: nothing holds the workflow ID.');
        self::assertSame(['placed:123', 'shipped:123'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_every_segment_of_an_order_started_in_the_request(string $backend): void
    {
        $this->shop($backend, shippingFailures: 2);

        $this->playAnOrderStartedInTheRequest();

        self::assertSame(
            ['start: suspended', 'signal: suspended', 'wake: failed', 'wake: failed', 'wake: completed'],
            $this->outcomesReported(self::WORKFLOW_ID),
        );
        self::assertSame(
            [Verb::Start->value, Verb::Signal->value, Verb::Wake->value, Verb::Wake->value, Verb::Wake->value],
            array_column($this->announcementsOf(self::WORKFLOW_ID), 'verb'),
        );
    }

    #[DataProvider('playedOrdersOnEveryBackend')]
    public function test_an_order_runs_each_of_its_nodes_as_often_as_its_waits_and_its_failures_ask(string $backend, string $checkout, int $shippingFailures): void
    {
        $bench = $this->shop($backend, $shippingFailures);

        $this->play($checkout);

        // A node runs again from the top after each of its waits, and after each failure.
        self::assertSame(2, $bench->executions->count(AwaitPaymentNode::class));
        self::assertSame(2 + $shippingFailures, $bench->executions->count(ShipOrderNode::class));
    }

    #[DataProvider('playedOrdersOnEveryBackend')]
    public function test_the_worker_of_the_document_sets_no_message_of_an_order_aside(string $backend, string $checkout, int $shippingFailures): void
    {
        $this->shop($backend, $shippingFailures);

        $this->play($checkout);

        self::assertSame([], $this->redis->lRange(PlainPhpWorker::QUEUE));
        self::assertSame([], $this->redis->lRange(PlainPhpWorker::LATER));
        self::assertSame([], $this->redis->lRange(PlainPhpWorker::FAILED));
    }

    #[DataProvider('playedOrdersOnEveryBackend')]
    public function test_no_request_of_an_order_calls_the_platform(string $backend, string $checkout, int $shippingFailures): void
    {
        $bench = $this->shop($backend, $shippingFailures);

        $this->play($checkout);

        self::assertSame(0, $this->sentWhileServingRequests);
        self::assertNotSame([], $bench->platform->received(), 'The workers told the platform of every segment.');
    }

    #[DataProvider('playedOrdersOnEveryBackend')]
    public function test_nothing_planted_in_an_order_reaches_the_platform(string $backend, string $checkout, int $shippingFailures): void
    {
        $this->shop($backend, $shippingFailures);

        $this->play($checkout);

        $this->assertNothingReachedThePlatform(Planted::inAnOrder());
    }

    #[DataProvider('playedOrdersOnEveryBackend')]
    public function test_what_was_planted_in_an_order_was_in_the_store_of_the_application(string $backend, string $checkout, int $shippingFailures): void
    {
        $this->shop($backend, $shippingFailures);

        $this->play($checkout);

        $neverStored = array_filter(
            [Planted::START_EVENT, Planted::SIGNAL],
            fn (string $marker): bool => !$this->journal->wrote($marker),
        );
        self::assertSame([], $neverStored, 'The boundary would be checked on values no run ever held.');
    }

    #[DataProvider('backends')]
    public function test_a_payment_that_arrives_before_a_worker_ran_the_checkout_is_answered_409_and_accepted_when_it_is_sent_again(string $backend): void
    {
        $this->shop($backend);
        $this->checkoutOnTheQueue();

        $early = $this->paymentWebhook();
        $this->work();
        $retried = $this->paymentWebhook();

        self::assertSame([409, 200], [$early, $retried]);
        self::assertSame([Verb::Signal], array_column($this->waitingIn(PlainPhpWorker::QUEUE), 'verb'), 'Only the accepted payment was queued.');
    }

    #[DataProvider('backends')]
    public function test_a_second_delivery_of_the_payment_webhook_is_answered_409_once_the_order_is_past_that_wait(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();
        $this->work();
        $this->paymentWebhook();
        $this->work();

        $second = $this->paymentWebhook();

        self::assertSame(409, $second);
        self::assertSame([], $this->redis->lRange(PlainPhpWorker::QUEUE), 'Nothing was queued.');
        self::assertSame(1, $bench->executions->count(ShipOrderNode::class));
    }

    /**
     * @return iterable<string, array{string, string, int}> a backend, how the checkout starts the order, and how
     *                                                       many times its shipping fails
     */
    public static function playedOrdersOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "started on the queue, on {$store}" => [$backend, 'dispatch', 1];
            yield "started in the request, on {$store}" => [$backend, 'run', 2];
        }
    }

    protected function play(string $checkout): void
    {
        $checkout === 'dispatch' ? $this->playAnOrderStartedOnTheQueue() : $this->playAnOrderStartedInTheRequest();
    }

    /**
     * The checkout dispatches the start; workers run every segment. The
     * shipping fails once, and the platform retries it with a second wake.
     */
    protected function playAnOrderStartedOnTheQueue(): void
    {
        $this->checkoutOnTheQueue();
        $this->work();

        $this->paymentWebhook();
        $this->work();

        $this->platformWakes(self::WORKFLOW_ID);
        $this->work();

        $this->platformWakes(self::WORKFLOW_ID);
        $this->work();
    }

    /**
     * The checkout runs the first segment in its request. The shipping fails
     * in the worker the platform woke, fails again in the request that calls
     * recover(), and the next recover() ends the order.
     *
     * @return WorkflowState what the last recover() returned
     */
    protected function playAnOrderStartedInTheRequest(): WorkflowState
    {
        $this->checkoutInTheRequest();
        $this->work();

        $this->paymentWebhook();
        $this->work();

        $this->platformWakes(self::WORKFLOW_ID);
        $this->work();

        try {
            $this->inARequest(fn (): ?WorkflowState => $this->order()->recover());
            self::fail('The shipping did not fail again.');
        } catch (ShippingFailed $again) {
            self::assertSame(Planted::EXCEPTION, $again->getMessage());
        }
        $this->work();

        $state = $this->inARequest(fn (): ?WorkflowState => $this->order()->recover());
        $this->work();

        self::assertInstanceOf(WorkflowState::class, $state, 'recover() found nothing to finish.');

        return $state;
    }
}
