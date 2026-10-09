<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Requests;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use Throwable;

/**
 * A queue that takes nothing and throws what the test chose.
 */
class FailingQueue implements DispatcherInterface
{
    public function __construct(protected Throwable $failure)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        throw $this->failure;
    }
}
