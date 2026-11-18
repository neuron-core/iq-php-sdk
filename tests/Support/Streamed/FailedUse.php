<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Streamed;

/**
 * The ways the only use of a prepared segment can fail when a stream is involved.
 */
enum FailedUse
{
    /** events(), and the queue did not take the announcement: no generator was returned. */
    case StreamUnannounced;

    /** events(), and the configure() callback threw: nothing was announced. */
    case StreamMisconfigured;

    /** events(), and Neuron refused the segment at the first step of its stream. */
    case StreamRefused;

    /** events(), and a node threw in the middle of the stream. */
    case StreamWhoseNodeThrew;

    /** events(), and the consumer stopped after the first frame. */
    case StreamAbandoned;

    /** run(), and the queue did not take the announcement: nothing ran. */
    case RunUnannounced;

    /** dispatch(), and the queue did not take the segment. */
    case DispatchUnqueued;

    public function streams(): bool
    {
        return $this !== self::RunUnannounced && $this !== self::DispatchUnqueued;
    }

    /**
     * The words that name the failed use in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::StreamUnannounced => 'an events() whose announcement the queue did not take',
            self::StreamMisconfigured => 'an events() whose configure() callback threw',
            self::StreamRefused => 'an events() Neuron refused at the first step',
            self::StreamWhoseNodeThrew => 'an events() whose node threw',
            self::StreamAbandoned => 'an events() whose consumer stopped early',
            self::RunUnannounced => 'a run() whose announcement the queue did not take',
            self::DispatchUnqueued => 'a dispatch() the queue did not take',
        };
    }
}
