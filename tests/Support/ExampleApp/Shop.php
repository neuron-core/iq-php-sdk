<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use NeuronCore\IQ\IQ;

/**
 * What bootstrap.php hands the endpoint, the two web scripts, the worker and
 * the sync script.
 */
final class Shop
{
    public const DEFINITION = 'order-fulfilment';

    public function __construct(
        public readonly IQ $iq,
        public readonly Spool $spool,
        public readonly Faults $faults,
    ) {
    }
}
