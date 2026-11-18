<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use InvalidArgumentException;

use function array_key_exists;
use function in_array;
use function is_float;
use function is_int;
use function max;
use function microtime;
use function min;

/**
 * The durations of appendix B that the platform owns, its own back-offs and
 * its retry policy, in seconds. A test may shrink every one of them, to a
 * fraction of a second for all but the lifetime of a start: none is checked
 * by the SDK, except that lifetime, of which the endpoint refuses more than
 * 3600 seconds. The platform itself gives a start 600 seconds at least,
 * unless a test shrinks that bound too.
 *
 * What a test cannot shrink here belongs to the SDK or to the application:
 * the 3 second timeout of an announcement or a report (15 for a sync), the
 * signature window of 300 seconds, the Retry-After of 5 seconds, the hour a
 * ledger entry outlives its start, and the lease: the silence after an
 * announcement lasts the leaseSeconds the application registered.
 */
final class Settings
{
    public const DEFAULTS = [
        // How long the endpoint may take to answer.
        'endpointTimeout' => 10,
        // Rule 1: the use-by time of a start, 600 to 3600 seconds ahead of the moment it is first sent.
        'startLifetime' => 900,
        // The lower bound of that lifetime. Only a test shrinks it, to see a start expire without waiting ten minutes.
        'shortestStartLifetime' => 600,
        // Rule 12: a start still open this long after its use-by time is asked about.
        'startSilence' => 300,
        // Rule 10: a wake answered 202 and not announced within this time is asked about.
        'wakeSilence' => 900,
        // Rule 8: a timer the application found too early is woken again after 1 to 60 seconds.
        'earlyTimerRetry' => 30,
        // Rule 14: a wait with no timer is read daily at first, then less often, at least every 7 days.
        'periodicRead' => 86400,
        'periodicReadCap' => 604800,
        // Rule 6: a snapshot that was not read is asked for after 30 seconds, doubling to 600.
        'unreadFollowUp' => 30,
        'unreadFollowUpCap' => 600,
        // Rule 18: a report whose "at" is further than this from the platform's clock raises an alert.
        'reportClockTolerance' => 300,
        // Rule 12: after this long past its use-by time, the ledger entry of a start may have been dropped.
        'ledgerRetention' => 3600,
        // Rule 13 and the Durations table: the back-off of a re-send, doubling to its cap, and how many times a
        // message is sent again after a 500, a 3xx or a timeout. An inspect or an abandon is then asked again at the cap.
        'resendDelay' => 5,
        'resendDelayCap' => 300,
        'uncertainResends' => 5,
        // After a 401, how often the endpoint is pinged until a ping succeeds.
        'pingRetry' => 60,
        // Rule 17: how many messages one tick sends at most, so that timers due together are spread out.
        'deliveriesPerTick' => 20,
        // The retry policy, rules 9 and 11: how many failures of a run are followed by a wake, and after how long.
        'retryAttempts' => 3,
        'retryDelay' => 30,
        // How far the clock of the platform runs ahead of the true time: its timers then fire early by the
        // application's clock (rule 8). A test that plays the application moves the clock on with it, where it
        // would otherwise wait for a duration to pass.
        'clockAhead' => 0,
        // Not durations but faults of the platform itself: how many of the next announcements and of the next
        // reports it fails to record, each answered 503.
        'announcementsLost' => 0,
        'reportsLost' => 0,
    ];

    /** Rule 1: never more than 3600 seconds after the start is first sent. */
    protected const LONGEST_START_LIFETIME = 3600;

    /** The counts, and the one duration that becomes an instant of the protocol, which is in whole seconds. */
    protected const WHOLE = [
        'startLifetime', 'shortestStartLifetime', 'uncertainResends', 'deliveriesPerTick', 'retryAttempts', 'announcementsLost', 'reportsLost',
    ];

    public function __construct(protected Database $database)
    {
    }

    /**
     * The clock of the platform, with its fraction: what the platform heard and did within one second keeps
     * its order.
     */
    public function now(): float
    {
        return microtime(true) + $this->get('clockAhead');
    }

    /**
     * @return int|float seconds, where a test may have set a fraction
     */
    public function get(string $name): int|float
    {
        return $this->all()[$name];
    }

    /**
     * @param string $name one of the settings that only take a whole number
     */
    public function whole(string $name): int
    {
        return (int) $this->get($name);
    }

    /**
     * @return array<string, int|float>
     */
    public function all(): array
    {
        $changed = [];
        foreach ($this->database->rows('SELECT name, value FROM settings') as $setting) {
            $changed[$setting['name']] = $setting['value'];
        }

        return $changed + self::DEFAULTS;
    }

    /**
     * @param array<array-key, mixed> $settings name => seconds, or a count
     * @throws InvalidArgumentException when a name is unknown or a value cannot be used; nothing is changed
     */
    public function change(array $settings): void
    {
        foreach ($settings as $name => $value) {
            if (!array_key_exists($name, self::DEFAULTS)) {
                throw new InvalidArgumentException("'{$name}' is not a setting of the simulator.");
            }

            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                throw new InvalidArgumentException("'{$name}' takes a number of 0 or more.");
            }

            if (in_array($name, self::WHOLE, true) && !is_int($value)) {
                throw new InvalidArgumentException("'{$name}' takes a whole number.");
            }
        }

        ['startLifetime' => $lifetime, 'shortestStartLifetime' => $shortest] = $settings + $this->all();

        if ($lifetime > self::LONGEST_START_LIFETIME) {
            throw new InvalidArgumentException('A start lives ' . self::LONGEST_START_LIFETIME . ' seconds at most.');
        }

        if ($lifetime < $shortest) {
            throw new InvalidArgumentException("A start lives {$shortest} seconds at least.");
        }

        foreach ($settings as $name => $value) {
            $this->database->write('INSERT OR REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
        }
    }

    /**
     * The delay before a message is sent again: doubling with every send, up to the cap.
     */
    public function backOff(int $sends): int|float
    {
        $delay = $this->get('resendDelay');
        $longest = $this->longestBackOff();

        for ($sent = 1; $sent < $sends && $delay < $longest; $sent++) {
            $delay *= 2;
        }

        return min($delay, $longest);
    }

    /**
     * The cap of the back-off: the pace at which a question is asked again once its bounded re-sends ran out.
     */
    public function longestBackOff(): int|float
    {
        return max($this->get('resendDelayCap'), $this->get('resendDelay'));
    }
}
