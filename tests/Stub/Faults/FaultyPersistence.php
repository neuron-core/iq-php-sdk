<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Faults;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\Tests\Support\Faults\StoreFault;
use NeuronCore\Cloud\Tests\Support\Faults\StoreJournal;
use NeuronCore\Cloud\Tests\Support\Faults\StoreOperation;

/**
 * A store that keeps a journal of what it performs and fails when its fault
 * says so: before it does anything, or after a write it has committed.
 */
class FaultyPersistence implements PersistenceInterface
{
    public function __construct(
        protected PersistenceInterface $store,
        protected StoreJournal $journal,
        protected StoreFault $fault,
    ) {
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->perform(
            new StoreOperation(__FUNCTION__, $partition, [$key => null]),
            fn (): ?string => $this->store->get($partition, $key),
        );
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        return $this->perform(
            new StoreOperation(__FUNCTION__, $partition, [$conditionKey => $initialValue] + $records),
            fn (): bool => $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records),
        );
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        return $this->perform(
            new StoreOperation(__FUNCTION__, $partition, $records + [$conditionKey => null]),
            fn (): bool => $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records),
        );
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->perform(
            new StoreOperation(__FUNCTION__, $partition, [$conditionKey => null]),
            fn (): bool => $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue),
        );
    }

    /**
     * @template TAnswer of bool|string|null
     * @param Closure(): TAnswer $perform
     * @return TAnswer
     * @throws StoreFailed
     */
    protected function perform(StoreOperation $operation, Closure $perform): bool|string|null
    {
        if ($this->fault->strikesBefore($operation)) {
            throw new StoreFailed("The store failed at {$operation->describe()} and did nothing.");
        }

        $answer = $perform();
        $this->journal->record($operation, $answer);

        if ($this->fault->strikesAfter($operation)) {
            throw new StoreFailed("The store performed {$operation->describe()} and lost the answer.");
        }

        return $answer;
    }
}
