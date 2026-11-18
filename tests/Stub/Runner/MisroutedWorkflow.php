<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Runner;

use NeuronAI\Workflow\Events\Event;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;

/**
 * Its own start event is one that none of its nodes handles.
 */
class MisroutedWorkflow extends CompletingWorkflow
{
    protected function startEvent(): Event
    {
        return new PaymentSettled();
    }
}
