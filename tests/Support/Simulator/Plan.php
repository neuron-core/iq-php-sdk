<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function array_filter;
use function array_key_first;
use function array_values;
use function asort;
use function count;
use function max;
use function min;

/**
 * What the platform will do next about a run, and when: its timers. They are
 * not stored. Every look computes them again from what the platform holds,
 * which is how it plans from a snapshot that was read (rule 6) and from
 * nothing else.
 */
final class Plan
{
    public const ABANDON = 'abandon';

    public const START = 'start';

    public const INSPECT = 'inspect';

    public const WAKE = 'wake';

    public function __construct(
        protected Settings $settings,
        protected Manifest $manifest,
        protected Runs $runs,
        protected Orders $orders,
        protected Segments $segments,
    ) {
    }

    /**
     * @param array<string, mixed> $run
     * @return array<int, array{action: string, reason: string, at: int|float, held: string|null}> what is planned, the
     *         first to be done first; "at" is 0 for what is done at once, and "held" says why a step is not taken
     */
    public function of(array $run): array
    {
        if ($run['closed_as'] !== null) {
            // Rule 7: a snapshot of the very second that closed the run named it. Only the store can say more.
            return array_values(array_filter([$this->inspect($run)]));
        }

        return array_values(array_filter([$this->abandon($run), $this->start($run), $this->inspect($run), $this->wake($run)]));
    }

