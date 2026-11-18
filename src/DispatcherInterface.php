<?php

declare(strict_types=1);

namespace NeuronCore\IQ;

interface DispatcherInterface
{
    /**
     * Hand one message to the application's queue.
     */
    public function dispatch(Invocation $invocation): void;
}
