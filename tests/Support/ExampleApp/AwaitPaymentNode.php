<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use DateTimeImmutable;
use Generator;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function time;

class AwaitPaymentNode extends Node
{
    /**
     * @param int $paymentDeadline seconds the payment may take
     */
    public function __construct(protected NodeRuns $runs, protected Faults $faults, protected int $paymentDeadline)
    {
    }

    /**
     * @return Generator<int, OrderProgress, mixed, PaymentSettled>
     */
    public function __invoke(OrderPlaced $event, WorkflowState $state): Generator
    {
        $this->runs->entered(self::class, $event->orderId);

        yield new OrderProgress(OrderProgress::RECEIVED);
        $this->faults->at("await-payment.{$event->orderId}");

        $state->set('orderId', $event->orderId);
        $state->set('note', $event->note);
        $state->set('card', Confidential::STATE);

        yield new OrderProgress(OrderProgress::CHECKED);
        $this->faults->at("await-payment.{$event->orderId}.checked");
        yield new OrderProgress(OrderProgress::AWAITING_PAYMENT);
        $this->faults->at("await-payment.{$event->orderId}.told");

        // Neuron keeps the deadline of the wait with the run: the clock is read on the first pass only.
        $payment = $this->awaitEvent(OrderWorkflow::PAYMENT_RECEIVED, new DateTimeImmutable('@' . (time() + $this->paymentDeadline)));
        $state->set('payment', $payment);

        $this->runs->finished(self::class, $event->orderId);

        // The provider's webhook says nothing of an approval: a payment it reports is one it took.
        return new PaymentSettled($payment !== null && ($payment['approved'] ?? true) === true);
    }
}
