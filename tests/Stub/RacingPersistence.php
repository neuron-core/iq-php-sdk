<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store in which another process gets in just before each of the first
 * conditional writes, as when two workers act on one record at the same moment.
 */
class RacingPersistence implements PersistenceInterface
{
    /**
     * @param int $races how many conditional writes the rival gets in front of
     * @param Closure(): void $rival what the other process does to the store
     */
    public function __construct(
        protected PersistenceInterface $store,
        protected int $races,
        protected Closure $rival,
    ) {
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $this->race();

        return $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $this->race();

        return $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        $this->race();

        return $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }

    protected function race(): void
    {
        if ($this->races > 0) {
            $this->races--;
            ($this->rival)();
        }
    }
}
