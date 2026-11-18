<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Questions;

/**
 * What holds a workflow ID when the platform asks about it, or orders its
 * run abandoned.
 */
enum Holder: string
{
    case WaitingForAnEvent = 'a run suspended on an event';
    case Sleeping = 'a run suspended on a timer';
    case Failed = 'a failed run';
    case Executing = 'a run executing under a live lease';
    case Dead = 'a run whose lease has passed';
    case Unleased = 'a run executing without a lease';
    case Retained = 'a completion retained by code outside the SDK';

    /**
     * @return self[] the holders Neuron's abandon() discards
     */
    public static function discardable(): array
    {
        return [self::WaitingForAnEvent, self::Sleeping, self::Failed, self::Dead, self::Unleased];
    }

    /**
     * @return self[] the holders that stay as they are while a test asks about them, one question after another
     */
    public static function atRest(): array
    {
        return [self::WaitingForAnEvent, self::Sleeping, self::Failed, self::Retained];
    }

    /**
     * Whether the SDK started the run, from a start the platform sent through the queue.
     */
    public function wasQueued(): bool
    {
        return $this !== self::Unleased && $this !== self::Retained;
    }
}
