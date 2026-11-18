<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function implode;
use function random_bytes;

/**
 * The orders the platform itself sent or is about to send: a start, or a wake
 * fenced with an attempt the application reported. An order keeps its
 * invocation ID on every re-send, and the platform re-sends nothing else.
 */
final class Orders
{
    public const START = 'start';

    public const WAKE = 'wake';

    public function __construct(protected Database $database)
    {
    }

    /**
     * The use-by time of a start is not known yet: it is fixed when the start is first sent, and kept for the
     * life of its run ID (rule 1).
     *
     * @param int|null $attempt the fence of a wake: rule 2, never guessed
     * @param string $purpose "start"; "timer:<wait>" for the one timer of a wait; "retry" for a failed or dead attempt
     * @param float $dueAt when the order is first sent
     * @return array<string, mixed>
     */
    public function issue(string $verb, string $runId, ?int $attempt, string $purpose, float $dueAt): array
    {
        $invocationId = 'inv_' . bin2hex(random_bytes(12));

        $this->database->write(
            'INSERT INTO orders (invocation_id, verb, run_id, attempt, purpose, due_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$invocationId, $verb, $runId, $attempt, $purpose, $dueAt],
        );

        return (array) $this->find($invocationId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $invocationId): ?array
    {
        return $this->database->row('SELECT * FROM orders WHERE invocation_id = ?', [$invocationId]);
    }

    /**
     * @return array<string, mixed>|null the start the platform issued for a run; null for a run the application started
     */
    public function startOf(string $runId): ?array
    {
        return $this->database->row('SELECT * FROM orders WHERE run_id = ? AND verb = ?', [$runId, self::START]);
    }

    /**
     * Rule 8: one timer per wait. Rule 9: one retry per run ID and attempt.
     *
     * @return array<string, mixed>|null
     */
    public function wakeFor(string $runId, int $attempt, string $purpose): ?array
    {
        return $this->database->row(
            'SELECT * FROM orders WHERE run_id = ? AND attempt = ? AND purpose = ?',
            [$runId, $attempt, $purpose],
        );
    }

    /**
     * @return array<int, array<string, mixed>> oldest first
     */
    public function of(string $runId): array
    {
        return $this->database->rows('SELECT * FROM orders WHERE run_id = ? ORDER BY rowid', [$runId]);
    }

    /**
     * @param array<string, mixed> $fields column => value
     */
    public function update(string $invocationId, array $fields): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys($fields)));

        $this->database->write(
            "UPDATE orders SET {$assignments} WHERE invocation_id = ?",
            [...array_values($fields), $invocationId],
        );
    }
}
