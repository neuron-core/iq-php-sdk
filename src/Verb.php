<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

/**
 * What an invocation asks for: a segment of a run (start, wake, answer,
 * signal), or one of the SDK's own notices to the platform (announce, observe).
 */
enum Verb: string
{
    case Start = 'start';
    case Wake = 'wake';
    case Answer = 'answer';
    case Signal = 'signal';
    case Announce = 'announce';
    case Observe = 'observe';
}
