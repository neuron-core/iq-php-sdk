<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Concurrency;

use DateTimeImmutable;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Support\Concurrency\Gate;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * Commits one step, then stays inside the body of its second node until the
 * gate opens: long enough for a test to kill its process, or to deliver a
 * copy of its order through another one.
 */
class StallingWorkflow extends Workflow
{
    /**
     * @param DateTimeImmutable|null $timer when given, the first node waits for it before it commits its step
     */
    public function __construct(
        protected ExecutionLog $executions,
        protected Gate $gate,
        protected ?DateTimeImmutable $timer = null,
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new PrepareNode($this->executions, $this->timer),
            new StallNode($this->executions, $this->gate),
        ];
    }
}
