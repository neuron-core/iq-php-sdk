<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * An agent the platform can start: its startEvent() hook builds the prompt
 * from the thread ID.
 */
class PromptingAgent extends Agent
{
    public const PROMPT = 'Reconcile the ledger of ';

    protected function startEvent(): AgentStartEvent
    {
        return new AgentStartEvent([new UserMessage(self::PROMPT . $this->getThreadId())]);
    }
}
