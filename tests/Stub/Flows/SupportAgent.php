<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Flows;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * The chat agent of the Laravel example: a factory can rebuild it from the
 * thread ID alone, because its hooks name everything a turn needs.
 */
class SupportAgent extends Agent
{
    public function __construct(
        protected AIProviderInterface $model,
        protected MessageStoreInterface $conversations,
        protected ExecutionLog $executions,
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
        return [new RefundTool($this->executions)];
    }
}
