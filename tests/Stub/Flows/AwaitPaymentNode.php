<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Flows;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\Concurrency\Gate;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\Flows\Transcript;

class AwaitPaymentNode extends Node
{
    public function __construct(
        protected ExecutionLog $executions,
        protected Transcript $transcript,
        protected ?Gate $gate,
    ) {
    }

    public function __invoke(OrderPlaced $event, WorkflowState $state): PaymentReceived
    {
        $this->executions->record(self::class);

        // The node runs again from the top when its wait is answered: the memo keeps one note per run.
        $this->memoize('placed', fn (): bool => $this->notePlaced($event));
        $this->gate?->pass();

        $state->set('orderId', $event->orderId);
        $state->set('note', $event->note);
        $state->set('payment', $this->awaitEvent(OrderWorkflow::EVENT));

        return new PaymentReceived();
    }

    protected function notePlaced(OrderPlaced $event): bool
    {
        $this->transcript->note("placed:{$event->orderId}");

        return true;
    }
}
