<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Runner;

use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use RuntimeException;

/**
 * A queue that is down: it accepts nothing.
 */
class UnreachableQueue implements DispatcherInterface
{
    public function dispatch(Invocation $invocation): void
    {
        throw new RuntimeException('The queue is down.');
    }
}
