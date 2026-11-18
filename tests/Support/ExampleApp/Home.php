<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use PDO;

use function is_dir;
use function mkdir;

/**
 * The directory every process of the shop shares: the SQLite file of the
 * workflow store, the SQLite file of the queue, and what a test reads or
 * plants there.
 */
final class Home
{
    public function __construct(public readonly string $directory)
    {
    }

    /**
     * The migrations of a deploy: Neuron's workflow table and the table of the queue.
     */
    public function install(): void
    {
        foreach ([$this->faults(), $this->executions()] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
        }

        $this->store()->exec(
            'CREATE TABLE IF NOT EXISTS workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );
        Spool::install($this->queue());
    }

    /**
     * The database with Neuron's workflow table.
     */
    public function store(): PDO
    {
        return $this->connect("{$this->directory}/store.sqlite");
    }

    public function queue(): PDO
    {
        return $this->connect("{$this->directory}/queue.sqlite");
    }

    public function faults(): string
    {
        return "{$this->directory}/faults";
    }

    public function executions(): string
    {
        return "{$this->directory}/executions";
    }

    protected function connect(string $path): PDO
    {
        $pdo = new PDO("sqlite:{$path}");
        // Several processes write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
