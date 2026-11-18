<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;

/**
 * A wait an application derives from Neuron's own: its JSON form carries what
 * the reviewer is shown.
 */
class RefundReview extends WaitForEventRequest
{
    public const EVENT_NAME = 'refund.reviewed';

    public function __construct(protected string $customer, protected string $note)
    {
        parent::__construct(self::EVENT_NAME);
    }

    protected function metadata(): array
    {
        return ['customer' => $this->customer, 'note' => $this->note];
    }
}
