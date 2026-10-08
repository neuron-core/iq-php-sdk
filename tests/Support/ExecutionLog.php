<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support;

use function array_keys;
use function count;
use function file;
use function file_put_contents;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const LOCK_EX;

/**
 * Counts how many times each node body ran. It counts in a file, so the
 * count holds when the workflow runs in another process.
 */
final class ExecutionLog
{
    /**
     * @param string $path an existing file
     */
    public function __construct(public readonly string $path)
    {
    }

    /**
     * @param class-string $node
     */
    public function record(string $node): void
    {
        file_put_contents($this->path, $node . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param class-string $node
     */
    public function count(string $node): int
    {
        return count(array_keys($this->executions(), $node, true));
    }

    /**
     * How many node bodies ran, whatever the node.
     */
    public function total(): int
    {
        return count($this->executions());
    }

    /**
     * @return string[] the class of the node of every execution, in order
     */
    protected function executions(): array
    {
        return file($this->path, FILE_IGNORE_NEW_LINES) ?: [];
    }
}
