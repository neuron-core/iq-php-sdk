<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Questions;

use NeuronAI\Workflow\Workflow;
use RuntimeException;

/**
 * A workflow whose destructor throws for the copy the SDK works with, as a
 * class may that closes a connection of its own when it is released.
 */
class ReleasedWithAnErrorWorkflow extends Workflow
{
    public const FAILURE = 'The connection to crm.internal could not be closed.';

    protected bool $isTheCopy = false;

    public function __clone()
    {
        $this->isTheCopy = true;
    }

    public function __destruct()
    {
        // for() works on a copy: the one the factory built is released as soon as it is made.
        if ($this->isTheCopy) {
            throw new RuntimeException(self::FAILURE);
        }
    }
}
