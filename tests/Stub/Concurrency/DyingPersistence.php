<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Concurrency;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

use function posix_getpid;
use function posix_kill;
use function str_starts_with;

use const SIGKILL;

/**
 * A store whose process dies between the claim of a start and Neuron's first
 * write: it kills itself at the first write that follows the ledger's.
 */
class DyingPersistence implements PersistenceInterface
{
    protected const LEDGER_PARTITION_PREFIX = '__neuron_cloud:';

    protected bool $claimed = false;

    public function __construct(protected PersistenceInterface $store)
    {
    }

    public function get(string $partition, string $key): ?string
    {
        return $this->store->get($partition, $key);
    }

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        return $this->write(
            $partition,
            fn (): bool => $this->store->initializeIfAbsent($partition, $conditionKey, $initialValue, $records),
        );
    }

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        return $this->write(
            $partition,
            fn (): bool => $this->store->writeIfUnchanged($partition, $conditionKey, $expectedValue, $records),
        );
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        return $this->write(
            $partition,
            fn (): bool => $this->store->deleteIfUnchanged($partition, $conditionKey, $expectedValue),
        );
    }

    /**
     * @param Closure(): bool $write
     */
    protected function write(string $partition, Closure $write): bool
    {
        if ($this->claimed) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        $written = $write();
        $this->claimed = $written && str_starts_with($partition, self::LEDGER_PARTITION_PREFIX);

        return $written;
    }
}
