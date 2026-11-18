<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\StreamedChat;

use RuntimeException;

use function array_merge;
use function explode;
use function fclose;
use function fsockopen;
use function getenv;
use function http_build_query;
use function is_executable;
use function is_resource;
use function json_encode;
use function parse_url;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_get_contents;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PATH_SEPARATOR;
use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * The chat application on PHP's built-in server, and the browser that calls
 * it: curl, which can read a whole response or hang up after a time. Call
 * stop() in tearDown().
 */
final class ChatServer
{
    /** What the model of the server answers every message with: long enough to hang up in the middle of it. */
    public const ANSWER = 'Your order left our warehouse this morning and the courier will deliver it tomorrow before noon.';

    protected const FRONT_CONTROLLER = __DIR__ . '/front-controller.php';

    /** @var resource */
    protected mixed $process;

    protected int $port;

    /**
     * @throws RuntimeException when the server cannot be started
     */
    public function __construct(protected ChatHome $home)
    {
        $this->port = self::freePort() ?? throw new RuntimeException('No free port for the chat server.');
        $this->start();
    }

    /**
     * Whether the process of the server has ended, given a moment to end when its connections are already
     * closed. A PHP-FPM worker survives a request that dies of a fatal error; PHP 8.1's built-in server
     * ends with a request that runs out of memory.
     */
    public function hasEnded(): bool
    {
        for ($try = 1; $try <= 20; $try++) {
            if (!proc_get_status($this->process)['running']) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * Start the server again, on the same port and the same home, once its process has ended.
     *
     * @throws RuntimeException when the server cannot be started
     */
    public function restart(): void
    {
        proc_close($this->process);
        $this->start();
    }

    /**
     * @throws RuntimeException when the server cannot be started
     */
    protected function start(): void
    {
        $home = $this->home;
        $process = proc_open(
            // Unbuffered, whatever the php.ini of the machine says: a frame leaves when it is echoed.
            [PHP_BINARY, '-d', 'output_buffering=0', '-d', 'display_errors=0', '-S', "127.0.0.1:{$this->port}", self::FRONT_CONTROLLER],
            // The server logs every request: into a file, so that a pipe nobody reads never fills up.
            [1 => ['file', "{$home->directory}/server.log", 'a'], 2 => ['file', "{$home->directory}/server.log", 'a']],
            $pipes,
            null,
            ['STREAMED_CHAT_HOME' => $home->directory],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The chat server could not be started.');
        }

        $this->process = $process;
        $this->waitUntilReachable();
    }

    /**
     * @return string|null what this machine lacks to run the server and its browser; null when it lacks nothing
     */
    public static function unavailable(): ?string
    {
        return match (true) {
            self::curl() === null => 'curl was not found in the PATH',
            self::freePort() === null => 'no free port on 127.0.0.1',
            default => null,
        };
    }

    /**
     * Send the endpoint one request and read its answer.
     *
     * @param array<string, mixed> $request what ChatEndpoint takes
     * @param array<string, int> $scene the query parameters of the front controller
     * @param int|null $hangUpAfter seconds after which the browser closes the connection; null: it reads to the end
     * @return array{status: int, body: string} the body as far as it was received
     */
    public function post(array $request, array $scene = [], ?int $hangUpAfter = null): array
    {
        $curl = proc_open(
            array_merge(
                [(string) self::curl(), '--silent', '--no-buffer', '--noproxy', '*', '--write-out', "\n%{http_code}"],
                $hangUpAfter === null ? [] : ['--max-time', (string) $hangUpAfter],
                ['--header', 'Content-Type: application/json', '--data', json_encode($request, JSON_THROW_ON_ERROR)],
                ["http://127.0.0.1:{$this->port}/chat?" . http_build_query($scene)],
            ),
            [1 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($curl)) {
            throw new RuntimeException('curl could not be started.');
        }

        $printed = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($curl);

        $statusAt = (int) strrpos($printed, "\n");

        return ['status' => (int) substr($printed, $statusAt + 1), 'body' => substr($printed, 0, $statusAt)];
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    protected static function curl(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (is_executable("{$directory}/curl")) {
                return "{$directory}/curl";
            }
        }

        return null;
    }

    /**
     * A port the system just handed out: a random one may belong to another server.
     */
    protected static function freePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return null;
        }

        $port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    protected function waitUntilReachable(): void
    {
        for ($try = 1; $try <= 100; $try++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        $this->stop();

        throw new RuntimeException('The chat server never became reachable.');
    }
}
