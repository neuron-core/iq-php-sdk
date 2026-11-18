<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Streamed;

use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Testing\FakePlatform;
use NeuronCore\IQ\Tests\Stub\Matrix\MidBody;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Flows\FileQueue;
use NeuronCore\IQ\Tests\Support\TestBench;
use PDO;
use RuntimeException;

use function array_map;
use function bin2hex;
use function dirname;
use function fclose;
use function feof;
use function file;
use function fread;
use function fsockopen;
use function fwrite;
use function glob;
use function is_resource;
use function json_decode;
use function mkdir;
use function parse_url;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function str_contains;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * An application on PHP's built-in server whose only route streams a run of
 * "tale" to the browser, for what only a real connection proves: what is
 * left when the browser closes it in the middle of the stream. The server
 * and the test share one SQLite file and one queue file. Call stop() in
 * tearDown().
 */
final class StreamingEndpoint
{
    public const DEFINITION = 'tale';

    public const WORKFLOW_ID = 'thread:42';

    /** Seconds. */
    public const LEASE = 90;

    /** The route that answers at once. The server takes one request at a time: its answer means the previous request has ended. */
    public const IDLE = '/idle';

    protected const ROUTER = '/Stub/Streamed/streaming-endpoint.php';

    public readonly ExecutionLog $executions;

    /** The IQ of a worker of the application, in the process of the test. */
    public readonly IQ $worker;

    /** @var resource */
    protected mixed $process;

    /** @var array<int, resource> */
    protected array $pipes = [];

    protected int $port;

    protected string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/iq-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        touch($this->directory . '/queue');
        touch($this->directory . '/executions');
        self::connect($this->directory)->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        $this->executions = new ExecutionLog($this->directory . '/executions');
        $this->worker = self::application($this->directory);
        $this->port = self::freePort();

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__, 2) . self::ROUTER],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            ['IQ_STREAM_FILES' => $this->directory],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The streaming endpoint could not be started.');
        }

        $this->process = $process;
        $this->waitUntilReachable();
    }

    /**
     * The IQ every process of the application builds: its bootstrap.
     *
     * @param string $directory where the store, the queue and the log of the node bodies are
     */
    public static function application(string $directory): IQ
    {
        $executions = new ExecutionLog($directory . '/executions');

        return (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new FakePlatform()))
            ->setPersistence(static fn (): PersistenceInterface => new DatabasePersistence(self::connect($directory)))
            ->setDispatcher(new FileQueue($directory . '/queue'))
            ->register(
                self::DEFINITION,
                static fn (): Workflow => new TaleWorkflow($executions, false, Ending::Completes, new MidBody()),
                leaseTimeout: self::LEASE,
            );
    }

    /**
     * A browser asks for the stream, and closes the connection as soon as it has read the first word.
     *
     * @return string what it had read by then
     */
    public function leaveAfterTheFirstWord(): string
    {
        $connection = $this->ask('/');
        $read = '';

        while (!str_contains($read, TaleWorkflow::OPENING[0]) && !feof($connection)) {
            $read .= (string) fread($connection, 8192);
        }

        fclose($connection);
        $this->awaitTheEndOfTheRequest();

        return $read;
    }

    /**
     * A browser asks for the stream and reads it until the server closes the connection.
     *
     * @return string everything it read
     */
    public function readToTheEnd(): string
    {
        $connection = $this->ask('/');
        $read = '';

        while (!feof($connection)) {
            $read .= (string) fread($connection, 8192);
        }

        fclose($connection);
        $this->awaitTheEndOfTheRequest();

        return $read;
    }

    /**
     * The run that holds the workflow ID, read without the SDK and told in the words of the protocol.
     *
     * @return array<string, mixed>|null
     */
    public function stored(): ?array
    {
        return StoredRun::of(new DatabasePersistence(self::connect($this->directory)), self::WORKFLOW_ID);
    }

    /**
     * @return Invocation[] the messages that wait in the queue of the application, in order
     */
    public function queued(): array
    {
        return array_map(
            static fn (string $message): Invocation => Invocation::fromArray(json_decode($message, true, flags: JSON_THROW_ON_ERROR)),
            file($this->directory . '/queue', FILE_IGNORE_NEW_LINES) ?: [],
        );
    }

    public function stop(): void
    {
        proc_terminate($this->process);

        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($this->process);
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /**
     * @return resource the connection, once the request was sent
     */
    protected function ask(string $path): mixed
    {
        $connection = fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 2.0);

        if ($connection === false) {
            throw new RuntimeException("The streaming endpoint cannot be reached: {$errorMessage}.");
        }

        fwrite($connection, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        return $connection;
    }

    /**
     * The server notices that a browser has gone only when it next writes to it, and then still has to shut the
     * request down: the answer to another request says that all of this is over.
     */
    protected function awaitTheEndOfTheRequest(): void
    {
        $connection = $this->ask(self::IDLE);

        while (!feof($connection)) {
            fread($connection, 8192);
        }

        fclose($connection);
    }

    /**
     * A port the system just handed out: a random one may belong to another server.
     */
    protected static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port for the streaming endpoint.');
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

        throw new RuntimeException('The streaming endpoint never became reachable.');
    }

    protected static function connect(string $directory): PDO
    {
        $pdo = new PDO('sqlite:' . $directory . '/store.sqlite');
        // The server and the test write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}
