<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Flows;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Support\TestBench;

/**
 * A test of example (a) of section 5 of the design: the shop whose checkout
 * starts an order and whose payment webhook delivers "payment.received".
 */
abstract class PlainPhpTestCase extends FlowTestCase
{
    protected const DEFINITION = 'order-fulfilment';

    protected const ORDER_ID = '123';

    protected const WORKFLOW_ID = 'order:123';

    /** How many times the node after the timer throws before an order ships. */
    protected int $shippingFailures = 0;

    /**
     * bootstrap.php: "order-fulfilment" is registered on the store and the queue of the bench.
     */
    protected function shop(string $backend, int $shippingFailures = 0): TestBench
    {
        $bench = $this->benchOn($backend);
        $this->shippingFailures = $shippingFailures;

        $bench->cloud->register(self::DEFINITION, $this->orderWorkflow(...));

        return $bench;
    }

    protected function orderWorkflow(): Workflow
    {
        return new OrderWorkflow($this->bench->executions, $this->transcript, shippingFailures: $this->shippingFailures);
    }

    protected function order(): ManagedWorkflow
    {
        return $this->bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID);
    }

    /**
     * The checkout of the example, which starts the order on the queue.
     *
     * @return string the run ID
     */
    protected function checkoutOnTheQueue(): string
    {
        return $this->inARequest(fn (): string => $this->order()->start($this->orderPlaced())->dispatch());
    }

    /**
     * The checkout of the example, which runs the first segment of the order in its request.
     */
    protected function checkoutInTheRequest(): WorkflowState
    {
        return $this->inARequest(fn (): WorkflowState => $this->order()->start($this->orderPlaced())->run());
    }

    /**
     * The payment webhook of the example.
     *
     * @return int the HTTP status it answers its sender
     */
    protected function paymentWebhook(): int
    {
        return $this->inARequest(function (): int {
            try {
                $this->order()->signal(OrderWorkflow::EVENT, ['amount' => 49.9, 'reference' => Planted::SIGNAL])->dispatch();
            } catch (InputTranslationException|WorkflowRefusedException) {
                return 409;
            }

            return 200;
        });
    }

    protected function orderPlaced(): OrderPlaced
    {
        return new OrderPlaced(self::ORDER_ID, Planted::START_EVENT);
    }
}
