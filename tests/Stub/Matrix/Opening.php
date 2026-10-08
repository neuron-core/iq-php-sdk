<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Matrix;

/**
 * What the first node of a run does before it lets the run through.
 */
enum Opening
{
    /** Nothing: the run goes straight on. */
    case Passes;

    /** It waits for the event, with no deadline. */
    case AwaitsEvent;

    /** It sleeps until a time that has passed: the timer is due at once. */
    case Sleeps;

    /** Its first execution throws; the next one goes on. */
    case FailsOnce;
}
