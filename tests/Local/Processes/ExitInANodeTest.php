<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Processes;

use NeuronCore\IQ\Tests\Support\Processes\ScriptProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function is_executable;

use const PHP_BINARY;

/**
 * An exit() reached while the SDK executes a segment ends the process as it
 * ends one that calls Neuron directly: normally, with the run failed by
 * Neuron as the process lets go of it. On PHP 8.1 the process dies of a
 * segmentation fault instead when anything delegates to Neuron's generator
 * with "yield from", so the SDK must not. Each case is a real process, on the
 * running PHP and on a PHP 8.1 when one is installed.
 */
class ExitInANodeTest extends TestCase
{
    protected const PHP_8_1 = '/usr/bin/php8.1';

    #[DataProvider('waysToExecuteASegmentOnEachPhp')]
    public function test_an_exit_reached_while_a_segment_executes_ends_the_process_normally_with_the_run_failed(string $php, string $how): void
    {
        if (!is_executable($php)) {
            self::markTestSkipped("No PHP binary at {$php}.");
        }

        $process = ScriptProcess::run($php, 'exit-in-a-node.php', $how);

        self::assertSame(
            ['shutdown: the queue was handed announce; the run is failed', 0, null],
            [$process->printed, $process->exitCode, $process->signal],
        );
    }

    /**
     * @return array<string, array{string, string}> the PHP binary, and how the script executes its segment
     */
    public static function waysToExecuteASegmentOnEachPhp(): array
    {
        $rows = [];

        foreach (['the running PHP' => PHP_BINARY, 'PHP 8.1' => self::PHP_8_1] as $label => $php) {
            $rows["run(), on {$label}"] = [$php, 'run'];
            $rows["events(), on {$label}"] = [$php, 'events'];
            $rows["events() encoded as example (b) does, on {$label}"] = [$php, 'encoded'];
        }

        return $rows;
    }
}
