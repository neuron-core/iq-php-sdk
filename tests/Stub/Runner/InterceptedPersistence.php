<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Runner;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store a test steps into just before each operation reaches it: to make
 * the store fail, to hold a caller up, or to note what had happened by then.
 */
class InterceptedPersistence implements PersistenceInterface
{
    /**
     * @param Closure(string $operation, string $partition): void $before called with the name of the interface method
     */
    public function __construct(protected PersistenceInterface $store, protected Closure $before)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        ($this->before)(__FUNCTION__, $partition);

        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        ($this->before)(__FUNCTION__, $partition);

        return $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        ($this->before)(__FUNCTION__, $partition);

        return $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        ($this->before)(__FUNCTION__, $partition);

        return $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }
}
