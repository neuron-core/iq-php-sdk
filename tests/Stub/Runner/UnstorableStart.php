<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Runner;

use Closure;
use NeuronAI\Workflow\Events\Event;

/**
 * A start event PHP cannot serialize: Neuron cannot store it with a run.
 */
class UnstorableStart implements Event
{
    public readonly Closure $callback;

    public function __construct()
    {
        $this->callback = static function (): void {
        };
    }
}
