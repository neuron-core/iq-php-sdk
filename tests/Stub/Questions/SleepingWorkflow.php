<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Questions;

use DateTimeImmutable;
use NeuronAI\Workflow\Workflow;

/**
 * Sleeps on a timer in its first segment, then completes.
 */
class SleepingWorkflow extends Workflow
{
    public function __construct(protected DateTimeImmutable $wakeAt)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new SleepNode($this->wakeAt)];
    }
}
