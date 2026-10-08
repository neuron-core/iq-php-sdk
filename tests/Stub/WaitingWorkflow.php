<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub;

use DateTimeImmutable;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * Waits for the event "payment.received", then for a timer, then completes.
 * Neuron reads the real clock, so a test makes a wait due by giving it a past time.
 */
class WaitingWorkflow extends Workflow
{
    public const EVENT = 'payment.received';

    /**
     * @param DateTimeImmutable|null $paymentDeadline when the wait for the event gives up; null: it waits for the event only
     * @param DateTimeImmutable $deliveryAt when the timer is due
     */
    public function __construct(
        protected ExecutionLog $executions,
        protected ?DateTimeImmutable $paymentDeadline = null,
        protected DateTimeImmutable $deliveryAt = new DateTimeImmutable('@0'),
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new AwaitPaymentNode($this->executions, $this->paymentDeadline),
            new DeliverNode($this->executions, $this->deliveryAt),
        ];
    }
}
