<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Local;

use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Verb;
use RuntimeException;

/**
 * A queue that goes down for one kind of message and takes every other.
 */
class PickyQueue implements DispatcherInterface
{
    public function __construct(protected DispatcherInterface $queue, protected Verb $refused)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        if ($invocation->verb === $this->refused) {
            throw new RuntimeException("The queue is down for '{$invocation->verb->value}'.");
        }

        $this->queue->dispatch($invocation);
    }
}
