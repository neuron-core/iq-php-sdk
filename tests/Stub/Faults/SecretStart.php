<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Faults;

use NeuronAI\Workflow\Events\Event;

/**
 * A start event that carries an input of the application.
 */
class SecretStart implements Event
{
    public function __construct(public readonly string $input)
    {
    }
}
