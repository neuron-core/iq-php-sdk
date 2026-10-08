<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Concurrency;

use function file_exists;
use function touch;
use function usleep;

/**
 * Holds a node of another process inside its body until the test opens it.
 * It is a file, so the test and the workers see the same gate.
 */
final class Gate
{
    /**
     * @param string $path a file that does not exist while the gate is closed
     */
    public function __construct(public readonly string $path)
    {
    }

    public function open(): void
    {
        touch($this->path);
    }

    /**
     * Returns once the gate is open.
     */
    public function pass(): void
    {
        while (!file_exists($this->path)) {
            usleep(5_000);
        }
    }
}
