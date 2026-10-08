<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;

/**
 * With no dispatcher set, every message runs in place. That is for scripts and tests.
 *
 * @internal
 */
final class InPlaceDispatcher implements DispatcherInterface
{
    public function __construct(protected NeuronCloud $cloud)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $this->cloud->invoke($invocation);
    }
}
