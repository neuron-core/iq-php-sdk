<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Runner;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Testing\FakeDispatcher;

/**
 * The fake queue, opened for the SDK's own tests: they read the messages the
 * runner handed over, which an application's test has no reason to do.
 */
final class InspectableDispatcher extends FakeDispatcher
{
    /**
     * @return Invocation[] every message ever dispatched, in order
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
