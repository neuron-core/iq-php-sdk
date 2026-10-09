<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Questions;

use DateTimeImmutable;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class SleepNode extends Node
{
    public function __construct(protected DateTimeImmutable $wakeAt)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $this->sleepUntil($this->wakeAt);

        return new StopEvent();
    }
}
