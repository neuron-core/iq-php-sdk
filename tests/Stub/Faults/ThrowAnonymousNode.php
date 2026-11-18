<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Faults;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use RuntimeException;

/**
 * Throws an exception of an anonymous class. PHP names such a class after
 * its parent, a NUL byte, and the path and line of the file that declares it.
 */
class ThrowAnonymousNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        throw new class ('The anonymous exception was thrown on purpose.') extends RuntimeException {
        };
    }
}
