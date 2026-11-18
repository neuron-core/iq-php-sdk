<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndpointFlows;

use Closure;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Testing\FakePlatform;
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
use function stream_select;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const SIGKILL;

/**
 * A queue worker in a process of its own, executing one message, as the
 * test that spawned it holds it. The test is also the wire between the
 * worker and the fake platform: it must serve the worker until it ends.
 */
final class Worker
{
    protected const SCRIPT = __DIR__ . '/worker.php';

    /** Microseconds between two looks at a worker that says nothing. */
    protected const PATIENCE = 10_000;

    /** @var resource */
    protected mixed $process;

    /** @var resource */
    protected mixed $input;

    /** @var resource */
    protected mixed $output;

    protected bool $ended = false;

    /**
     * @param array<string, mixed> $settings what Shop::bootstrap() builds the IQ of the worker from
     */
    public function __construct(array $settings, Invocation $message, protected FakePlatform $platform)
    {
        // No shell in between: the process the test may kill is the worker itself.
        $this->process = proc_open(
            [PHP_BINARY, self::SCRIPT, json_encode(['settings' => $settings, 'message' => $message->toArray()], JSON_THROW_ON_ERROR)],
            [['pipe', 'r'], ['pipe', 'w']],
            $pipes,
        );
        [$this->input, $this->output] = $pipes;
    }

    /**
     * Let the worker execute its message to the end: until invoke() has returned its report.
     */
    public function finish(): void
    {
        $printed = $this->serveUntil(static fn (): bool => false);
        $this->end();

        Assert::assertIsArray(json_decode($printed, true)['report'] ?? null, 'A worker ended without the report of its message: ' . $printed);
    }

    /**
     * Kill the worker where it stands, as soon as something has happened.
     *
     * @param Closure(): bool $happened
     * @return int|null the signal that ended the process; null when it had ended by itself
     */
    public function killOnce(Closure $happened): ?int
    {
        $this->serveUntil($happened);

        return $this->kill();
    }

    /**
     * Kill the process where it stands, unless it has already ended.
     *
     * @return int|null the signal that ended the process; null when it had ended by itself
     */
    public function kill(): ?int
    {
        if ($this->ended) {
            return null;
        }

        proc_terminate($this->process, SIGKILL);

        return $this->end();
    }

    /**
     * Answer every request of the worker with what the platform says, until
     * the worker prints something else or the test has seen enough.
     *
     * @param Closure(): bool $enough
     * @return string the line the worker printed in the end; empty when it printed none
     */
    protected function serveUntil(Closure $enough): string
    {
        while (!$enough()) {
            $readable = [$this->output];
            $writable = $failed = null;

            if (stream_select($readable, $writable, $failed, 0, self::PATIENCE) === 0) {
                continue;
            }

            $printed = (string) fgets($this->output);
            $request = json_decode($printed, true)['request'] ?? null;

            if ($request === null) {
                // The report of the worker, or nothing at all when it died.
                return $printed;
            }

            $answer = $this->answerOfThePlatform(new HttpRequest(
                HttpMethod::POST,
                $request['uri'],
                $request['headers'],
                $request['body'],
                $request['timeout'],
            ));
            fwrite($this->input, json_encode(['status' => $answer->statusCode, 'body' => $answer->body], JSON_THROW_ON_ERROR) . "\n");
        }

        return '';
    }

    protected function answerOfThePlatform(HttpRequest $request): HttpResponse
    {
        try {
            return $this->platform->request($request);
        } catch (HttpException $refused) {
            return $refused->response ?? throw $refused;
        }
    }

    /**
     * @return int|null the signal that ended the process; null when it ended by itself
     */
    protected function end(): ?int
    {
        $status = proc_get_status($this->process);
        while ($status['running']) {
            usleep(1_000);
            $status = proc_get_status($this->process);
        }

        fclose($this->input);
        fclose($this->output);
        proc_close($this->process);
        $this->ended = true;

        return $status['signaled'] ? $status['termsig'] : null;
    }
}
