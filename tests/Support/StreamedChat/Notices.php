<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Verb;

use function array_map;
use function array_values;

/**
 * The two notices of a segment as a test reads them in a queue.
 */
final class Notices
{
    /**
     * @param Invocation[] $messages announcements and observes
     * @return string[] each notice with the segment it speaks for and, for an observe, how that segment ended,
     *                  or "no outcome" when the observe does not say
     */
    public static function describe(array $messages): array
    {
        return array_map(static function (Invocation $notice): string {
            $about = $notice->body ?? [];

            return $notice->verb === Verb::Observe
                ? "observe of the {$about['verb']}: " . ($about['outcome'] ?? 'no outcome') . (isset($about['code']) ? " ({$about['code']})" : '')
                : "{$notice->verb->value} of the {$about['verb']}";
        }, array_values($messages));
    }
}
