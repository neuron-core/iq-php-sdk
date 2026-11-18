<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndpointFlows;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\UniqueIdGenerator;
use NeuronCore\IQ\IQ;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function max;
use function time;

/**
 * IQ for one run, as appendix B of the design asks it to behave
 * (rules 8 to 11 and the aborted start of rule 13, with rules 6 and 7 as far
 * as they need them). It reaches the application only through deliver() and
 * wake() of the fake platform, and decides only from what the fake recorded
 * and from the answers to its own inspects. It sends every message twice.
 *
 * Not played: a start that stays open (rule 12), the other re-sends of rule
 * 13, which follow an answer of the endpoint, the periodic read of a wait
 * with no timer (rule 14) and a retry policy that gives up.
 */
final class PlatformSimulator
{
    /** Seconds a start can be used for: 600 to 3600. */
    protected const START_LIFETIME = 900;

    /** Rule 8: seconds before a timer that came too early by the application's clock is woken again: 1 to 60. */
    protected const EARLY_TIMER_RETRY = 30;

    /** Rule 10: seconds a wake may stay without an announcement before it is sent again. */
    protected const WAKE_SILENCE = 900;

    public readonly string $runId;

    /** @var array<string, mixed> the start the platform issued, as it is sent every time */
    protected array $start = [];

    /** Whether the last report of the start said it was aborted before any copy of it was claimed. */
    protected bool $startAborted = false;

    /** @var array<string, mixed>|null the run, as the newest snapshot that was read shows it */
    protected ?array $run = null;

    /** Whether that snapshot is the answer to an inspect made after the last report. */
    protected bool $shown = false;

    protected bool $closed = false;

    /** How many reports the platform has planned from. */
    protected int $heard = 0;

    /** @var array<string, int> the instant a wake was sent at, by what it was sent for */
    protected array $sent = [];

    /** @var string[] the invocation IDs of the silent announcements the platform has asked about */
    protected array $asked = [];

    /** When the platform has something to do next, by the application's clock; null when nothing is planned. */
    protected ?int $resumesAt = null;

    /**
     * @param int $version the current version of the definition, which a sync would have published
     * @param int $clockAhead seconds the clock of the platform runs ahead of the application's
     */
    public function __construct(
        protected ReadablePlatform $platform,
        protected IQ $endpoint,
        protected string $definition,
        protected string $workflowId,
        int $version = 1,
        protected int $clockAhead = 0,
    ) {
        // Rule 1: a run ID is never used twice.
        $this->runId = $version . '-' . UniqueIdGenerator::generateUUID();
    }

    /**
     * A trigger fires: one start (rule 4), with the use-by time it keeps for the life of its run ID.
     */
    public function start(): void
    {
        $this->start = [
            'verb' => 'start',
            'invocationId' => UniqueIdGenerator::generateId('inv_'),
            'definition' => $this->definition,
            'workflowId' => $this->workflowId,
            'runId' => $this->runId,
            'expiresAt' => $this->clock() + self::START_LIFETIME,
        ];

        $this->send($this->start);
    }

    /**
     * Plan from what the application reported since the last time, and send what is due now.
     */
    public function advance(): Move
    {
        $this->hear();

        if ($this->closed) {
            return Move::Closed;
        }

        // Rule 13: only what can be sent again, and a start never after its use-by time.
        if ($this->startAborted && $this->run === null && $this->clock() < $this->start['expiresAt']) {
            $this->startAborted = false;
            $this->send($this->start);

            return Move::SentStartAgain;
        }

        $silenceEnds = $this->silenceEnds();
        if ($silenceEnds !== null) {
            if ($this->clock() < $silenceEnds) {
                return $this->waitUntil($silenceEnds);
            }

            $this->ask();
        }

        $due = $this->due();
        if ($due === null || $this->clock() < $due) {
            return $this->waitUntil($due);
        }

        return $this->wake();
    }

    /**
     * @return int|null the instant advance() has something to do again, by the application's clock; null when
     *                  the platform waits for the application
     */
    public function resumesAt(): ?int
    {
        return $this->resumesAt;
    }

    protected function hear(): void
    {
        $reports = $this->about($this->platform->reports());

        foreach (array_slice($reports, $this->heard) as $report) {
            $this->shown = false;
            // Rule 7: a report closes the run named by its runId when its outcome is completed.
            $this->closed = $this->closed || ($report['runId'] === $this->runId && $report['outcome'] === 'completed');

            if ($report['runId'] === $this->runId && $report['verb'] === 'start') {
                // Rule 13: in an aborted report a "claimed" that is null is sent again like a false.
                $this->startAborted = $report['outcome'] === 'aborted' && $report['claimed'] !== true;
            }

            // Rule 6: a report whose snapshot was not read carries none.
            if ($report['snapshotRead']) {
                $this->learn($report['run']);
            }
        }

        $this->heard = count($reports);
    }

