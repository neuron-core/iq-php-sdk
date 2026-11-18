<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use function array_shift;

/**
 * The lists of a Redis server, in memory: the queue of the plain PHP example.
 */
final class FakeRedis
{
    /** @var array<string, string[]> */
    protected array $lists = [];

    /** @var array<string, string[]> */
    protected array $pushed = [];

    public function rPush(string $list, string $value): void
    {
        $this->lists[$list][] = $value;
        $this->pushed[$list][] = $value;
    }

    /**
     * @return string|null the head of the list, removed from it; null when the list is empty
     */
    public function lPop(string $list): ?string
    {
        return isset($this->lists[$list]) ? array_shift($this->lists[$list]) : null;
    }

    /**
     * @return string[] the whole list, in order
     */
    public function lRange(string $list): array
    {
        return $this->lists[$list] ?? [];
    }

    /**
     * @return string[] every value the list was ever pushed, in order, whether it is still there or not
     */
    public function everPushed(string $list): array
    {
        return $this->pushed[$list] ?? [];
    }
}
