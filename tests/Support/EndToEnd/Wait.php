<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use Closure;
use PHPUnit\Framework\AssertionFailedError;

use function microtime;
use function sprintf;
use function usleep;

/**
 * How a test waits in real time: it looks again and again until what it
 * waits for is there, and fails when that takes too long.
 */
final class Wait
{
    /** Microseconds between two looks. */
    protected const PATIENCE = 40_000;

    /**
     * @template TSeen
     * @param string $what what is awaited, for the failure
     * @param Closure(): (TSeen|null|false) $seen what was awaited, once it is there
     * @return TSeen
     * @throws AssertionFailedError when it did not happen in time
     */
    public static function until(string $what, Closure $seen, float $seconds = 20.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            $found = $seen();

            if ($found !== null && $found !== false) {
                return $found;
            }

            usleep(self::PATIENCE);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError(sprintf('This did not happen within %.0f seconds: %s.', $seconds, $what));
    }
}
