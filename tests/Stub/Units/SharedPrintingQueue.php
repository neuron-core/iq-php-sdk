<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;

use function getmypid;

/**
 * A queue that a process shares with the children it forks: it prints each
 * notice it is handed, with the process that handed it and, for an observe,
 * the outcome it carries.
 */
class SharedPrintingQueue implements DispatcherInterface
{
    /** The process that built the queue: every other one is a child of it. */
    protected int $parent;

    public function __construct()
    {
        $this->parent = (int) getmypid();
    }

    public function dispatch(Invocation $invocation): void
    {
        echo getmypid() === $this->parent ? 'parent' : 'child', ': ', $invocation->verb->value, ' ', $invocation->body['outcome'] ?? '-', "\n";
    }
}
