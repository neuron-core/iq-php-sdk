<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Matrix;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * A store its worker can be cut off from while a node is in flight. From then
 * on none of the worker's writes arrive, as when its process is killed or its
 * connection drops; what is stored stays readable.
 */
class SeverablePersistence implements PersistenceInterface
{
    protected bool $severed = false;

    public function __construct(protected PersistenceInterface $store)
    {
    }

    public function sever(): void
    {
        $this->severed = true;
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $this->assertNotSevered();

        return $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $this->assertNotSevered();

        return $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        $this->assertNotSevered();

        return $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }

    /**
     * @throws PersistenceException
     */
    protected function assertNotSevered(): void
    {
        if ($this->severed) {
            throw new PersistenceException('The worker is cut off from its store: nothing it writes arrives.');
        }
    }
}
