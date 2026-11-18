<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function max;
use function min;
use function preg_match;

/**
 * The list of definitions and versions that the last sync published.
 */
final class Manifest
{
    /** A run ID minted by the SDK or the platform carries its version at the front. */
    protected const MINTED_RUN_ID = '/^([1-9][0-9]{0,5})-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    public function __construct(protected Database $database)
    {
    }

    /**
     * @return array<int, array{definition: string, version: int, leaseSeconds: int}> in the order the sync named them
     */
    public function all(): array
    {
        return $this->database->rows(
            'SELECT definition, version, lease_seconds AS leaseSeconds FROM manifest ORDER BY position',
        );
    }

    /**
     * The list is complete and replaces the previous one.
     *
     * @param array<int, array{definition: string, version: int, leaseSeconds: int}> $registrations
     * @return bool whether the list changed
     */
    public function replace(array $registrations): bool
    {
        $before = $this->all();

        $this->database->write('DELETE FROM manifest');
        foreach ($registrations as $position => $registration) {
            $this->database->write(
                'INSERT OR REPLACE INTO manifest (definition, version, lease_seconds, position) VALUES (?, ?, ?, ?)',
                [$registration['definition'], $registration['version'], $registration['leaseSeconds'], $position],
            );
        }

        return $this->all() !== $before;
    }

    public function holds(string $definition, int $version): bool
    {
        return $this->database->value(
            'SELECT 1 FROM manifest WHERE definition = ? AND version = ?',
            [$definition, $version],
        ) !== null;
    }

    /**
     * @return int|null the version new runs start on; null when the definition is not published
     */
    public function currentVersion(string $definition): ?int
    {
        $versions = $this->versionsOf($definition);

        return $versions === [] ? null : max($versions);
    }

    /**
     * The version a run is pinned to: the number at the front of its run ID. A run ID that was not minted
     * belongs to a run started outside the SDK and counts as the lowest version.
     */
    public function versionOf(string $definition, string $runId): int
    {
        if (preg_match(self::MINTED_RUN_ID, $runId, $matches) === 1) {
            return (int) $matches[1];
        }

        $versions = $this->versionsOf($definition);

        return $versions === [] ? 1 : min($versions);
    }

    /**
     * @return int[]
     */
    protected function versionsOf(string $definition): array
    {
        return $this->database->column('SELECT version FROM manifest WHERE definition = ?', [$definition]);
    }
}
