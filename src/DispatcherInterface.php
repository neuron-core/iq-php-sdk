<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

interface DispatcherInterface
{
    /**
     * Hand one message to the application's queue.
     */
    public function dispatch(Invocation $invocation): void;
}
