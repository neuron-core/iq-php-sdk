<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store that remembers every operation it was asked for.
 */
class RecordingPersistence implements PersistenceInterface
{
    /** @var string[] */
    protected array $operations = [];

    public function __construct(protected PersistenceInterface $store = new InMemoryPersistence())
    {
    }

    public function get(string $partition, string $key): ?string
    {
        $this->operations[] = __FUNCTION__;

        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $this->operations[] = __FUNCTION__;

        return $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $this->operations[] = __FUNCTION__;

        return $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        $this->operations[] = __FUNCTION__;

        return $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }

    /**
     * @return string[] the name of every operation asked for, in order
     */
    public function operations(): array
    {
        return $this->operations;
    }
}
