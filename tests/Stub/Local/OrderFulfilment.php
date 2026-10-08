<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Local;

use DateTimeImmutable;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * The workflow of the plain PHP example: it starts with an OrderPlaced, waits
 * for the event "payment.received", then for a timer, then completes. Its
 * own start event is one no node handles: the order comes with the start.
 */
class OrderFulfilment extends Workflow
{
    public const EVENT = 'payment.received';

    /**
     * @param DateTimeImmutable $deliveryAt when the timer is due
     * @param int $failures how many executions of the first node throw before one reaches the wait
     */
    public function __construct(
        protected ExecutionLog $executions,
        protected DateTimeImmutable $deliveryAt = new DateTimeImmutable('@0'),
        protected int $failures = 0,
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new AwaitOrderPaymentNode($this->executions, $this->failures),
            new DeliverNode($this->executions, $this->deliveryAt),
        ];
    }
}
