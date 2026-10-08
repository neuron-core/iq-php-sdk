<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Flows;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;

use function array_map;
use function file;
use function file_put_contents;
use function json_decode;
use function json_encode;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * The queue of an application whose requests and workers are separate
 * processes: a file each of them appends its messages to.
 */
final class FileQueue implements DispatcherInterface
{
    /**
     * @param string $path an existing file
     */
    public function __construct(public readonly string $path)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        file_put_contents($this->path, json_encode($invocation->toArray(), JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Take everything that waits in the queue out of it.
     *
     * @return Invocation[] in the order the messages arrived
     */
    public function take(): array
    {
        $waiting = file($this->path, FILE_IGNORE_NEW_LINES) ?: [];
        file_put_contents($this->path, '', LOCK_EX);

        return array_map(
            static fn (string $message): Invocation => Invocation::fromArray(json_decode($message, true, flags: JSON_THROW_ON_ERROR)),
            $waiting,
        );
    }
}
