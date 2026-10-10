<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

/**
 * What an order tells whoever watches it while a segment runs: the step it
 * has reached. A checkout that streams shows it to the customer.
 */
final class OrderProgress
{
    public const RECEIVED = 'received';

    public const CHECKED = 'checked';

    public const AWAITING_PAYMENT = 'awaiting-payment';

    public function __construct(public readonly string $step)
    {
    }
}
