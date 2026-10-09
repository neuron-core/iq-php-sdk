<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Processes;

use function fclose;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function stream_get_contents;
use function usleep;

/**
 * One of the plain scripts of this directory, run to its end in a PHP process
 * of its own: what it printed, and how the process ended.
 */
final class ScriptProcess
{
    /**
     * @param string $printed what the script wrote to its standard output
     * @param int|null $exitCode null when a signal ended the process
     * @param int|null $signal the signal that ended the process; null when it ended by itself
     */
    protected function __construct(
        public readonly string $printed,
        public readonly ?int $exitCode,
        public readonly ?int $signal,
    ) {
    }

    /**
     * @param string $php the PHP binary that runs the script
     * @param string $script a file of this directory
     */
    public static function run(string $php, string $script, string ...$arguments): self
    {
        // No shell in between: the status read is the one of the script itself.
        $process = proc_open([$php, __DIR__ . '/' . $script, ...$arguments], [1 => ['pipe', 'w']], $pipes);

        // The output ends when the process does, however it ends.
        $printed = (string) stream_get_contents($pipes[1]);

        $status = proc_get_status($process);
        while ($status['running']) {
            usleep(1_000);
            $status = proc_get_status($process);
        }

        fclose($pipes[1]);
        proc_close($process);

        return $status['signaled'] ? new self($printed, null, $status['termsig']) : new self($printed, $status['exitcode'], null);
    }
}
