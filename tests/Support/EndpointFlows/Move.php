<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

/**
 * What the simulated platform did when it was asked to advance.
 */
enum Move: string
{
    case SentStartAgain = 'sent the start again';

    case WokeTimer = 'woke the timer of a wait';

    case Retried = 'retried a failed run';

    case RetriedDead = 'retried a dead run';

    /** Nothing is due: the platform sent nothing, or only asked. */
    case Waits = 'waits';

    case Closed = 'closed the run';
}
