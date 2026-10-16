<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Units;

use RuntimeException;

use function array_map;
use function dirname;
use function fclose;
use function file;
use function fsockopen;
use function is_resource;
use function file_put_contents;
use function glob;
use function json_decode;
use function json_encode;
use function mkdir;
use function parse_url;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function stream_socket_get_name;
use function stream_socket_server;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * A platform on PHP's built-in server, for what only a real HTTP exchange
 * proves: what the SDK's default client sends, and what it does with the
 * answer. It serves the datasets it is given. Call stop() in tearDown().
 */
final class LocalPlatform
{
    /** The prefix of a platform URL that has moved: every request is answered with a redirect to the real path. */
    public const MOVED = '/moved';

    /** The prefix of a platform URL that never answers. */
    public const SILENT = '/silent';

    protected const ROUTER = '/Stub/Units/platform-server.php';

    /** @var resource */
    protected mixed $process;

    /** @var array<int, resource> */
    protected array $pipes = [];

    protected int $port;

    protected string $requestLog;

    /** Where the datasets are kept, one file for each slug. */
    protected string $datasets;

    public function __construct()
    {
        $this->port = self::freePort();
        $this->requestLog = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-');
        $this->datasets = sys_get_temp_dir() . '/' . uniqid('neuron-cloud-datasets-');
        mkdir($this->datasets);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__, 2) . self::ROUTER],
            // The silent route waits on the input, so it must be a pipe nobody writes to.
            [0 => ['pipe', 'r'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            ['NEURON_CLOUD_REQUEST_LOG' => $this->requestLog, 'NEURON_CLOUD_DATASETS' => $this->datasets],
        );

        if (!is_resource($process)) {
            throw new RuntimeException('The local platform could not be started.');
        }

        $this->process = $process;
        $this->waitUntilReachable();
    }

    /**
     * @param string $prefix self::MOVED, self::SILENT, or none for a platform that records
     */
    public function url(string $prefix = ''): string
    {
        return "http://127.0.0.1:{$this->port}{$prefix}";
    }

    /**
     * Keep a dataset under a slug, and answer it as the platform does.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function keepDataset(string $slug, array $items): void
    {
        file_put_contents(
            "{$this->datasets}/{$slug}.json",
            json_encode(['slug' => $slug, 'name' => $slug, 'description' => null, 'items' => $items], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<int, array{method: string, path: string, headers: array<string, string>, body: string}> every
     *         request received, in order
     */
    public function requests(): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->requestLog, FILE_IGNORE_NEW_LINES) ?: [],
        );
    }

    public function stop(): void
    {
        proc_terminate($this->process);

        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($this->process);
        unlink($this->requestLog);

        foreach (glob("{$this->datasets}/*.json") ?: [] as $dataset) {
            unlink($dataset);
        }

        rmdir($this->datasets);
    }

    /**
     * A port the system just handed out: a random one may belong to another server.
     */
    protected static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port for the local platform.');
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

        throw new RuntimeException('The local platform never became reachable.');
    }
}
