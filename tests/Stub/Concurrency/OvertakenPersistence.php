<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Concurrency;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store whose process is overtaken while Neuron continues a run: another
 * process gets in between Neuron's read of the control record of the run and
 * its read of the start record.
 */
class OvertakenPersistence implements PersistenceInterface
{
    protected const START_RECORD = '__ignition';

    /** The runner reads the run once itself before it gives a wake to Neuron: the second read is Neuron's. */
    protected const READ_OF_NEURON = 2;

    protected int $startRecordReads = 0;

    /**
     * @param Closure(): void $rival what the other process does to the store
     */
    public function __construct(protected PersistenceInterface $store, protected Closure $rival)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        if ($key === self::START_RECORD && ++$this->startRecordReads === self::READ_OF_NEURON) {
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
