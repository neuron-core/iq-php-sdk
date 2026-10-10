<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

use function strlen;
use function substr;

/**
 * The workflow of example (a) of section 5 of the design: ordinary Neuron
 * code that knows nothing about the SDK. Its first node waits for the event
 * "payment.received" with a deadline, its second sleeps until the order
 * ships, then the run completes.
 */
class OrderWorkflow extends Workflow
{
    public const PAYMENT_RECEIVED = 'payment.received';

    protected const WORKFLOW_ID_PREFIX = 'order:';

    /**
     * @param int $paymentDeadline seconds the payment may take
     * @param int $shipsAfter seconds between the payment and the moment the order ships
     */
    public function __construct(
        protected NodeRuns $runs,
        protected Faults $faults,
        protected int $paymentDeadline,
        protected int $shipsAfter,
    ) {
        parent::__construct();
    }

    public static function workflowIdOf(string $orderId): string
    {
        return self::WORKFLOW_ID_PREFIX . $orderId;
    }

    /**
     * The platform starts an order with no input: the workflow loads it from its workflow ID.
     */
    protected function startEvent(): Event
    {
        return new OrderPlaced(substr((string) $this->getWorkflowId(), strlen(self::WORKFLOW_ID_PREFIX)), Confidential::START_EVENT);
    }

    protected function nodes(): array
    {
        return [
            new AwaitPaymentNode($this->runs, $this->faults, $this->paymentDeadline),
            new ShipOrderNode($this->runs, $this->faults, $this->shipsAfter),
        ];
    }
}
