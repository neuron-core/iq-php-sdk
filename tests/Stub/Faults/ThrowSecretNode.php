<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Faults;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;
use NeuronCore\IQ\Tests\Support\Faults\Markers;

/**
 * Throws an exception whose message is a marker the first time it runs in a
 * run, and completes the run the next time.
 */
class ThrowSecretNode extends Node
{
    public function __invoke(PaymentSettled $event, WorkflowState $state): StopEvent
    {
        // A memo is the only memory of a run that survives a failed step.
        $triedBefore = $this->recallMemo('tried') === true;
        $this->memoize('tried', static fn (): bool => true);

        if (!$triedBefore) {
            throw new NodeFailed(Markers::EXCEPTION);
        }

        return new StopEvent();
    }
}
