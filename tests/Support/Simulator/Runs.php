<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function max;
use function min;

/**
 * What the platform holds for each run: its identifiers, the newest snapshot
 * that named it and how it ended. The application's store is the truth: this
 * is a copy, replaced by rule 6 and closed by rule 7 of appendix B.
 */
final class Runs
{
    /** A report said so, or a snapshot showed a completion that code outside the SDK retained. */
    public const COMPLETED = 'completed';

    /** The endpoint answered 200 to an abandon: nothing runs under that run ID any more. */
    public const ABANDONED = 'abandoned';

    /** "Ended, outcome unknown": the run left its workflow ID and no report said how. */
    public const ENDED_UNKNOWN = 'ended_unknown';

    /** A start that travelled through a queue, that no copy claimed and none can claim any more. */
    public const LOST = 'lost';

    /** A start that was refused, rejected or aborted after its claim: it never runs under that run ID. */
    public const SPENT = 'spent';

    /** What no later snapshot or announcement undoes. */
    protected const FINAL = [self::COMPLETED, self::ABANDONED];

    public function __construct(
        protected Database $database,
        protected Settings $settings,
        protected Alerts $alerts,
        protected Manifest $manifest,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        return $this->database->row('SELECT * FROM runs WHERE run_id = ?', [$runId]);
    }

    /**
     * @return array<int, array<string, mixed>> every run of a workflow ID, or every run, oldest first
     */
    public function all(?string $workflowId = null): array
    {
        return $workflowId === null
            ? $this->database->rows('SELECT * FROM runs ORDER BY rowid')
            : $this->database->rows('SELECT * FROM runs WHERE workflow_id = ? ORDER BY rowid', [$workflowId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function open(): array
    {
        return $this->database->rows('SELECT * FROM runs WHERE closed_as IS NULL ORDER BY rowid');
    }

    /**
     * @return array<int, array<string, mixed>> the runs the platform may have something to do about: the open ones,
     *                                          and a closed one that a snapshot it cannot place named
     */
    public function toPlan(): array
    {
        return $this->database->rows('SELECT * FROM runs WHERE closed_as IS NULL OR undecided_until IS NOT NULL ORDER BY rowid');
    }

    /**
     * A run whose start the platform issues itself (rules 1, 3 and 4).
     *
     * @param string|null $firing the firing of the trigger, when the operator names it
     * @return array<string, mixed>
     */
    public function issue(string $runId, string $workflowId, string $definition, ?string $firing, float $now): array
    {
        $this->database->write(
            'INSERT INTO runs (run_id, workflow_id, definition, origin, firing, opened_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$runId, $workflowId, $definition, 'platform', $firing, $now],
        );

        return (array) $this->find($runId);
    }

    /**
     * @return array<string, mixed>|null the run a firing already started
     */
    public function ofFiring(string $firing): ?array
    {
        return $this->database->row('SELECT * FROM runs WHERE firing = ?', [$firing]);
    }

    /**
     * Rules 3 and 15: a run the platform did not start is accepted, with the definition of the first
     * announcement or report whose run ID is that run, or of the snapshot it was first seen in. What a later
     * message says of it changes nothing.
     *
     * @return array<string, mixed>
     */
    public function heardOf(string $runId, string $workflowId, string $definition, float $now): array
    {
        $this->database->write(
            'INSERT OR IGNORE INTO runs (run_id, workflow_id, definition, origin, opened_at) VALUES (?, ?, ?, ?, ?)',
            [$runId, $workflowId, $definition, 'application', $now],
        );

        return (array) $this->find($runId);
    }

    /**
     * @param array<string, mixed> $fields column => value
     */
    public function update(string $runId, array $fields): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($fields)));

        $this->database->write("UPDATE runs SET {$assignments} WHERE run_id = ?", [...array_values($fields), $runId]);
    }

    /**
     * Rules 6 and 7: plan from a snapshot that was read. The run it names replaces what the platform holds for
     * that run. For every other run of the workflow ID it is a snapshot that does not name it.
     *
     * @param array<string, mixed>|null $shown the run holding the workflow ID, as a report or an answer carries it
     * @param int $at the application's clock when the store was read
     */
    public function read(string $workflowId, ?array $shown, int $at, float $now): void
    {
        $named = $shown === null ? null : $this->find($shown['runId']) ?? $this->adopt($workflowId, $shown, $now);
        $named = $named !== null && $named['workflow_id'] === $workflowId ? $named : null;

        $this->database->write(
            'UPDATE runs SET absent_at = max(coalesce(absent_at, 0), ?) WHERE workflow_id = ? AND run_id != ?',
            [$at, $workflowId, $named['run_id'] ?? ''],
        );

        if ($named !== null) {
            $this->show($named, (array) $shown, $at, $now);
        }

        $this->database->write(
            'UPDATE runs SET read_at = ?, unread_at = NULL, unread_delay = NULL '
            . 'WHERE workflow_id = ? AND (closed_as IS NULL OR undecided_until IS NOT NULL)',
            [$now, $workflowId],
        );

        foreach ($this->all($workflowId) as $run) {
            if ($run['closed_as'] === null && $run['seen_at'] !== null) {
                $this->place($run, $at, $now);
            } elseif ($run['closed_as'] !== null && $run['undecided_until'] !== null && $at >= $run['closed_at']) {
                // A snapshot later than the message that closed the run settles whether the run is there. One of
                // that very second settles nothing, and the store is asked again a second later.
                $this->update($run['run_id'], ['undecided_until' => $at > $run['closed_at'] ? null : $now + 1]);
            }
        }
    }

