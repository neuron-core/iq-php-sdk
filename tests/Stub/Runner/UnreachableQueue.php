<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
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
