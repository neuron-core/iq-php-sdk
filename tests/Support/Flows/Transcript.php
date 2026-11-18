<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use function file;
use function file_put_contents;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const LOCK_EX;

/**
 * What the nodes of a flow were given, in the order they noted it. It is a
 * file, so it holds when the nodes run in another process.
 */
final class Transcript
{
    /**
     * @param string $path an existing file
     */
    public function __construct(public readonly string $path)
    {
    }

    public function note(string $line): void
    {
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return string[] every line noted, in order
     */
    public function lines(): array
    {
        return file($this->path, FILE_IGNORE_NEW_LINES) ?: [];
    }
}
