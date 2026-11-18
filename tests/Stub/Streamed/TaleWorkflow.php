<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Streamed;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Stub\Matrix\MidBody;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * A tale streamed word by word by two nodes. The first one can wait for a
 * reader before it says anything, so that an answer or a signal has a run to
 * continue. The second one ends the segment that reaches it in a chosen way.
 */
class TaleWorkflow extends Workflow
{
    public const EVENT = 'reader.arrived';

    public const MESSAGE_ID = 'tale';

    /** What the first node streams. */
    public const OPENING = ['Once', 'upon'];

    /** What the last node streams: its first word, then how it ends the segment, then its last word. */
    public const CLOSING = ['a', 'time.'];

    public function __construct(
        protected ExecutionLog $executions,
        protected bool $awaitsReader,
        protected Ending $ending,
        protected MidBody $midStream,
    ) {
        parent::__construct();
    }

    protected function nodes(): array
    {
        return [
            new OpenTaleNode($this->executions, $this->awaitsReader, $this->midStream),
            new CloseTaleNode($this->executions, $this->ending),
        ];
    }
}
