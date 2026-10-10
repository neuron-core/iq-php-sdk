<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

/**
 * What the application said of each segment, by invocation ID: "starting",
 * then "this is the run now". Only identifiers, instants, the outcome, its
 * code and whether a start was claimed are kept (rule 15).
 */
final class Segments
{
    public function __construct(protected Database $database)
    {
    }

    /**
     * @param array<string, mixed> $announcement
     * @param float $now the platform's clock, to tell what was heard after an order was accepted
     */
    public function announced(array $announcement, float $now): void
    {
        $this->open($announcement);

        $this->database->write(
            'UPDATE segments SET lease_seconds = ?, announced_at = max(coalesce(announced_at, 0), ?), heard_at = ? WHERE invocation_id = ?',
            [$announcement['leaseSeconds'], $announcement['at'], $now, $announcement['invocationId']],
        );
    }

    /**
     * @param array<string, mixed> $report
     * @param bool|null $claimed what the report says of the ledger; null when its snapshot was not read
     * @param bool $tooEarly whether it is the report of a wake that came before its timer was due (rule 8)
     */
    public function reported(array $report, ?bool $claimed, bool $tooEarly, float $now): void
    {
        $this->open($report);

        $this->database->write(
            'UPDATE segments SET reported_at = max(coalesce(reported_at, 0), ?), settled_at = ?, outcome = ?, code = ?, claimed = ?, '
            . 'too_early = ? WHERE invocation_id = ?',
            [
                $report['at'],
                $now,
                $report['outcome'],
                $report['code'],
                $claimed === null ? null : (int) $claimed,
                (int) $tooEarly,
                $report['invocationId'],
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $invocationId): ?array
    {
        return $this->database->row('SELECT * FROM segments WHERE invocation_id = ?', [$invocationId]);
    }

    /**
     * @return array<int, array<string, mixed>> oldest first
     */
    public function of(string $runId): array
    {
        return $this->database->rows('SELECT * FROM segments WHERE run_id = ? ORDER BY rowid', [$runId]);
    }

    /**
     * Rule 10: the newest announcement of a run that no report has followed. An order that is sent again keeps
     * its invocation ID, so two copies of it are announced and reported under one ID. A report follows an
     * announcement when its "at" is later. When both carry the same second, it follows only when it also
     * arrived later: an announcement that arrived after it may be the one of a second copy, and is asked about.
     *
     * @return array<string, mixed>|null
     */
    public function silentOf(string $runId): ?array
    {
        return $this->database->row(
            'SELECT * FROM segments WHERE run_id = ? AND announced_at IS NOT NULL '
            . 'AND (reported_at IS NULL OR reported_at < announced_at OR (reported_at = announced_at AND settled_at < heard_at)) '
            . 'ORDER BY announced_at DESC LIMIT 1',
            [$runId],
        );
    }

    /**
     * @return array<string, mixed>|null the newest announcement of a run, reported or not
     */
    public function lastAnnouncedOf(string $runId): ?array
    {
        return $this->database->row(
            'SELECT * FROM segments WHERE run_id = ? AND announced_at IS NOT NULL ORDER BY announced_at DESC LIMIT 1',
            [$runId],
        );
    }

    /**
     * @return array<string, mixed>|null the newest report of a run
     */
    public function lastReportedOf(string $runId): ?array
    {
        return $this->database->row(
            'SELECT * FROM segments WHERE run_id = ? AND reported_at IS NOT NULL ORDER BY reported_at DESC LIMIT 1',
            [$runId],
        );
    }

    /**
     * Whether a copy of the start of a run was reported "rejected: expired": its use-by time has passed.
     */
    public function startExpired(string $runId): bool
    {
        return $this->database->value(
            "SELECT 1 FROM segments WHERE run_id = ? AND verb = ? AND outcome = 'rejected' AND code = 'expired'",
            [$runId, Orders::START],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $notice an announcement or a report
     */
    protected function open(array $notice): void
    {
        $this->database->write(
            'INSERT OR IGNORE INTO segments (invocation_id, run_id, verb, origin, attempt) VALUES (?, ?, ?, ?, ?)',
            [$notice['invocationId'], $notice['runId'], $notice['verb'], $notice['origin'], $notice['attempt'] ?? null],
        );
    }
}
