<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Faults;

use RuntimeException;

/**
 * What a store throws when it fails: neither Neuron's exception nor the
 * SDK's, like the PDOException of a database that went away.
 */
class StoreFailed extends RuntimeException
{
}
