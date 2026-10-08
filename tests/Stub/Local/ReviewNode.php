<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Local;

use Closure;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class ReviewNode extends Node
{
    /**
     * @param Closure(): void $onceReviewed
     */
    public function __construct(protected Closure $onceReviewed)
    {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('review', $this->awaitEvent(ReviewedWorkflow::EVENT));

        ($this->onceReviewed)();

        return new StopEvent();
    }
}
