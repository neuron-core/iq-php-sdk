<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use NeuronAI\Exceptions\NeuronException;
use Throwable;

/**
 * What Neuron cannot say: the reason tells the caller what to do next.
 */
class CloudException extends NeuronException
{
    public function __construct(string $message, public readonly Code $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
