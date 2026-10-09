<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Streamed;

/**
 * How the last node of a tale ends the segment that reaches it, once it has streamed a word.
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

    /** It throws a refusal of Neuron, as a node does when a workflow it drives itself refuses it. */
    case Refuses;
}
