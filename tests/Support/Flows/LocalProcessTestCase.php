<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use PHPUnit\Framework\TestCase;

use function array_count_values;
use function array_map;
use function extension_loaded;
use function ksort;

/**
 * A test whose requests and workers each run in a process of their own, on
 * one SQLite file. Whatever those processes sent the platform must respect
 * the protocol.
 */
abstract class LocalProcessTestCase extends TestCase
{
    protected LocalProcessBench $bench;

    protected function setUp(): void
    {
        foreach (['pcntl', 'posix', 'pdo_sqlite'] as $extension) {
            if (!extension_loaded($extension)) {
                self::markTestSkipped("Application processes on SQLite need the {$extension} extension.");
            }
        }

        $this->bench = new LocalProcessBench();
    }

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function tearDown(): void
    {
        if (isset($this->bench)) {
            $this->bench->cleanUp();
        }
    }

    /**
     * How the calls of some processes ended.
     *
     * @param array<int, array<string, mixed>> $results what ApplicationCall::perform() answered in each process
     * @return array<string, int> how many calls ended as each "returned: status", "reported: outcome[: code]" or
     *                            "thrown: reason or class", sorted by that text
     */
    protected function tally(array $results): array
    {
        $endings = array_count_values(array_map($this->ending(...), $results));
        ksort($endings);

        return $endings;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function ending(array $result): string
    {
        return match (true) {
            isset($result['returned']) => 'returned: ' . $result['returned']['status'],
            isset($result['reported']) => 'reported: ' . $result['reported']['outcome']
                . ($result['reported']['code'] === null ? '' : ': ' . $result['reported']['code']),
            default => 'thrown: ' . ($result['thrown']['reason'] ?? $result['thrown']['class']),
        };
    }
}
