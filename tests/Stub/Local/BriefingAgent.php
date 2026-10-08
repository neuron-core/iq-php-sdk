<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Local;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronCore\Cloud\Tests\Stub\Units\BriefingStartEvent;

/**
 * An agent whose startEvent() hook declares a class and options of its own
 * for the event, and leaves the messages to whoever starts a turn.
 */
class BriefingAgent extends Agent
{
    public const MAX_RETRIES = 3;

    protected function startEvent(): AgentStartEvent
    {
        return new BriefingStartEvent(options: new AgentRunOptions(maxRetries: self::MAX_RETRIES));
    }
}
