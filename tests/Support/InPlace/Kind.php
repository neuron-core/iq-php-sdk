<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronCore\Cloud\Tests\Stub\Matrix\Opening;
use NeuronCore\Cloud\Verb;

/**
 * The kinds of segment an application runs in its own process.
 */
enum Kind
{
    case Start;

    case Answer;

    case Signal;

    /** The wake that recover() executes for a run whose first segment failed. */
    case Recovery;

    public function verb(): Verb
    {
        return match ($this) {
            self::Start => Verb::Start,
            self::Answer => Verb::Answer,
            self::Signal => Verb::Signal,
            self::Recovery => Verb::Wake,
        };
    }

    public function continuesARun(): bool
    {
        return $this !== self::Start;
    }

    /**
     * The words that name a segment of this kind in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::Start => 'a start',
            self::Answer => 'an answer',
            self::Signal => 'a signal',
            self::Recovery => 'the wake of recover()',
        };
    }

    /**
     * What the first node of the run does, so that a segment of this kind has the run it needs.
     */
    public function opening(): Opening
    {
        return match ($this) {
            self::Start => Opening::Passes,
            self::Answer, self::Signal => Opening::AwaitsEvent,
            self::Recovery => Opening::FailsOnce,
        };
    }
}
