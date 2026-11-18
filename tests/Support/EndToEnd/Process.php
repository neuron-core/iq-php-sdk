<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use RuntimeException;

use function array_diff_key;
use function file_get_contents;
use function getenv;
use function implode;
use function is_file;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function usleep;

use const SIGKILL;
use const SIGTERM;

/**
 * One process a test started, with a log file of its own that holds what it
 * wrote to its standard output and to its standard error. It is stopped when
 * the test says so and, at the latest, when this object goes away.
 */
final class Process
{
    /** Seconds a process is given to end by itself after it was asked to. */
    protected const GRACE = 2.0;

    /** A server of PHP that forks workers would answer two requests at once: every server of a test serves one. */
    protected const NOT_INHERITED = ['PHP_CLI_SERVER_WORKERS' => true];

    /** @var resource */
    protected mixed $process;

    /** @var array{exitCode: int|null, signal: int|null}|null how the process ended; null while it runs */
    protected ?array $ending = null;

    /**
     * @param string[] $command the program and its arguments: no shell stands in between
     * @param array<string, string> $environment added to the environment of the test
     * @param string $logPath where the process writes
     * @throws RuntimeException when the process cannot be started
     */
    public function __construct(protected array $command, array $environment, public readonly string $logPath)
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
            $pipes,
            null,
            $environment + array_diff_key(getenv(), self::NOT_INHERITED),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('A process could not be started: ' . implode(' ', $command));
        }

        $this->process = $process;
    }

    public function __destruct()
    {
        $this->kill();
    }

    public function isRunning(): bool
    {
        return $this->ending() === null;
    }

    /**
     * Wait for the process to end by itself.
     *
     * @return int|null its exit code; null when a signal ended it, or when it still runs after the given time
     */
    public function exitCode(float $seconds = 30.0): ?int
    {
        $deadline = microtime(true) + $seconds;
        while ($this->isRunning() && microtime(true) < $deadline) {
            usleep(5_000);
        }

        return $this->ending()['exitCode'] ?? null;
    }

    /**
     * @return int|null the signal that ended the process; null when it ended by itself, or still runs
     */
    public function endedBy(): ?int
    {
        return $this->ending()['signal'] ?? null;
    }

    /**
     * Ask the process to end, and kill it when it has not after a moment.
     */
    public function stop(): void
    {
        if ($this->isRunning()) {
            proc_terminate($this->process, SIGTERM);
            $this->exitCode(self::GRACE);
        }

        $this->kill();
    }

    /**
     * Kill the process where it stands, as kill -9 does, unless it has already ended.
     */
    public function kill(): void
    {
        if ($this->isRunning()) {
            proc_terminate($this->process, SIGKILL);
            $this->exitCode(self::GRACE);
        }
    }

    /**
     * @return string what the process wrote so far
     */
    public function log(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    /**
     * @return array{exitCode: int|null, signal: int|null}|null
     */
    protected function ending(): ?array
    {
        if ($this->ending !== null) {
            return $this->ending;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            return null;
        }

        // The status of a process that ended can be read once: it is kept.
        $this->ending = $status['signaled']
            ? ['exitCode' => null, 'signal' => $status['termsig']]
            : ['exitCode' => $status['exitcode'], 'signal' => null];
        proc_close($this->process);

        return $this->ending;
    }
}
