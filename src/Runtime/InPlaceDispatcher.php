<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;

/**
 * With no dispatcher set, every message runs in place. That is for scripts and tests.
 *
 * @internal
 */
final class InPlaceDispatcher implements DispatcherInterface
{
    public function __construct(protected IQ $iq)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $this->iq->invoke($invocation);
    }
}