    /**
     * Rule 3: a run the platform has never heard of and sees in a snapshot is adopted under the definition the
     * snapshot shows, when the manifest holds that definition. A snapshot that shows none does not say who
     * started the run: such a run is sent nothing until an announcement or a report of its own arrives.
     *
     * @param array<string, mixed> $shown
     * @return array<string, mixed>|null the run, once the platform holds it
     */
    protected function adopt(string $workflowId, array $shown, float $now): ?array
    {
        $definition = $shown['definition'];

        return $definition === null || $this->manifest->currentVersion($definition) === null
            ? null
            : $this->heardOf($shown['runId'], $workflowId, $definition, $now);
    }

    /**
     * Rule 7: a run a snapshot named has ended when a later snapshot does not name it, and nothing said how.
     * Later means a greater "at", whatever the order in which the two arrived.
     *
     * @param array<string, mixed> $run an open run that a snapshot named
     * @param int $at the "at" of the snapshot that was just read
     */
    protected function place(array $run, int $at, float $now): void
    {
        if ($run['absent_at'] > $run['seen_at']) {
            $this->close($run['run_id'], self::ENDED_UNKNOWN, $run['absent_at']);

            return;
        }

        // Instants are whole seconds: nothing tells which of two snapshots of one second was read first. The store
        // is asked again once that second has passed, and no wake is planned from what is held until then.
        $inDoubt = $run['absent_at'] === $run['seen_at'];
        $askAt = $at === $run['seen_at'] ? $now + 1 : $run['undecided_until'] ?? $now + 1;

        $this->update($run['run_id'], ['undecided_until' => $inDoubt ? $askAt : null]);
    }

    /**
     * Rule 6: a report whose snapshot was not read is followed by an inspect after 30 seconds, doubling to 600
     * with every answer that could not be read either.
     *
     * @param bool $again whether an inspect just failed to read the store too
     */
    public function unread(string $runId, float $now, bool $again = false): void
    {
        $run = (array) $this->find($runId);

        if ($run['unread_at'] !== null && !$again) {
            return;
        }

        $delay = $run['unread_delay'] === null
            ? $this->settings->get('unreadFollowUp')
            : min($run['unread_delay'] * 2, max($this->settings->get('unreadFollowUpCap'), $this->settings->get('unreadFollowUp')));

        $this->update($runId, ['unread_at' => $now + $delay, 'unread_delay' => $delay]);
    }

    /**
     * Rule 7. A completion always counts. Anything else closes only a run that is open.
     *
     * @param int $at the application's clock at the observation that closes the run
     * @param string|null $code shown to an operator for a start that is spent
     */
    public function close(string $runId, string $as, int $at, ?string $code = null): void
    {
        $this->database->write(
            'UPDATE runs SET closed_as = ?, closed_at = ?, code = ?, abandon = NULL, undecided_until = NULL, unread_at = NULL '
            . 'WHERE run_id = ? AND (closed_as IS NULL OR ? = ?)',
            [$as, $at, $code, $runId, $as, self::COMPLETED],
        );
    }

    /**
     * Rule 7: an announcement with a later "at" opens again a run the application started that is known from
     * its announcement alone and was closed as "ended, outcome unknown". It opens no other run: a second copy
     * of a start or of a wake posts its announcement before it is refused.
     *
     * @param array<string, mixed> $run
     */
    public function reopenForAnnouncement(array $run, int $at): void
    {
        $knownFromItsAnnouncementAlone = $run['origin'] === 'application' && $run['seen_at'] === null;

        if ($knownFromItsAnnouncementAlone && $run['closed_as'] === self::ENDED_UNKNOWN && $at > $run['closed_at']) {
            $this->update($run['run_id'], ['closed_as' => null, 'closed_at' => null]);
        }
    }

    /**
     * @return array<int, array<string, mixed>> rule 9: one failure per run ID and attempt
     */
    public function failures(string $runId): array
    {
        return $this->database->rows(
            'SELECT attempt, kind, error_class AS errorClass, counted_at AS countedAt FROM failures WHERE run_id = ? ORDER BY attempt',
            [$runId],
        );
    }

