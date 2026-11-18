<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\InPlace;

/**
 * The ways the only use of a prepared segment can fail.
 */
enum FailedUse
{
    /** run(), and the queue did not take the announcement: nothing ran. */
    case RunUnannounced;

    /** run(), and the configure() callback threw: nothing was announced. */
    case RunMisconfigured;

    /** run(), and a node threw. */
    case RunWhoseNodeThrew;

    /** run() of a start, and Neuron refused it: a run holds the workflow ID. */
    case RunRefused;

    /** dispatch(), and the queue did not take the segment. */
    case DispatchUnqueued;

    /** dispatch() of a start, refused in the request: a run holds the workflow ID. */
    case DispatchRefused;

    public function dispatches(): bool
    {
        return $this === self::DispatchUnqueued || $this === self::DispatchRefused;
    }

    public function findsTheQueueDown(): bool
    {
        return $this === self::RunUnannounced || $this === self::DispatchUnqueued;
    }

    /**
     * Whether a run holds the workflow ID when the segment is used: only a start can fail that way.
     */
    public function meetsARun(): bool
    {
        return $this === self::RunRefused || $this === self::DispatchRefused;
    }

    /**
     * The words that name the failed use in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::RunUnannounced => 'a run() whose announcement the queue did not take',
            self::RunMisconfigured => 'a run() whose configure() callback threw',
            self::RunWhoseNodeThrew => 'a run() whose node threw',
            self::RunRefused => 'a run() Neuron refused',
            self::DispatchUnqueued => 'a dispatch() the queue did not take',
            self::DispatchRefused => 'a dispatch() refused because a run holds the workflow ID',
        };
    }
}
