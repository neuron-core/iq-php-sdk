<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Continuations;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store whose process is outpaced: another process acts on a run after this
 * one read its snapshot and before it does what it decided from it. Neuron
 * reads the control record of a run first, for a snapshot as for an admission,
 * so the rival gets in just before the second read of that record.
 */
class OutpacedPersistence implements PersistenceInterface
{
    protected const CONTROL_RECORD = '__control';

    protected const READ_OF_THE_ADMISSION = 2;

    protected int $controlReads = 0;

    /**
     * @param Closure(): void $rival what the other process does to the run
     */
    public function __construct(protected PersistenceInterface $store, protected Closure $rival)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        if ($key === self::CONTROL_RECORD && ++$this->controlReads === self::READ_OF_THE_ADMISSION) {
            ($this->rival)();
        }

        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        return $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        return $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }
}