    /**
     * Rule 6: a snapshot that names the run replaces what the platform holds when its attempt is higher, or
     * equal with a later lease deadline or a status that left "running".
     *
     * @param array<string, mixed>|null $run
     */
    protected function learn(?array $run): void
    {
        if (($run['runId'] ?? null) !== $this->runId) {
            return;
        }

        $held = $this->run;
        $leftRunning = $held !== null && $held['status'] === 'running' && $run['status'] !== 'running';

        if (
            $held === null
            || $run['attempt'] > $held['attempt']
            || ($run['attempt'] === $held['attempt'] && ($run['leaseExpiresAt'] > $held['leaseExpiresAt'] || $leftRunning))
        ) {
            $this->run = $run;
        }
    }

    /**
     * Rule 10: after an announcement, no report within its lease means ask. A run the platform was told is
     * running is left alone until the lease deadline it was shown.
     *
     * @return int|null the instant the platform asks at; null when every announcement has its report
     */
    protected function silenceEnds(): ?int
    {
        $silent = $this->silentAnnouncements();

        return $silent === []
            ? null
            : max($silent[0]['at'] + $silent[0]['leaseSeconds'], $this->run['leaseExpiresAt'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>> the announcements no report has followed and the platform has
     *                                          not asked about, in order
     */
    protected function silentAnnouncements(): array
    {
        $reports = array_count_values(array_column($this->about($this->platform->reports()), 'invocationId'));
        $silent = [];

        foreach ($this->about($this->platform->announcements()) as $announcement) {
            $segment = $announcement['invocationId'];
            $reports[$segment] = ($reports[$segment] ?? 0) - 1;

            if ($reports[$segment] < 0 && !in_array($segment, $this->asked, true)) {
                $silent[] = $announcement;
            }
        }

        return $silent;
    }

    protected function ask(): void
    {
        $this->asked = [...$this->asked, ...array_column($this->silentAnnouncements(), 'invocationId')];

        $answer = $this->send([
            'verb' => 'inspect',
            'definition' => $this->definition,
            'workflowId' => $this->workflowId,
            'runId' => $this->runId,
        ]);

        $this->learn($answer->json()['run']);
        $this->shown = true;
    }

    /**
     * @return int|null the instant a wake of the run is due at; null when the platform has none to send
     */
    protected function due(): ?int
    {
        $run = $this->run;
        $sent = $run === null ? null : ($this->sent[$this->order()] ?? null);

        if ($sent !== null) {
            // Rule 8: the same wait and attempt back means the timer came too early by the application's clock.
            // Rule 9: a failure counts once per run ID and attempt, so its wake is only sent again after a silence.
            return $sent + ($run['status'] === 'suspended' ? self::EARLY_TIMER_RETRY : self::WAKE_SILENCE);
        }

        return match ($run['status'] ?? null) {
            // Rule 8: one timer per wait.
            'suspended' => $run['wait']['wakeAt'],
            // Rule 9: a retry is a wake. The policy of this platform retries at once.
            'failed' => $this->clock(),
            // Rule 11: never before the lease deadline, and never a run that runs with no lease.
            'running' => $run['leaseExpiresAt'],
            default => null,
        };
    }

    /**
     * Rule 2: a wake carries the run ID and the attempt the application itself reported or returned from an inspect.
     */
    protected function wake(): Move
    {
        $run = (array) $this->run;
        $this->sent[$this->order()] = $this->clock();

        if ($this->shown) {
            // FakePlatform::wake() fences from the last report: a fence an inspect showed is delivered as it is.
            $this->send([
                'verb' => 'wake',
                'invocationId' => UniqueIdGenerator::generateId('inv_'),
                'definition' => $this->definition,
                'workflowId' => $this->workflowId,
                'runId' => $run['runId'],
                'attempt' => $run['attempt'],
            ]);
        } else {
            $this->platform->wake($this->endpoint, $this->workflowId);
            $this->platform->wake($this->endpoint, $this->workflowId);
        }

        return match ($run['status']) {
            'suspended' => Move::WokeTimer,
            'failed' => Move::Retried,
            default => Move::RetriedDead,
        };
    }

    /**
     * What a wake of the run is sent for now: one timer per wait, one retry per failure or death of an attempt.
     */
    protected function order(): string
    {
        return implode('#', [$this->run['status'] ?? '', $this->run['attempt'] ?? '', $this->run['wait']['interruptId'] ?? '']);
    }

    /**
     * Every message is delivered twice, as by a platform that did not see the first answer.
     *
     * @param array<string, mixed> $message
     */
    protected function send(array $message): HttpResponse
    {
        $this->platform->deliver($this->endpoint, $message, $this->clock());

        return $this->platform->deliver($this->endpoint, $message, $this->clock());
    }

    protected function waitUntil(?int $instant): Move
    {
        $this->resumesAt = $instant === null ? null : $instant - $this->clockAhead;

        return Move::Waits;
    }

    /**
     * @param array<int, array<string, mixed>> $notices
     * @return array<int, array<string, mixed>> the ones about the workflow ID of the run
     */
    protected function about(array $notices): array
    {
        return array_values(array_filter($notices, fn (array $notice): bool => $notice['workflowId'] === $this->workflowId));
    }

    protected function clock(): int
    {
        return time() + $this->clockAhead;
    }
}
