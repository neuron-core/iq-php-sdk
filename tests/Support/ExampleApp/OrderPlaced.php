<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Workflow\Events\Event;

/**
 * The start event of an order: it carries the order, and what the customer
 * wrote with it.
 */
class OrderPlaced implements Event
{
    public function __construct(public readonly string $orderId, public readonly string $note)
    {
    }
}
