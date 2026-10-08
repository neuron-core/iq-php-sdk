<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use NeuronAI\Workflow\Events\Event;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\PaymentSettled;

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
