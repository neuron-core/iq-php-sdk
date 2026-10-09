<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\EndpointFlows;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Tests\Support\Concurrency\Gate;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Secrets;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\Flows\Transcript;

use function time;

class ShipOrderNode extends Node
{
    public function __construct(
        protected ExecutionLog $executions,
        protected Transcript $transcript,
        protected Gate $gate,
        protected int $shipsAfter,
        protected int $failures,
    ) {
    }

    public function __invoke(PaymentReceived $event, WorkflowState $state): StopEvent
    {
        $this->executions->record(self::class);

        // Neuron keeps the time of the wait with the run: the clock is read on the first pass only.
        $this->sleepUntil(new DateTimeImmutable('@' . (time() + $this->shipsAfter)));

        // A memo is the only memory of a run that survives a failed step.
        $failed = 0;
        while ($this->recallMemo("failure-{$failed}") === true) {
            $failed++;
        }

        if ($failed < $this->failures) {
            $this->memoize("failure-{$failed}", static fn (): bool => true);

            throw new ShippingFailed(Secrets::EXCEPTION);
        }

        $this->gate->pass();
        $this->transcript->note("shipped:{$state->get('orderId')}");

        return new StopEvent();
    }
}
