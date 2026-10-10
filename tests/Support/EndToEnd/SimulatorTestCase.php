<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use function array_map;
use function bin2hex;
use function max;
use function microtime;
use function random_bytes;

/**
 * A test of one rule of appendix B of the design on the platform simulator
 * alone: the test plays the application and scripts the answers of its
 * endpoint. Every test meets a platform that has seen nothing but the sync
 * that published version 1 of the one definition. The platform sends
 * nothing until the test ticks it, so what it sends at a tick is exactly
 * what the rule says is due. No test waits for a duration of the platform
 * to pass: the test moves the clock of the platform on, by the whole length
 * of the duration, so that "not yet" and "now" are far apart.
 */
abstract class SimulatorTestCase extends EndToEndTestCase
{
    protected const DEFINITION = PlayedApplication::DEFINITION;

    protected const WORKFLOW_ID = 'order:42';

    /** Seconds the platform gives a start, from the 600 to 3600 of the Durations table of appendix B. */
    protected const START_LIFETIME = 900;

    /** The start silence of that table: a start still open this long after its use-by time is asked about. */
    protected const START_SILENCE = 300;

    /** The wake silence: a wake answered 202 and not announced within this time is asked about. */
    protected const WAKE_SILENCE = 900;

    /** The follow-up of a snapshot that was not read: after this long, doubling to 600. */
    protected const FOLLOW_UP = 30;

    /** The periodic read of a wait without a timer: daily at first. */
    protected const PERIODIC_READ = 86400;

    /** What the simulator waits before it wakes again a timer that came too early, from the 1 to 60 of the table. */
    protected const EARLY_TIMER_RETRY = 30;

    /** The delay of its retry policy, which the design leaves to the platform. */
    protected const RETRY_DELAY = 30;

    /** The back-off of the Durations table before a message is sent again, doubling with every try. */
    protected const RESEND_DELAY = 5;

    /** The cap of that back-off, and the pace of a question whose bounded re-sends ran out. */
    protected const RESEND_DELAY_CAP = 300;

    protected static ?PlatformAlone $stage = null;

    /** Seconds the test has moved the clock of the platform on. */
    protected int|float $movedOn = 0;

    protected SimulatedPlatform $platform;

    protected ScriptedEndpoint $endpoint;

    protected PlayedApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        // One simulator for the tests of a class, which each one finds empty: starting it costs more than a test.
        self::$stage ??= self::forTheClass(new PlatformAlone());
        self::$stage->reset();

        $this->platform = self::$stage->platform;
        $this->endpoint = self::$stage->endpoint;
        $this->application = self::$stage->application;

        self::assertSame(200, $this->application->sync()['status'], 'The sync every test begins with was refused.');
    }

    /**
     * Whatever a test made the application or its endpoint say, the platform must have kept to the protocol.
     */
    protected function assertPostConditions(): void
    {
        $wire = $this->platform->wire();

        $wire->assertThePlatformRespectedTheProtocol();
        $wire->assertSignedWith($this->platform->signingKey);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        self::$stage = null;
    }

    /**
     * Move the clock of the platform on by one tick.
     *
     * @return string[] what it sent: "<action> <reason>: <status of the answer>" for each message, in order
     */
    protected function tick(): array
    {
        return array_map(
            static fn (array $sent): string => "{$sent['action']} {$sent['reason']}: {$sent['status']}",
            $this->platform->tick(),
        );
    }

    /**
     * A trigger fires and the start of the platform leaves, answered 202 unless the test scripted otherwise.
     *
     * @return string the run ID the platform minted
     */
    protected function startByThePlatform(string $workflowId = self::WORKFLOW_ID): string
    {
        $runId = $this->platform->fire(self::DEFINITION, $workflowId)['runId'];
        $this->platform->tick();

        return $runId;
    }

    /**
     * The application reports a run the platform has never heard of, as the report of its start says it is now.
     *
     * @param array<string, mixed>|null $run as Shown writes it for the run ID given; null: suspended at attempt 1 on a wait with no timer
     * @return string the run ID
     */
    protected function runOfTheApplication(?string $runId = null, ?array $run = null, string $workflowId = self::WORKFLOW_ID): string
    {
        $runId ??= $run['runId'] ?? self::runId();
        $run ??= Shown::suspended($runId, 1, null, eventName: 'payment.received');

        $outcome = $run['status'] === 'failed' ? 'failed' : 'suspended';
        self::assertSame(200, $this->application->report($runId, $workflowId, $outcome, $run, [
            'executedAttempt' => $run['attempt'],
            'errorClass' => $outcome === 'failed' ? 'App\\ShippingFailed' : null,
            'claimed' => false,
        ]));

        return $runId;
    }

    /**
     * @return array<string, mixed> what the platform holds for a run now
     */
    protected function held(string $runId): array
    {
        return $this->platform->run($runId);
    }

    /**
     * @return array<int, array{string, string|null}> the kind and the run of every alert the platform raised, in order
     */
    protected function alerts(): array
    {
        return array_map(static fn (array $alert): array => [$alert['kind'], $alert['runId']], $this->platform->alerts());
    }

    /**
     * @return array<int, array<string, mixed>> the fields of every message of one kind the platform sent, oldest first
     */
    protected function sent(string $message): array
    {
        return array_map(static fn (array $sent): array => $sent['fields'], $this->platform->wire()->of($message));
    }

    /**
     * Time passes for the platform: its clock moves on. The clock of the application, which the test plays
     * with time(), stays where it is.
     */
    protected function timePasses(int|float $seconds): void
    {
        $this->movedOn += $seconds;
        $this->platform->configure(['clockAhead' => $this->movedOn]);
    }

    /**
     * Time passes for the platform until its clock shows an instant.
     *
     * @param int|float $instant of the clock of the platform, as it reports its own timers
     */
    protected function theClockReaches(int|float $instant): void
    {
        $this->timePasses(max(0, $instant - microtime(true) - $this->movedOn));
    }

    /**
     * @param string $reason why the platform plans it: the reason of a timer, as the record of the run shows it
     * @return int|float|null when the platform plans to act on a run for a reason; null when it has no such plan
     */
    protected function timerOf(string $runId, string $reason): int|float|null
    {
        foreach ($this->held($runId)['timers'] as $timer) {
            if ($timer['reason'] === $reason) {
                return $timer['at'];
            }
        }

        return null;
    }

    /**
     * A run ID as the SDK mints one for version 1.
     */
    protected static function runId(): string
    {
        return '1-' . bin2hex(random_bytes(8));
    }
}
