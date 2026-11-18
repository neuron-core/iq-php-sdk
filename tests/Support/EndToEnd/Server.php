<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use RuntimeException;

use function array_slice;
use function explode;
use function fclose;
use function file;
use function file_get_contents;
use function implode;
use function in_array;
use function intdiv;
use function is_file;
use function is_string;
use function microtime;
use function parse_url;
use function preg_grep;
use function preg_split;
use function random_int;
use function sprintf;
use function sscanf;
use function str_contains;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function substr;
use function trim;
use function usleep;

use const FILE_IGNORE_NEW_LINES;
use const PHP_BINARY;
use const PREG_GREP_INVERT;
use const PHP_URL_PORT;

/**
 * PHP's built-in server on a free port of this machine, serving one request
 * at a time: a front controller, or the scripts of a document root.
 */
final class Server
{
    /** Seconds: a server that a killed test run left behind ends by itself. */
    protected const LIFETIME = 600;

    /** The alarm outlives the exec, which turns this very process into the server. */
    protected const BOUNDED_LIFE = 'pcntl_alarm((int) $argv[1]); pcntl_exec(PHP_BINARY, array_slice($argv, 2));';

    protected const CONNECTION_NOISE = '/ (Accepted|Closing|Closed without sending a request.*)$/';

    /** Seconds a server is given to listen. */
    protected const PATIENCE = 10.0;

    /** What a server of PHP writes when the port it was given is not free any more. */
    protected const PORT_TAKEN = 'Failed to listen on';

    /** Where Linux says which ports it gives to sockets that ask for none: the lowest, then the highest. */
    protected const PORTS_OF_THE_SYSTEM = '/proc/sys/net/ipv4/ip_local_port_range';

    /** Where Linux lists the TCP sockets of the machine: the local address, the remote one and the state of each. */
    protected const SOCKETS_OF_THE_SYSTEM = '/proc/net/tcp';

    /** The states of a connection a server still holds: established, and closed by its client alone. */
    protected const HELD = ['01', '08'];

    /** @var int[] the ports handed out last: the system can name one again while its server has not bound it yet */
    protected static array $handedOut = [];

    public readonly string $url;

    protected Process $process;

    /** How much the log held before this server wrote to it: a server started again shares the log of the one before. */
    protected int $loggedBefore;

    /**
     * The process is started and nothing is waited for: awaitListening() does that, so that the servers of
     * one stage come up together.
     *
     * @param string[] $serves what "php -S <address>" is given after the address: a front controller, or "-t" and a document root
     * @param array<string, string> $environment
     * @param int|null $port the port to listen on; null: one the system hands out
     * @throws RuntimeException when no port is free, or the process cannot be started
     */
    public function __construct(array $serves, array $environment, string $logPath, ?int $port = null)
    {
        $port ??= self::freePort() ?? throw new RuntimeException('No free port on 127.0.0.1.');
        $this->url = "http://127.0.0.1:{$port}";
        $this->loggedBefore = is_file($logPath) ? strlen((string) file_get_contents($logPath)) : 0;

        $this->process = new Process(
            [
                PHP_BINARY, '-r', self::BOUNDED_LIFE, '--', (string) self::LIFETIME,
                // Errors go to the log of the server, never into an answer.
                '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', "127.0.0.1:{$port}", ...$serves,
            ],
            $environment,
            $logPath,
        );
    }

    /**
     * A port for a server to bind: one of those the system keeps for its own sockets, and never one that was
     * handed out a moment ago, which the servers of one stage, started together, would both try to bind.
     *
     * Where the system says which ports those are, an odd one is taken at random and is not bound here first:
     * a port that was bound and closed a moment ago can still count as taken when its server binds it (one
     * stage in nine, measured on a busy machine under WSL), and the system gives outgoing connections the
     * even ones first. The server itself finds a port that is taken, and its stage is built again.
     */
    public static function freePort(): ?int
    {
        $own = @file_get_contents(self::PORTS_OF_THE_SYSTEM);
        $range = is_string($own) ? sscanf($own, '%d %d') : null;

        do {
            $port = isset($range[0], $range[1])
                ? 2 * random_int(intdiv($range[0], 2), intdiv($range[1] - 1, 2)) + 1
                : self::portTheSystemHandsOut();
        } while ($port !== null && in_array($port, self::$handedOut, true));

        if ($port !== null) {
            self::$handedOut = array_slice([...self::$handedOut, $port], -8);
        }

        return $port;
    }

    /**
     * @return int|null a port the system had free a moment ago; null when it has none
     */
    protected static function portTheSystemHandsOut(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return null;
        }

        $port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    public function stop(): void
    {
        $this->process->stop();
    }

    /**
     * @return string what the server wrote, without the lines it writes for every connection it takes and closes
     */
    public function log(): string
    {
        return implode("\n", preg_grep(self::CONNECTION_NOISE, explode("\n", $this->process->log()), PREG_GREP_INVERT) ?: []);
    }

    /**
     * @return bool|null whether the system lists a connection of a client to this server: one the client closed
     *                   counts until the server closes it too, or is told that it was reset. Null where the
     *                   system keeps no such list
     */
    public function holdsAConnection(): ?bool
    {
        $sockets = @file(self::SOCKETS_OF_THE_SYSTEM, FILE_IGNORE_NEW_LINES);
        if ($sockets === false) {
            return null;
        }

        $address = sprintf('0100007F:%04X', (int) parse_url($this->url, PHP_URL_PORT));

        foreach ($sockets as $socket) {
            $fields = preg_split('/\s+/', trim($socket)) ?: [];

            if (($fields[1] ?? null) === $address && in_array($fields[3] ?? null, self::HELD, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wait until this very process listens. Its own log says so: a connection that succeeds would also reach
     * whoever else took the port between the moment the system handed it out and the moment the server bound it.
     *
     * @throws PortTaken when another process bound the port first
     * @throws RuntimeException when the server did not come up for another reason: it is stopped
     */
    public function awaitListening(): void
    {
        $deadline = microtime(true) + self::PATIENCE;

        while ($this->process->isRunning() && microtime(true) < $deadline) {
            if (str_contains(substr($this->process->log(), $this->loggedBefore), "({$this->url}) started")) {
                return;
            }

            usleep(1_000);
        }

        $this->stop();
        $written = substr($this->process->log(), $this->loggedBefore);

        throw str_contains($written, self::PORT_TAKEN)
            ? new PortTaken("Another process took the port of {$this->url}: {$written}")
            : new RuntimeException("The server of {$this->url} did not come up: {$written}");
    }
}
