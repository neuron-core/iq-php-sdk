<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use PDO;

use function microtime;
use function usleep;

/**
 * The durable queue of the shop: named lists in a SQLite table, where the
 * example of the design uses Redis lists. A message is handed to one worker
 * only and once: a worker that dies with it does not give it back, as with
 * BLPOP. A taken message stays in the table, marked, so that what the queue
 * carried can be read afterwards.
 */
final class Spool
{
    /** Microseconds between two looks at a list that is empty. */
    protected const PATIENCE = 20_000;

    public function __construct(protected PDO $pdo)
    {
    }

    public static function install(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jobs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, list TEXT NOT NULL, job TEXT NOT NULL, taken_at REAL)'
        );
    }

    public function push(string $list, string $job): void
    {
        $this->pdo->prepare('INSERT INTO jobs (list, job) VALUES (?, ?)')->execute([$list, $job]);
    }

    /**
     * Wait for a message of the list and take it.
     */
    public function pop(string $list): string
    {
        while (($job = $this->take($list)) === null) {
            usleep(self::PATIENCE);
        }

        return $job;
    }

    /**
     * What the cron of the example does: every message of one list that still waits goes to the end of another.
     */
    public function move(string $from, string $to): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $waiting = $this->pdo->prepare('SELECT id, job FROM jobs WHERE list = ? AND taken_at IS NULL ORDER BY id');
        $waiting->execute([$from]);

        foreach ($waiting->fetchAll(PDO::FETCH_ASSOC) as $message) {
            $this->pdo->prepare('UPDATE jobs SET taken_at = ? WHERE id = ?')->execute([microtime(true), $message['id']]);
            $this->pdo->prepare('INSERT INTO jobs (list, job) VALUES (?, ?)')->execute([$to, $message['job']]);
        }

        $this->pdo->exec('COMMIT');
    }

    /**
     * @return string[] the messages of a list that no worker has taken, oldest first
     */
    public function waiting(string $list): array
    {
        $waiting = $this->pdo->prepare('SELECT job FROM jobs WHERE list = ? AND taken_at IS NULL ORDER BY id');
        $waiting->execute([$list]);

        return $waiting->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<int, array{list: string, job: string}> every message the queue ever held, oldest first
     */
    public function carried(): array
    {
        return $this->pdo->query('SELECT list, job FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * One write transaction: two workers never take the same message.
     */
    protected function take(string $list): ?string
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        $first = $this->pdo->prepare('SELECT id, job FROM jobs WHERE list = ? AND taken_at IS NULL ORDER BY id LIMIT 1');
        $first->execute([$list]);
        $message = $first->fetch(PDO::FETCH_ASSOC);

        if ($message !== false) {
            $this->pdo->prepare('UPDATE jobs SET taken_at = ? WHERE id = ?')->execute([microtime(true), $message['id']]);
        }

        $this->pdo->exec('COMMIT');

        return $message === false ? null : $message['job'];
    }
}
