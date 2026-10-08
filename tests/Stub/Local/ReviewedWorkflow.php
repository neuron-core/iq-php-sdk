<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Local;

use Closure;
use NeuronAI\Workflow\Workflow;

/**
 * Waits for the event "review.done" and completes, and lets a test act once
 * the answer has arrived: the run is then running with its answered wait
 * still attached, and failed with it when the test throws.
 */
class ReviewedWorkflow extends Workflow
{
    public const EVENT = 'review.done';

    /**
     * @param Closure(): void $onceReviewed called from inside the node, after its wait was answered
     */
    public function __construct(protected Closure $onceReviewed)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new ReviewNode($this->onceReviewed)];
    }
}
