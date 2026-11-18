<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use PHPUnit\Framework\Assert;

use function fclose;
use function fgets;
use function fwrite;
use function json_decode;
use function json_encode;
use function microtime;
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
 * One request or one queue worker of an application, in a process of its
 * own, as the test that spawned it holds it.
 */
final class ApplicationProcess
{
    protected const SCRIPT = __DIR__ . '/process.php';

    protected const READY = "ready\n";

    /** @var resource */
    protected mixed $process;

    /** @var resource */
    protected mixed $input;

    /** @var resource */
    protected mixed $output;

    /** What the process printed after it said it was ready; null while it has not ended. */
    protected ?string $printed = null;

    /**
     * @param array<string, string> $files what LocalProcessBench::application() builds the IQ of the process from
     * @param array<string, mixed> $call what ApplicationCall::perform() does in the process
     */
    public function __construct(array $files, array $call)
    {
        // No shell in between: the process the test may kill is the PHP process itself.
        $this->process = proc_open(
            [PHP_BINARY, self::SCRIPT, json_encode(['files' => $files, 'call' => $call], JSON_THROW_ON_ERROR)],
            [['pipe', 'r'], ['pipe', 'w']],
            $pipes,
        );
        [$this->input, $this->output] = $pipes;
    }

    /**
     * Wait until the process has built its IQ and only waits for its order.
     */
    public function awaitReady(): void
    {
        $said = fgets($this->output);

        if ($said !== self::READY) {
            Assert::fail('A process did not come up: ' . $said . $this->end());
        }
    }

    /**
     * Order the process to make its call at an instant, so that several can act together.
     *
     * @param float $instant Unix time with microseconds
     */
    public function actAt(float $instant): void
    {
        fwrite($this->input, sprintf("%.6F\n", $instant));
    }

    /**
     * Wait for the process to come up and order it to make its call now.
     */
    public function act(): void
    {
        $this->awaitReady();
        $this->actAt(microtime(true));
    }

    /**
     * Wait for the process to end.
     *
     * @return array<string, mixed> what ApplicationCall::perform() answered in the process
     */
    public function result(): array
    {
        $printed = $this->end();
        $result = json_decode($printed, true);

        Assert::assertIsArray($result, 'A process ended without the result of its call: ' . $printed);

        return $result;
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
     * @return string what the process printed after it said it was ready
     */
    protected function end(): string
    {
        if ($this->printed !== null) {
            return $this->printed;
        }

        // The output ends when the process does, however it ends.
        $this->printed = (string) stream_get_contents($this->output);

        while (proc_get_status($this->process)['running']) {
            usleep(1_000);
        }

        fclose($this->input);
        fclose($this->output);
        proc_close($this->process);

        return $this->printed;
    }
}
