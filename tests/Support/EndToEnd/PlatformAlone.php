<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use Throwable;

/**
 * The platform simulator with no application behind it: a test plays the
 * application, and a scripted endpoint answers the platform what the test
 * told it to. It is how each rule of appendix B is shown one case at a time.
 */
final class PlatformAlone implements Stage
{
    /** How many times the stage is built when another process takes a port it was handed. */
    protected const TRIES = 3;

    public readonly SimulatedPlatform $platform;

    public readonly ScriptedEndpoint $endpoint;

    public readonly PlayedApplication $application;

    protected Workspace $workspace;

    public function __construct()
    {
        [$this->workspace, $this->platform, $this->endpoint] = self::start(self::TRIES);
        $this->application = new PlayedApplication($this->platform);

        try {
            $this->reset();
        } catch (Throwable $notReset) {
            $this->tearDown();

            throw $notReset;
        }
    }

    /**
     * @param int $tries how many times the stage may still be built
     * @return array{Workspace, SimulatedPlatform, ScriptedEndpoint}
     * @throws Throwable what kept the stage from being built: nothing of it is left
     */
    protected static function start(int $tries): array
    {
        $workspace = new Workspace();
        $platform = $endpoint = null;

        try {
            $platform = new SimulatedPlatform($workspace->directory);
            $endpoint = new ScriptedEndpoint($workspace->directory);
            $platform->awaitUp();
            $endpoint->awaitUp();

            return [$workspace, $platform, $endpoint];
        } catch (Throwable $notStarted) {
            // Nobody holds a stage that was never built: what it started ends here.
            $endpoint?->stop();
            $platform?->stop();
            $workspace->remove();

            if ($notStarted instanceof PortTaken && $tries > 1) {
                return self::start($tries - 1);
            }

            throw $notStarted;
        }
    }

    /**
     * Begin again: the platform has seen nothing, and the endpoint answers the plain success of the protocol.
     */
    public function reset(): void
    {
        $this->platform->reset();
        $this->endpoint->forget();
        $this->platform->tunnel(ScriptedEndpoint::PUBLIC_URL, $this->endpoint->url());
    }

    public function logs(): string
    {
        return "--- platform ---\n{$this->platform->log()}\n--- endpoint ---\n{$this->endpoint->log()}\n";
    }

    public function tearDown(): void
    {
        $this->endpoint->stop();
        $this->platform->stop();
        $this->workspace->remove();
    }
}
