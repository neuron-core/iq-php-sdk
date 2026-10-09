<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\StreamedChat;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Broadcast;

/**
 * The chat agent of example (b) of the design: a factory rebuilds it from the
 * thread ID alone. Its two hooks name where a segment delivers that no
 * response streams: a recovered turn, a wake of the platform.
 */
class SupportAgent extends Agent
{
    public function __construct(
        protected AIProviderInterface $model,
        protected MessageStoreInterface $conversations,
        protected RefundOrder $refund,
        protected Broadcast $broadcast,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->model;
    }

    protected function messageStore(): MessageStoreInterface
    {
        return $this->conversations;
    }

    protected function tools(): array
    {
        return [$this->refund];
    }

    protected function streamAdapter(): AGUIAdapter
    {
        return new AGUIAdapter((string) $this->getThreadId());
    }

    protected function channel(): CallbackChannel
    {
        return $this->broadcast->channelOf((string) $this->getThreadId());
    }
}
