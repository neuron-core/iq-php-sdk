<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Flows;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;

/**
 * An agent that lets a test act inside its startEvent() hook, which Neuron
 * calls whenever the start event of a turn is built.
 */
class HookedAgent extends Agent
{
    /**
     * @param Closure(): void $inStartEventHook
     */
    public function __construct(protected Closure $inStartEventHook)
    {
        parent::__construct();
    }

    protected function startEvent(): AgentStartEvent
    {
        ($this->inStartEventHook)();

        return parent::startEvent();
    }
}
