<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use RuntimeException;

/**
 * Another process bound a port between the moment the system handed it out
 * and the moment a server of the tests tried to bind it: two test runs on one
 * machine can meet so. A stage that is being built is built again on other
 * ports; a server started again on the port it had cannot move.
 */
final class PortTaken extends RuntimeException
{
}