    /**
     * @param array<string, mixed> $run
     * @return array{action: string, reason: string, at: int|float, held: string|null}|null the one step to take now:
     *         the first that is due and that nothing holds; null when the run needs nothing yet
     */
    public function next(array $run, float $now): ?array
    {
        foreach ($this->of($run) as $step) {
            if ($step['at'] <= $now && $step['held'] === null) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Rule 5: only when an operator asked, and then at once, whatever the platform asked about the run before.
     * An abandon that was answered itself waits for its own back-off.
     *
     * @param array<string, mixed> $run
     * @return array{action: string, reason: string, at: int|float, held: string|null}|null
     */
    protected function abandon(array $run): ?array
    {
        return $run['abandon'] === 'requested'
            ? ['action' => self::ABANDON, 'reason' => 'operator', 'at' => $run['abandon_after'] ?? 0, 'held' => $this->heldAfter($run, $run['abandon_status'])]
            : null;
    }

    /**
     * The start the platform issued: sent once, and again only by rule 13, never after its use-by time.
     *
     * @param array<string, mixed> $run
     * @return array{action: string, reason: string, at: int|float, held: string|null}|null
     */
    protected function start(array $run): ?array
    {
        $start = $this->orders->startOf($run['run_id']);
        if ($start === null || $start['ended'] !== null) {
            return null;
        }

        $at = $start['due_at'] ?? $this->startSentAgainAt($run, $start);
        // Its use-by time is fixed when it is first sent (rule 1): a start that never left has none yet.
        if ($at === null || ($start['expires_at'] !== null && $at >= $start['expires_at'])) {
            return null;
        }

        return ['action' => self::START, 'reason' => $start['sends'] === 0 ? 'trigger' : 'resend', 'at' => $at, 'held' => $this->strandedOn($run)];
    }

    /**
     * Rule 13: an aborted report whose "claimed" is not true, or an observed report or an inspect answer that
     * shows the start unclaimed and no run under its run ID.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $start accepted with a 202
     */
    protected function startSentAgainAt(array $run, array $start): int|float|null
    {
        if ($start['accepted_at'] === null || $run['seen_at'] !== null) {
            return null;
        }

        $report = $this->segments->find($start['invocation_id']);
        $wasAborted = $report !== null && $report['outcome'] === 'aborted' && $report['claimed'] !== 1;

        $shownUnclaimed = array_filter([
            $wasAborted && $report['settled_at'] >= $start['accepted_at'] ? $report['settled_at'] : null,
            $run['unclaimed_at'] >= $start['accepted_at'] ? $run['unclaimed_at'] : null,
        ]);

        return $shownUnclaimed === [] ? null : min($shownUnclaimed) + $this->settings->backOff($start['sends']);
    }

    /**
     * The next question, for the earliest of the reasons the platform has to ask.
     *
     * @param array<string, mixed> $run
     * @return array{action: string, reason: string, at: int|float, held: string|null}|null
     */
    protected function inspect(array $run): ?array
    {
        $reasons = array_filter($this->reasonsToAsk($run), static fn (int|float|null $at): bool => $at !== null);

        if ($reasons === []) {
            return null;
        }

        asort($reasons);
        $reason = array_key_first($reasons);

        return [
            'action' => self::INSPECT,
            'reason' => $reason,
            'at' => max($reasons[$reason], $run['ask_after'] ?? 0),
            'held' => $this->heldAfter($run, $run['ask_status']),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, int|float|null> when each reason to ask comes due; null for a reason the platform does not have
     */
    protected function reasonsToAsk(array $run): array
    {
        if ($run['closed_as'] !== null && $run['undecided_until'] === null) {
            return [];
        }

        if ($run['unread_at'] !== null) {
            // Rule 6: the store could not be read. Whatever the platform wants to know of the run, it asks when
            // the follow-up is due, and not before.
            return ['unread_snapshot' => $run['unread_at']];
        }

        if ($run['closed_as'] !== null) {
            return ['same_second' => $run['undecided_until']];
        }

        return [
            // Appendix A: a 409 to an abandon that shows no run is followed by an inspect.
            'abandon_left_open' => $run['abandon'] === 'asking' ? 0 : null,
            'same_second' => $run['undecided_until'],
            'segment_silence' => $this->segmentSilenceEnds($run),
            'never_seen' => $this->neverSeenSince($run),
            'wake_silence' => $this->wakeSilenceEnds($run),
            'unanswered_start' => $this->unansweredStartSilenceEnds($run),
            'lease_passed' => $this->leasePasses($run),
            'start_silence' => $this->startSilenceEnds($run),
            'periodic_read' => $this->nextPeriodicRead($run),
        ];
    }

    /**
     * Rule 10: after an announcement, no report within leaseSeconds. The question is answered by an inspect
     * made since, or by a snapshot the application read when the lease had run out: an older snapshot says
     * nothing of the segment, whenever it arrives.
     *
     * @param array<string, mixed> $run
     */
    protected function segmentSilenceEnds(array $run): int|float|null
    {
        $silent = $this->segments->silentOf($run['run_id']);
        if ($silent === null) {
            return null;
        }

        $ends = $silent['announced_at'] + $silent['lease_seconds'];
        $answeredAt = max($run['asked_at'] ?? 0, $run['seen_at'] ?? 0, $run['absent_at'] ?? 0);

        return $answeredAt < $ends ? $ends : null;
    }

    /**
     * Rule 7: a run the application started, known from its announcement alone. When leaseSeconds have passed
     * and no snapshot has named it, the platform asks, whatever its reports said.
     *
     * A run known from a report alone has no announcement to count from, and rule 12 cannot be applied to a
     * start whose use-by time the platform was never told: such a run is asked about once the start silence has
     * passed since its last report. A start that was rejected as expired before it was announced is the case.
     *
     * @param array<string, mixed> $run
     */
    protected function neverSeenSince(array $run): int|float|null
    {
        if ($run['origin'] !== 'application' || $run['seen_at'] !== null) {
            return null;
        }

        $announced = $this->segments->lastAnnouncedOf($run['run_id']);
        $reported = $this->segments->lastReportedOf($run['run_id']);
        $since = match (true) {
            $announced !== null => $announced['announced_at'] + $announced['lease_seconds'],
            $reported !== null => $reported['reported_at'] + $this->settings->get('startSilence'),
            default => null,
        };

        return $since !== null && ($run['asked_at'] ?? 0) < $since ? $since : null;
    }

    /**
     * Rule 10: after a 202 for a wake, no announcement within the wake silence, and no snapshot read since.
     * Rule 13: a wake whose bounded re-sends ran out counts as accepted when the last of them was sent.
     *
     * @param array<string, mixed> $run
     */
    protected function wakeSilenceEnds(array $run): int|float|null
    {
        foreach ($this->orders->of($run['run_id']) as $order) {
            $waitsToBeHeard = $order['verb'] === Orders::WAKE && $order['ended'] === null && $order['accepted_at'] !== null;
            $ends = $order['accepted_at'] + $this->settings->get('wakeSilence');

            if ($waitsToBeHeard && !$this->wasHeardOf($order) && ($run['read_at'] ?? 0) < $ends) {
                return $ends;
            }
        }

        return null;
    }

    /**
     * Rule 13 counts a start whose bounded re-sends ran out as accepted and silent, and rule 12 asks about it
     * when no copy can be sent any more. While its use-by time still allows one, the simulator asks about it
     * after the wake silence too, as about a wake nothing was heard of: an answer that shows it unclaimed has
     * it sent again. A start the endpoint answered 202 is asked about by rule 12 alone.
     *
     * @param array<string, mixed> $run
     */
    protected function unansweredStartSilenceEnds(array $run): int|float|null
    {
        $start = $this->orders->startOf($run['run_id']);

        if ($start === null || $start['accepted_at'] === null || $start['status'] === 202 || $run['seen_at'] !== null) {
            return null;
        }

        $ends = $start['accepted_at'] + $this->settings->get('wakeSilence');

        return $ends < $start['expires_at'] && ($run['read_at'] ?? 0) < $ends ? $ends : null;
    }

    /**
     * Rule 11: a run shown running under a lease is asked about when that lease has passed by the
     * application's clock. Nothing is planned once a snapshot showed the lease passed: the attempt was counted.
     *
     * @param array<string, mixed> $run
     */
    protected function leasePasses(array $run): int|float|null
    {
        return $run['status'] === 'running' ? $run['lease_check_at'] : null;
    }

    /**
     * Rule 12: a start still open at its use-by time plus the start silence, asked about once.
     *
     * @param array<string, mixed> $run
     */
    protected function startSilenceEnds(array $run): int|float|null
    {
        $start = $this->orders->startOf($run['run_id']);

        return $start !== null && $start['expires_at'] !== null && $run['seen_at'] === null && $run['start_asked'] === 0
            ? $start['expires_at'] + $this->settings->get('startSilence')
            : null;
    }

    /**
     * Rule 14: a wait that has no timer is read daily at first, then less often. Rule 11: so is a run that is
     * running with no lease.
     *
     * @param array<string, mixed> $run
     */
    protected function nextPeriodicRead(array $run): int|float|null
    {
        $waitsWithoutTimer = $run['status'] === 'suspended' && $run['wake_at'] === null;
        $runsWithoutLease = $run['status'] === 'running' && $run['lease_expires_at'] === null;

        if (!$waitsWithoutTimer && !$runsWithoutLease) {
            return null;
        }

        $interval = $this->settings->get('periodicRead');
        $longest = max($this->settings->get('periodicReadCap'), $interval);
        for ($read = 0; $read < $run['reads'] && $interval < $longest; $read++) {
            $interval *= 2;
        }

        return ($run['read_at'] ?? $run['opened_at']) + min($interval, $longest);
    }

    /**
     * The one wake the snapshot asks for: the timer of the wait (rule 8), or the retry of a failed or dead
     * attempt (rules 9 and 11). Its fence is the attempt the snapshot shows (rule 2).
     *
     * @param array<string, mixed> $run
     * @return array{action: string, reason: string, at: int|float, held: string|null}|null
     */
    protected function wake(array $run): ?array
    {
        [$purpose, $due] = $this->wakeWanted($run);
        if ($purpose === null || $run['undecided_until'] !== null) {
            return null;
        }

        $order = $this->orders->wakeFor($run['run_id'], $run['attempt'], $purpose);
        $at = match (true) {
            $order === null => $due,
            $order['ended'] !== null => null,
            default => $order['due_at'] ?? $this->wakeSentAgainAt($run, $order),
        };

        return $at === null ? null : ['action' => self::WAKE, 'reason' => $purpose, 'at' => $at, 'held' => $this->strandedOn($run)];
    }

    /**
     * @param array<string, mixed> $run
     * @return array{string|null, int|float|null} what the wake is for and when it is due; nulls when the run needs none
     */
    protected function wakeWanted(array $run): array
    {
        if ($run['status'] === 'suspended' && $run['wake_at'] !== null) {
            return ["timer:{$run['wait_id']}", $run['wake_at']];
        }

        // Rule 11: never a run whose lease still runs, and never one that runs with no lease.
        $isDead = $run['status'] === 'running' && $run['lease_expires_at'] !== null && $run['lease_check_at'] === null;
        $failure = $run['status'] === 'failed' || $isDead ? $this->failureOf($run) : null;
        $policyGaveUp = count($this->runs->failures($run['run_id'])) > $this->settings->whole('retryAttempts');

        return $failure === null || $policyGaveUp
            ? [null, null]
            : ['retry', $failure['countedAt'] + $this->settings->get('retryDelay')];
    }

    /**
     * A wake that was answered 202 is sent again once a snapshot, read after the wake was proven to have
     * changed nothing, still asks for it. Its own report came back: with the same wait and attempt, which is
     * "too early by the application's clock", and it is tried again after the early retry (rule 8); or with
     * anything else, such as "aborted", and it is sent again with the back-off of rule 13. Or it stayed silent
     * and the platform had to ask (rule 10): it is sent at once.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $order
     */
    protected function wakeSentAgainAt(array $run, array $order): int|float|null
    {
        if ($order['accepted_at'] === null) {
            return null;
        }

        $segment = $this->segments->find($order['invocation_id']);
        $wasReported = $segment !== null && $segment['settled_at'] >= $order['accepted_at'];
        $wasAnnounced = $segment !== null && $segment['heard_at'] >= $order['accepted_at'];

        $provenIdleAt = match (true) {
            $wasReported => $segment['settled_at'],
            $wasAnnounced => $segment['announced_at'] + $segment['lease_seconds'],
            default => $order['accepted_at'] + $this->settings->get('wakeSilence'),
        };

        if (($run['read_at'] ?? 0) < $provenIdleAt) {
            return null;
        }

        if (!$wasReported) {
            // Rule 13: a copy that got no usable answer is never followed by another sooner than the back-off allows.
            $notBefore = $order['status'] === 202 ? 0 : $order['accepted_at'] + $this->settings->backOff($order['uncertain']);

            return max($run['read_at'], $notBefore);
        }

        // Rule 8: only the report of the wake itself says "too early", never what the platform still holds.
        return $run['read_at'] + ($segment['too_early'] === 1 ? $this->settings->get('earlyTimerRetry') : $this->settings->backOff($order['sends']));
    }

    /**
     * @param array<string, mixed> $order a wake that was answered 202, or that counts as accepted
     */
    protected function wasHeardOf(array $order): bool
    {
        $segment = $this->segments->find($order['invocation_id']);

        return $segment !== null && max($segment['heard_at'] ?? 0, $segment['settled_at'] ?? 0) >= $order['accepted_at'];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>|null the failure counted for the attempt the snapshot shows
     */
    protected function failureOf(array $run): ?array
    {
        foreach ($this->runs->failures($run['run_id']) as $failure) {
            if ($failure['attempt'] === $run['attempt']) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * The status table of appendix A: a question answered 404 is asked again while the version of the run is
     * in the manifest. An inspect or an abandon invokes nothing, so nothing else holds one back.
     *
     * @param array<string, mixed> $run
     * @param int|null $status the status of the last answer to the question, when it was of no use
     */
    protected function heldAfter(array $run, ?int $status): ?string
    {
        return $status === 404 ? $this->strandedOn($run) : null;
    }

    /**
     * Rule 16: a version that left the manifest is never invoked.
     *
     * @param array<string, mixed> $run
     */
    protected function strandedOn(array $run): ?string
    {
        $version = $this->manifest->versionOf($run['definition'], $run['run_id']);

        return $this->manifest->holds($run['definition'], $version) ? null : 'stranded_version';
    }
}
