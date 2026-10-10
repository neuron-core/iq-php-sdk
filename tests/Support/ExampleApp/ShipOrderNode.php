<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

use function time;

class ShipOrderNode extends Node
{
    /**
     * @param int $shipsAfter seconds between the payment and the moment the order ships
     */
    public function __construct(protected NodeRuns $runs, protected Faults $faults, protected int $shipsAfter)
    {
    }

    public function __invoke(PaymentSettled $event, WorkflowState $state): StopEvent
    {
        $orderId = (string) $state->get('orderId');
        $this->runs->entered(self::class, $orderId);

        if ($event->paid) {
            // Neuron keeps the time of the wait with the run: the clock is read on the first pass only.
            $this->sleepUntil(new DateTimeImmutable('@' . (time() + $this->shipsAfter)));
            $this->faults->at("ship.{$orderId}");
        }

        $this->runs->finished(self::class, $orderId);

        return new StopEvent();
    }
}
