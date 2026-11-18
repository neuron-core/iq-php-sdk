<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

use function base64_encode;
use function file_get_contents;
use function json_decode;
use function random_bytes;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;

/**
 * The platform simulator as a test holds it: a server process with a SQLite
 * file as its only memory, a second one as the tap of the wire, and its
 * operator API. Nothing is read from those files directly, except to prove
 * what they do not hold.
 */
final class SimulatedPlatform
{
    public const API_KEY = 'key-of-the-shop-for-the-simulated-platform';

    protected const FRONT_CONTROLLER = 'Simulator/server.php';

    /** The key the platform signs with, and the endpoint of the application verifies with. */
    public readonly string $signingKey;

    public readonly string $databasePath;

    protected string $wireTapPath;

    protected string $logPath;

    /** The port the simulator listens on, also after an outage. */
    protected int $port;

    /** Null while the platform is down. */
    protected ?Server $server;

    /** @var array<string, string> public origin => local origin */
    protected array $tunnels = [];

    /**
     * The simulator is started and not waited for: awaitUp() does that, once whatever else belongs to the
     * stage was started too.
     *
     * @param string $directory an existing directory, for the database and the log of the simulator
     * @throws RuntimeException when no port is free
     */
    public function __construct(string $directory)
    {
        $this->signingKey = 'whsec_' . base64_encode(random_bytes(24));
        $this->databasePath = "{$directory}/platform.sqlite";
        $this->wireTapPath = "{$directory}/platform-wire.sqlite";
        $this->logPath = "{$directory}/platform.log";
        $this->port = Server::freePort() ?? throw new RuntimeException('No free port on 127.0.0.1.');
        $this->server = $this->serve();
    }

    /**
     * @throws RuntimeException when the simulator does not come up
     */
    public function awaitUp(): void
    {
        $this->server?->awaitListening();
    }

    /**
     * Start the simulator again after an outage: on the same port, with the memory it had.
     */
    public function start(): void
    {
        $this->server ??= $this->serve();
        $this->awaitUp();
    }

    /**
     * The platform is down: nothing answers on its port until start() is called.
     */
    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * Make the platform forget everything: its runs, its manifest, its settings, its tunnels and the wire.
     */
    public function reset(): void
    {
        $this->operator('POST', 'reset');
        $this->tunnels = [];
    }

    /**
     * @param array<string, int|float> $settings the durations, in seconds, and the retry policy to change: see Simulator\Settings
     * @return array<string, int|float> every setting, as it is now
     */
    public function configure(array $settings): array
    {
        return $this->operator('PUT', 'settings', $settings);
    }

    /**
     * Say which local address stands behind a public https origin, beside the tunnels already dug.
     */
    public function tunnel(string $publicOrigin, string $localOrigin): void
    {
        $this->tunnels[$publicOrigin] = $localOrigin;
        $this->operator('PUT', 'tunnels', $this->tunnels);
    }

    /**
     * Rule 16: an owner consents to the endpoint of the environment moving to another host.
     */
    public function confirmEndpointMove(string $host): void
    {
        $this->operator('POST', 'endpoint-moves', ['host' => $host]);
    }

    /**
     * A trigger fires: the platform issues one start, which leaves with the next tick.
     *
     * @param string|null $firing names the firing: the same firing never issues a second start
     * @return array<string, mixed> what the platform holds for the run
     */
    public function fire(string $definition, string $workflowId, ?string $firing = null): array
    {
        return $this->operator('POST', 'starts', ['definition' => $definition, 'workflowId' => $workflowId] + ($firing === null ? [] : ['firing' => $firing]));
    }

    /**
     * An operator asks for a run to be abandoned: the abandon leaves with the next tick.
     *
     * @return array<string, mixed> what the platform holds for the run
     */
    public function abandon(string $runId): array
    {
        return $this->operator('POST', 'abandons', ['runId' => $runId]);
    }

    /**
     * Move the clock of the platform on: it sends whatever is due now.
     *
     * @return array<int, array<string, mixed>> what it sent: the action, its reason, the run and the status of the answer
     */
    public function tick(): array
    {
        return $this->operator('POST', 'tick')['sent'];
    }

    /**
     * Wait for something to happen, in real time. Before every look the clock of the platform moves on by one
     * tick, which is the only way its orders and its questions ever leave.
     *
     * @template TSeen
     * @param string $what what is awaited, for the failure
     * @param Closure(): (TSeen|null|false) $seen what was awaited, once it is there
     * @return TSeen
     * @throws AssertionFailedError when it did not happen in time
     */
    public function await(string $what, Closure $seen, float $seconds = 20.0): mixed
    {
        return Wait::until($what, function () use ($seen): mixed {
            $this->tick();

            return $seen();
        }, $seconds);
    }

    /**
     * @return array<string, mixed> what the platform holds for a run: see Simulator\Operator::describe()
     */
    public function run(string $runId): array
    {
        return $this->operator('GET', 'runs/' . rawurlencode($runId));
    }

    /**
     * @return array<string, mixed>|null what the platform holds for a run; null while it has heard of no run with that run ID
     */
    public function find(string $runId): ?array
    {
        $answer = Http::send('GET', $this->url() . '/_operator/runs/' . rawurlencode($runId));

        return $answer['status'] === 404 ? null : json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array<string, mixed>> every run the platform holds for a workflow ID, oldest first
     */
    public function runsOf(string $workflowId): array
    {
        return $this->operator('GET', 'runs?workflowId=' . rawurlencode($workflowId));
    }

    /**
     * @return array<int, array<string, mixed>> rule 18: what the platform told a person, in order
     */
    public function alerts(): array
    {
        return $this->operator('GET', 'alerts');
    }

    /**
     * Every message the platform received or sent, with its answer, as each crossed the wire.
     */
    public function wire(): WireLog
    {
        return new WireLog($this->operator('GET', 'wire'));
    }

    /**
     * @return array<string, mixed> the endpoint and the definitions the last sync published
     */
    public function manifest(): array
    {
        return $this->operator('GET', 'manifest');
    }

    /**
     * @return string the bytes of the only memory of the platform: what it holds apart from the tap of the wire
     */
    public function memory(): string
    {
        return (string) file_get_contents($this->databasePath);
    }

    /**
     * @return string the bytes of the file the simulator keeps every message that crossed the wire in
     */
    public function wireTap(): string
    {
        return (string) file_get_contents($this->wireTapPath);
    }

    public function log(): string
    {
        return (string) $this->server?->log();
    }

    protected function serve(): Server
    {
        return new Server(
            [__DIR__ . '/../' . self::FRONT_CONTROLLER],
            [
                'SIMULATOR_DATABASE' => $this->databasePath,
                'SIMULATOR_WIRE_TAP' => $this->wireTapPath,
                'SIMULATOR_API_KEYS' => self::API_KEY,
                'SIMULATOR_SIGNING_KEYS' => $this->signingKey,
            ],
            $this->logPath,
            $this->port,
        );
    }

    protected function operator(string $method, string $resource, mixed $request = null): mixed
    {
        return Http::ask($method, $this->url() . '/_operator/' . $resource, $request);
    }
}
