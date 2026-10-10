<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

use Closure;
use PDO;
use Throwable;

use function array_column;

/**
 * A SQLite file of the simulator: its only memory, or the tap a test reads
 * the wire from. The built-in server handles one request at a time, so
 * nothing here is written by two processes at once.
 */
final class Database
{
    /** What appendix B says a platform holds: the manifest, and for each run only coordination data. */
    public const MEMORY = [
        // The durations and the retry policy an operator changed; the defaults are in Settings.
        'CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value NUMERIC NOT NULL)',

        // What the platform knows of the one endpoint it calls.
        'CREATE TABLE IF NOT EXISTS endpoint (name TEXT PRIMARY KEY, value TEXT NOT NULL)',

        // The list the last sync published, in the order it named its versions.
        'CREATE TABLE IF NOT EXISTS manifest ('
        . 'definition TEXT NOT NULL, version INTEGER NOT NULL, lease_seconds INTEGER NOT NULL, position INTEGER NOT NULL, '
        . 'PRIMARY KEY (definition, version))',

        // One row per run ID: identifiers, the newest snapshot that named the run, the newest that did not, and how
        // the run ended. "definition" is what the platform names in its messages; "shown_definition" is what the
        // last snapshot that named the run showed.
        'CREATE TABLE IF NOT EXISTS runs ('
        . 'run_id TEXT PRIMARY KEY, workflow_id TEXT NOT NULL, definition TEXT NOT NULL, origin TEXT NOT NULL, '
        . 'firing TEXT UNIQUE, opened_at INTEGER NOT NULL, '
        . 'shown_definition TEXT, status TEXT, attempt INTEGER, lease_expires_at INTEGER, '
        . 'wait_id INTEGER, wait_type TEXT, wait_event TEXT, wake_at INTEGER, '
        . 'seen_at INTEGER, absent_at INTEGER, read_at INTEGER, asked_at INTEGER, lease_check_at INTEGER, unclaimed_at INTEGER, '
        . 'unread_at INTEGER, unread_delay INTEGER, undecided_until INTEGER, '
        . 'ask_after INTEGER, ask_status INTEGER, ask_failures INTEGER NOT NULL DEFAULT 0, '
        . 'start_asked INTEGER NOT NULL DEFAULT 0, reads INTEGER NOT NULL DEFAULT 0, '
        . 'abandon TEXT, abandon_tries INTEGER NOT NULL DEFAULT 0, '
        . 'abandon_after INTEGER, abandon_status INTEGER, abandon_failures INTEGER NOT NULL DEFAULT 0, '
        . 'closed_as TEXT, closed_at INTEGER, code TEXT)',
        'CREATE INDEX IF NOT EXISTS runs_by_workflow ON runs (workflow_id)',

        // The orders the platform itself sent: a start or a wake, with the same invocation ID on every re-send.
        'CREATE TABLE IF NOT EXISTS orders ('
        . 'invocation_id TEXT PRIMARY KEY, verb TEXT NOT NULL, run_id TEXT NOT NULL, attempt INTEGER, purpose TEXT NOT NULL, '
        . 'expires_at INTEGER, due_at INTEGER, sends INTEGER NOT NULL DEFAULT 0, uncertain INTEGER NOT NULL DEFAULT 0, '
        . 'status INTEGER, accepted_at INTEGER, ended TEXT, '
        . 'UNIQUE (run_id, attempt, purpose))',

        // What the application said of each segment: its announcement and its report, by invocation ID.
        'CREATE TABLE IF NOT EXISTS segments ('
        . 'invocation_id TEXT PRIMARY KEY, run_id TEXT NOT NULL, verb TEXT NOT NULL, origin TEXT NOT NULL, attempt INTEGER, '
        . 'lease_seconds INTEGER, announced_at INTEGER, heard_at INTEGER, '
        . 'reported_at INTEGER, settled_at INTEGER, outcome TEXT, code TEXT, claimed INTEGER, too_early INTEGER NOT NULL DEFAULT 0)',
        'CREATE INDEX IF NOT EXISTS segments_by_run ON segments (run_id)',

        // Rule 9: one failure per run ID and attempt.
        'CREATE TABLE IF NOT EXISTS failures ('
        . 'run_id TEXT NOT NULL, attempt INTEGER NOT NULL, kind TEXT NOT NULL, error_class TEXT, counted_at INTEGER NOT NULL, '
        . 'PRIMARY KEY (run_id, attempt))',

        // Rule 18, and whatever else a person must look at.
        'CREATE TABLE IF NOT EXISTS alerts ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, kind TEXT NOT NULL, subject TEXT NOT NULL, '
        . 'run_id TEXT, workflow_id TEXT, detail TEXT NOT NULL, UNIQUE (kind, subject))',

    ];

    /**
     * Not memory of the platform but the tap of a test, in a file of its own: every message as it crossed the
     * wire, with its answer. The platform never reads it, and keeps of a message only what the tables above hold.
     */
    public const WIRE_TAP = [
        'CREATE TABLE IF NOT EXISTS wire ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, at REAL NOT NULL, direction TEXT NOT NULL, message TEXT NOT NULL, '
        . 'target TEXT NOT NULL, headers TEXT NOT NULL, body TEXT NOT NULL, status INTEGER NOT NULL, answer TEXT NOT NULL, '
        . 'answer_headers TEXT NOT NULL)',
    ];

    protected PDO $pdo;

    /**
     * @param string[] $schema the tables the file holds: MEMORY or WIRE_TAP
     */
    public function __construct(string $path, array $schema = self::MEMORY)
    {
        $this->pdo = new PDO("sqlite:{$path}");
        // Rule 15: "recorded" is answered only after a durable write. A commit outlives the death of the simulator,
        // which is all a test can inflict: no test waits for the disk to confirm each one.
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA busy_timeout = 10000');

        $this->atomically(function () use ($schema): void {
            foreach ($schema as $statement) {
                $this->pdo->exec($statement);
            }
        });
    }

    /**
     * Everything the work writes is committed together, or nothing of it is. Work that is already part of
     * such a whole simply joins it.
     *
     * @template TResult
     * @param Closure(): TResult $work
     * @return TResult
     * @throws Throwable what the work threw, after everything it wrote was undone
     */
    public function atomically(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();
        } catch (Throwable $failed) {
            $this->pdo->rollBack();

            throw $failed;
        }

        return $result;
    }

    /**
     * Forget everything: what a test asks for, so that the next one meets a platform that has seen nothing.
     */
    public function erase(): void
    {
        $this->atomically(function (): void {
            foreach ($this->column("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $this->pdo->exec("DELETE FROM {$table}");
            }
        });
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function write(string $sql, array $values = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->rowCount();
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql, array $values = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $values = []): ?array
    {
        return $this->rows($sql, $values)[0] ?? null;
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, mixed> the first column of every row
     */
    public function column(string $sql, array $values = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return array_column($statement->fetchAll(PDO::FETCH_NUM), 0);
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function value(string $sql, array $values = []): mixed
    {
        return $this->column($sql, $values)[0] ?? null;
    }

}
