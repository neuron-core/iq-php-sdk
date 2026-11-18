<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Concurrency;

use PHPUnit\Framework\Assert;

use function fclose;
use function fgets;
use function fwrite;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_get_contents;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const SIGKILL;

/**
 * A queue worker in a process of its own, as the test that spawned it holds it.
 */
final class WorkerProcess
{
    protected const SCRIPT = __DIR__ . '/worker.php';

    protected const READY = "ready\n";

    /** @var resource */
    protected mixed $process;

    /** @var resource */
    protected mixed $input;

    /** @var resource */
    protected mixed $output;

    /** What the process printed after it said it was ready; null while it has not ended. */
    protected ?string $printed = null;

    /** The signal that ended the process; null when it ended by itself. */
    protected ?int $signal = null;

    /**
     * @param array<string, mixed> $job what worker.php rebuilds its IQ and its invocation from
     */
    public function __construct(array $job)
    {
        // No shell in between: the process the test may kill is the worker itself.
        $this->process = proc_open(
            [PHP_BINARY, self::SCRIPT, json_encode($job, JSON_THROW_ON_ERROR)],
            [['pipe', 'r'], ['pipe', 'w']],
            $pipes,
        );
        [$this->input, $this->output] = $pipes;
    }

    /**
     * Wait until the worker has built its IQ and only waits for its order.
     */
    public function awaitReady(): void
    {
        $said = fgets($this->output);

        if ($said !== self::READY) {
            Assert::fail('A worker did not come up: ' . $said . $this->end());
        }
    }

    /**
     * Order the worker to give its invocation to invoke() at an instant, so that several can act together.
     *
     * @param float $instant Unix time with microseconds
     */
    public function invokeAt(float $instant): void
    {
        fwrite($this->input, sprintf("%.6F\n", $instant));
    }

    /**
     * Kill the process where it stands, unless it has already ended.
     */
    public function kill(): void
    {
        if ($this->printed === null) {
            proc_terminate($this->process, SIGKILL);
            $this->end();
        }
    }

    /**
     * Wait for the worker to end.
     *
     * @return array<string, mixed> the report invoke() returned
     */
    public function report(): array
    {
        $printed = $this->end();
        $report = json_decode($printed, true);

        Assert::assertIsArray($report, 'A worker ended without the report of its invocation: ' . $printed);

        return $report;
    }

    /**
     * Wait for the worker to end.
     *
     * @return int|null the signal that ended it; null when it ended by itself
     */
    public function killedBy(): ?int
    {
        $this->end();

        return $this->signal;
    }

    /**
     * @return string what the process printed after it said it was ready
     */
    protected function end(): string
    {
        if ($this->printed !== null) {
            return $this->printed;
        }

        // The output ends when the process does, however it ends.
        $this->printed = (string) stream_get_contents($this->output);

        $status = proc_get_status($this->process);
        while ($status['running']) {
            usleep(1_000);
            $status = proc_get_status($this->process);
        }
        $this->signal = $status['signaled'] ? $status['termsig'] : null;

        fclose($this->input);
        fclose($this->output);
        proc_close($this->process);

        return $this->printed;
    }
}
