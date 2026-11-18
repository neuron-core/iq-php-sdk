<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * The workflow store of the shop, with a point before each of its operations
 * where a test can plan a failure, a delay or the death of the process, and
 * one after each write, where a failure loses the answer of a write that was
 * committed. The removal of the records of a run has a point of its own, so
 * that a test reaches it whatever the number of writes before it.
 */
class FaultyStore implements PersistenceInterface
{
    public const READ = 'store.read';

    public const WRITE = 'store.write';

    /** After a write: the store holds it, and whoever wrote it never learns so. */
    public const WRITTEN = 'store.written';

    /** After the records of a run were removed, as its completion does: the run is gone, and whoever removed it never learns so. */
    public const REMOVED = 'store.removed';

    public function __construct(protected PersistenceInterface $store, protected Faults $faults)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        $this->faults->at(self::READ);

        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $this->faults->at(self::WRITE);
        $written = $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
        $this->faults->at(self::WRITTEN);

        return $written;
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $this->faults->at(self::WRITE);
        $written = $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
        $this->faults->at(self::WRITTEN);

        return $written;
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        $this->faults->at(self::WRITE);
        $deleted = $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
        $this->faults->at(self::WRITTEN);
        $this->faults->at(self::REMOVED);

        return $deleted;
    }
}
