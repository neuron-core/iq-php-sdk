<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;

use function array_filter;
use function hash;
use function json_decode;
use function json_encode;
use function time;

use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;

/**
 * The starts this application has accepted, kept in its own workflow store.
 * Neuron forgets a run when it completes; the ledger is what still refuses a
 * late copy of its start.
 *
 * One record per workflow ID: partition "__neuron_cloud:" followed by the
 * SHA-256 of the workflow ID, key "starts", value a JSON object of run ID to
 * use-by time. Neuron refuses workflow IDs that begin with "__", so no
 * workflow can collide with it. This is a durable format.
 *
 * @internal
 */
final class Ledger
{
    protected const PARTITION_PREFIX = '__neuron_cloud:';

    protected const KEY = 'starts';

    /** Seconds an entry outlives its use-by time. */
    protected const RETENTION = 3600;

    /** How many conditional writes may be lost to other processes before a claim gives up. */
    protected const TRIES = 5;

    /**
     * @param Closure(): PersistenceInterface $persistence
     */
    public function __construct(protected Closure $persistence)
    {
    }

    /**
     * Claim a run ID, immediately before its start is given to Neuron. A run
     * ID is claimed once and the claim is never undone.
     *
     * @param int $useBy the use-by time of the start
     * @throws CloudException unavailable, when every try lost its write to another process; nothing was written
     */
    public function claim(string $workflowId, string $runId, int $useBy): Claim
    {
        $store = ($this->persistence)();
        $partition = $this->partition($workflowId);

        for ($try = 1; $try <= self::TRIES; $try++) {
            $stored = $store->get($partition, self::KEY);
            $entries = $this->decode($stored);

            // The clock is read once, after the record, to refuse an expired start and to prune. An entry is
            // dropped only an hour past its use-by time, so the entry of a start this clock still accepts
            // cannot be missing from the record just read, however long the copy waited before the claim.
            $now = time();

            if ($now >= $useBy) {
                return Claim::Expired;
            }

            if (isset($entries[$runId])) {
                return Claim::AlreadyThere;
            }

            $entries = array_filter($entries, static fn (int $entryUseBy): bool => $entryUseBy + self::RETENTION >= $now);
            $entries[$runId] = $useBy;

            if ($this->write($store, $partition, $stored, $entries)) {
                return Claim::Written;
            }
        }

        throw new CloudException(
            "The start ledger of workflow ID '{$workflowId}' changed " . self::TRIES . ' times in a row: nothing was claimed.',
            Code::Unavailable,
        );
    }

    /**
     * Whether a run ID is in the ledger: one read, no write.
     */
    public function has(string $workflowId, string $runId): bool
    {
        $stored = ($this->persistence)()->get($this->partition($workflowId), self::KEY);

        return isset($this->decode($stored)[$runId]);
    }

    protected function partition(string $workflowId): string
    {
        return self::PARTITION_PREFIX . hash('sha256', $workflowId);
    }

    /**
     * @return array<array-key, int> run ID => use-by time
     */
    protected function decode(?string $stored): array
    {
        return $stored === null ? [] : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * One atomic conditional write: it fails when another process wrote the record since it was read.
     *
     * @param string|null $stored the bytes the entries were read from; null when there was no record
     * @param array<array-key, int> $entries
     */
    protected function write(PersistenceInterface $store, string $partition, ?string $stored, array $entries): bool
    {
        $encoded = json_encode($entries, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);

        return $stored === null
            ? $store->initializeIfAbsent($partition, self::KEY, $encoded)
            : $store->writeIfUnchanged($partition, self::KEY, $stored, [self::KEY => $encoded]);
    }
}
