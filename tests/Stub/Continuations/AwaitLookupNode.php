<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Continuations;

use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class AwaitLookupNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('results', $this->interrupt(new ToolResultsRequest([new ToolCall('lookup', LookupWorkflow::CALL_ID)])));

        return new StopEvent();
    }
}
