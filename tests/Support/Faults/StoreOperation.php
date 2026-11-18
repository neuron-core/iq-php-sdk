<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use function array_keys;
use function array_map;
use function implode;

/**
 * One call a store received.
 */
final class StoreOperation
{
    /**
     * @param string $method the method of PersistenceInterface that was called
     * @param array<array-key, string|null> $records the keys it names, each with the value it writes: null for a key it only reads or checks
     */
    public function __construct(
        public readonly string $method,
        public readonly string $partition,
        public readonly array $records,
    ) {
    }

    public function writes(): bool
    {
        return $this->method !== 'get';
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->records));
    }

    public function describe(): string
    {
        return "{$this->method}({$this->partition}: " . implode(', ', $this->keys()) . ')';
    }
}
