<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use NeuronAI\Workflow\Events\Event;

class PaymentSettled implements Event
{
    /**
     * @param bool $paid false when the deadline of the payment passed first
     */
    public function __construct(public readonly bool $paid)
    {
    }
}
