<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * Streams a sentence word by word, then completes in the same segment.
 */
class NarratingWorkflow extends Workflow
{
    public const WORDS = ['The', 'order', 'shipped.'];

    public function __construct(protected ExecutionLog $executions)
    {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [new NarrateNode($this->executions)];
    }
}
