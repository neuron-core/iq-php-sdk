<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\EndpointFlows;

use DateTimeImmutable;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Support\EndpointFlows\Secrets;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\Flows\Transcript;

class AwaitPaymentNode extends Node
{
    public function __construct(protected ExecutionLog $executions, protected Transcript $transcript)
    {
    }

    public function __invoke(OrderPlaced $event, WorkflowState $state): PaymentReceived
    {
        $this->executions->record(self::class);

        // The body runs again from the top when its wait is answered: the memo keeps the side effect to one.
        $this->memoize('placed', fn (): bool => $this->note("placed:{$event->orderId}"));

        $state->set('orderId', $event->orderId);
        $state->set('note', $event->note);
        $state->set('card', Secrets::STATE);
        $state->set('payment', $this->awaitEvent(OrderWorkflow::EVENT, new DateTimeImmutable('+1 hour')));

        $this->note("paid:{$event->orderId}");

        return new PaymentReceived();
    }

    protected function note(string $line): bool
    {
        $this->transcript->note($line);

        return true;
    }
}