    /**
     * @param array<string, mixed> $held the run as the platform holds it
     * @param array<string, mixed> $shown the same run, as the snapshot shows it
     */
    protected function show(array $held, array $shown, int $at, float $now): void
    {
        // Rule 7: what the platform concluded gives way to the store. A run closed as unknown, lost or spent is
        // open again when a snapshot later than the message that closed it names it.
        if (in_array($held['closed_as'], self::FINAL, true) || ($held['closed_as'] !== null && $at < $held['closed_at'])) {
            return;
        }

        if ($held['closed_as'] !== null && $at === $held['closed_at']) {
            // The same second: nothing tells whether the run was shown before or after it was closed. It stays
            // closed, and the store is asked once that second has passed.
            $this->update($held['run_id'], ['undecided_until' => $now + 1]);

            return;
        }

        $fields = [
            'closed_as' => null,
            'closed_at' => null,
            'code' => null,
            'seen_at' => max((int) $held['seen_at'], $at),
            'shown_definition' => $shown['definition'],
        ];
        if ($this->replaces($held, $shown)) {
            $fields += $this->snapshot($shown);
        }

        $held = $fields + $held;
        $lease = $shown['leaseExpiresAt'];
        $isTheRunHeld = $shown['attempt'] === $held['attempt'] && $shown['status'] === $held['status'];
        $runsUnderALease = $isTheRunHeld && $shown['status'] === 'running' && $lease !== null && $lease === $held['lease_expires_at'];

        if ($runsUnderALease) {
            // Rule 11: never before the lease deadline, which is a time of the application's clock. Null: it has passed.
            $fields['lease_check_at'] = $lease > $at ? $now + $lease - $at : null;
        }

        $this->update($held['run_id'], $fields);

        if ($shown['status'] === 'completed') {
            $this->close($held['run_id'], self::COMPLETED, $at);
        } elseif ($isTheRunHeld && $shown['status'] === 'failed') {
            $this->countFailure($held, 'failed', $now);
        } elseif ($runsUnderALease && $lease <= $at) {
            // Rule 11: a dead run is retried like a failed one.
            $this->countFailure($held, 'dead', $now);
        } elseif ($isTheRunHeld && $shown['status'] === 'running' && $lease === null) {
            $this->alerts->raise(
                Alerts::RUNNING_WITHOUT_LEASE,
                $held['run_id'],
                'The run is running with no lease: it was started outside the SDK and is never woken.',
                $now,
                $held,
            );
        }
    }

    /**
     * Rule 6: a snapshot that names a run replaces what the platform holds for it when its attempt is higher,
     * or equal with a later lease deadline or a status that left "running".
     *
     * @param array<string, mixed> $held
     * @param array<string, mixed> $shown
     */
    protected function replaces(array $held, array $shown): bool
    {
        if ($held['seen_at'] === null || $shown['attempt'] > $held['attempt']) {
            return true;
        }

        return $shown['attempt'] === $held['attempt']
            && $held['status'] === 'running'
            && ($shown['status'] !== 'running' || (int) $shown['leaseExpiresAt'] > (int) $held['lease_expires_at']);
    }

    /**
     * Rule 15: only coordination data is kept of a run: its status, its attempt, its lease deadline and its wait.
     *
     * @param array<string, mixed> $shown
     * @return array<string, mixed> column => value
     */
    protected function snapshot(array $shown): array
    {
        $wait = $shown['wait'] ?? [];

        return [
            'status' => $shown['status'],
            'attempt' => $shown['attempt'],
            'lease_expires_at' => $shown['leaseExpiresAt'],
            'wait_id' => $wait['interruptId'] ?? null,
            'wait_type' => $wait['type'] ?? null,
            'wait_event' => $wait['eventName'] ?? null,
            'wake_at' => $wait['wakeAt'] ?? null,
            'lease_check_at' => null,
            'reads' => 0,
        ];
    }

    /**
     * Rule 9: one failure per run ID and attempt. When the policy gives up, the run is left failed and a
     * person is told.
     *
     * @param array<string, mixed> $run
     */
    protected function countFailure(array $run, string $kind, float $now): void
    {
        $counted = $this->database->write(
            'INSERT OR IGNORE INTO failures (run_id, attempt, kind, counted_at) VALUES (?, ?, ?, ?)',
            [$run['run_id'], $run['attempt'], $kind, $now],
        );

        if ($counted === 1 && count($this->failures($run['run_id'])) > $this->settings->whole('retryAttempts')) {
            $this->alerts->raise(
                Alerts::RETRIES_EXHAUSTED,
                "{$run['run_id']}#{$run['attempt']}",
                "The retry policy gave up on the run at attempt {$run['attempt']}: it is left {$kind}.",
                $now,
                $run,
            );
        }
    }
}
