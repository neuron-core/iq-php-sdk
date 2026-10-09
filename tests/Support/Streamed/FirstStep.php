<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Streamed;

use Generator;

/**
 * The ways a consumer takes the first step of a stream.
 */
enum FirstStep
{
    /** What the endpoint of example (b) of the design calls before it sends anything to the browser. */
    case Valid;

    case Current;

    case Next;

    case ForeachLoop;

    /**
     * The words that name the step in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::Valid => 'valid()',
            self::Current => 'current()',
            self::Next => 'next()',
            self::ForeachLoop => 'a foreach',
        };
    }

    /**
     * @param Generator<int, object, mixed, mixed> $events a stream nobody has taken a step of yet
     */
    public function takeOn(Generator $events): void
    {
        match ($this) {
            self::Valid => $events->valid(),
            self::Current => $events->current(),
            self::Next => $events->next(),
            self::ForeachLoop => self::enterALoopOver($events),
        };
    }

    /**
     * @param Generator<int, object, mixed, mixed> $events
     */
    protected static function enterALoopOver(Generator $events): void
    {
        foreach ($events as $event) {
            break;
        }
    }
}
