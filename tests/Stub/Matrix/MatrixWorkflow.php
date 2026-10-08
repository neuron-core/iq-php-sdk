<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Matrix;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

/**
 * A run in two nodes. The first one stops the run in a chosen way before it
 * lets it through, so that an answer, a signal or a wake has a run to
 * continue. The second one ends the segment that reaches it in a chosen way.
 */
class MatrixWorkflow extends Workflow
{
    public const EVENT = 'payment.received';

    public function __construct(
        protected ExecutionLog $executions,
        protected Opening $opening,
        protected Ending $ending,
        protected MidBody $midBody,
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new OpeningNode($this->executions, $this->opening, $this->midBody),
            new EndingNode($this->executions, $this->ending),
        ];
    }
}
