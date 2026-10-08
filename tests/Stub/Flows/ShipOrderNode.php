<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Flows;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\Flows\Planted;
use NeuronCore\Cloud\Tests\Support\Flows\Transcript;

class ShipOrderNode extends Node
{
    public function __construct(
        protected ExecutionLog $executions,
        protected Transcript $transcript,
        protected DateTimeImmutable $shipsAt,
        protected int $failures,
    ) {
    }

    public function __invoke(PaymentReceived $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        $this->sleepUntil($this->shipsAt);

        // A memo is the only memory of a run that survives a failed step.
        $failed = 0;
        while ($this->recallMemo("failure-{$failed}") === true) {
            $failed++;
        }

        if ($failed < $this->failures) {
            $this->memoize("failure-{$failed}", static fn (): bool => true);

            throw new ShippingFailed(Planted::EXCEPTION);
        }

        $this->transcript->note("shipped:{$state->get('orderId')}");

        return new StopEvent();
    }
}
