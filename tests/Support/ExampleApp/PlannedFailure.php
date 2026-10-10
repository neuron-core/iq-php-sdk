<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use RuntimeException;

/**
 * What a point of the shop throws when a test planned a failure there.
 */
class PlannedFailure extends RuntimeException
{
}
