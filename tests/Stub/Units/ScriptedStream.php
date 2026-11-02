<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\HttpClient\StreamInterface;

use function array_shift;

/**
 * A response body read line by line, as a provider reads server-sent events.
 */
class ScriptedStream implements StreamInterface
{
    /**
     * @param list<string> $lines
     */
    public function __construct(protected array $lines)
    {
    }

    public function eof(): bool
    {
        return $this->lines === [];
    }

    public function read(int $length): string
    {
        return $this->readLine();
    }

    public function readLine(): string
    {
        return (string) array_shift($this->lines);
    }

    public function close(): void
    {
    }
}
