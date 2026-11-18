<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Matrix;

/**
 * How the last node of a run ends the segment that reaches it.
 */
enum Ending
{
    case Completes;

    /**
     * It waits for the event until a deadline that has passed: an answer, a
     * signal and a wake would each continue the run if they were let in.
     */
    case Waits;

    case Fails;
}
