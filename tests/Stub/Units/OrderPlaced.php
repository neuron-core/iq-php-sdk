<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\Workflow\Events\Event;

class OrderPlaced implements Event
{
    public function __construct(public readonly string $orderId)
    {
    }
}
