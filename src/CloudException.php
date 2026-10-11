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
    /**
     * @param Report|null $report for an observe the platform did not record: the report that was built, which
     *                            says what the store showed
     */
    public function __construct(
        string $message,
        public readonly Code $reason,
        ?Throwable $previous = null,
        public readonly ?Report $report = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
