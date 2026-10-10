<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

use function extension_loaded;
use function implode;

/**
 * Everything a test of the whole needs, over real HTTP with nothing faked:
 * the platform simulator and the shop of example (a), each a set of
 * processes of its own, in one temporary directory. Call tearDown() when the
 * test ends.
 */
final class Deployment implements Stage
{
    /** How many times the deployment is built when another process takes a port it was handed. */
    protected const TRIES = 3;

    public readonly SimulatedPlatform $platform;

    public readonly DeployedShop $shop;

    protected Workspace $workspace;

    /**
     * The platform is up and the shop is installed with its web server running; no worker runs yet and no
     * sync was published.
     *
     * @param array<string, string> $environment what the bootstrap of the shop reads, beside its keys
     */
    public function __construct(array $environment = [])
    {
        [$this->workspace, $this->platform, $this->shop] = self::deploy($environment, self::TRIES);
    }

    /**
     * @param array<string, string> $environment
     * @param int $tries how many times the deployment may still be built
     * @return array{Workspace, SimulatedPlatform, DeployedShop}
     * @throws Throwable what kept the deployment from being built: nothing of it is left
     */
    protected static function deploy(array $environment, int $tries): array
    {
        $workspace = new Workspace();
        $platform = $shop = null;

        try {
            // The simulator, the migration of the shop and its web server start together: each is a process of PHP.
            $platform = new SimulatedPlatform($workspace->directory);
            $shop = new DeployedShop($workspace->directory, $platform, $environment);
            $local = $shop->serve();
            $platform->awaitUp();
            $platform->tunnel(DeployedShop::PUBLIC_URL, $local);

            return [$workspace, $platform, $shop];
        } catch (Throwable $notDeployed) {
            // Nobody holds a deployment that was never built: what it started ends here.
            $shop?->stop();
            $platform?->stop();
            $workspace->remove();

            if ($notDeployed instanceof PortTaken && $tries > 1) {
                return self::deploy($environment, $tries - 1);
            }

            throw $notDeployed;
        }
    }

    /**
     * @return string|null what this machine lacks to run a deployment; null when it lacks nothing
     */
    public static function unavailable(): ?string
    {
        foreach (['pdo_sqlite', 'pcntl', 'posix', 'curl'] as $extension) {
            if (!extension_loaded($extension)) {
                return "the {$extension} extension is not loaded";
            }
        }

        return Server::freePort() === null ? 'no port is free on 127.0.0.1' : null;
    }

    /**
     * Wait for something to happen, in real time. Before every look the clock of the platform moves on by one
     * tick, which is the only way its orders and its questions ever leave: a test that does not wait sends none.
     *
     * @template TSeen
     * @param string $what what is awaited, for the failure
     * @param Closure(): (TSeen|null|false) $seen what was awaited, once it is there
     * @return TSeen
     * @throws AssertionFailedError when it did not happen in time
     */
    public function await(string $what, Closure $seen, float $seconds = 20.0): mixed
    {
        return $this->platform->await($what, $seen, $seconds);
    }

    public function logs(): string
    {
        $logs = [];
        foreach (['platform' => $this->platform->log()] + $this->shop->logs() as $name => $log) {
            $logs[] = "--- {$name} ---\n{$log}";
        }

        return implode("\n", $logs);
    }

    public function tearDown(): void
    {
        $this->shop->stop();
        $this->platform->stop();
        $this->workspace->remove();
    }
}
