<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;

use function array_keys;
use function max;
use function min;
use function preg_match;

/**
 * The definitions this process can build, by name and version.
 *
 * @internal
 */
final class Registry
{
    protected const NAME = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    /** A run ID minted by the SDK or the platform carries its version at the front. */
    protected const MINTED_RUN_ID = '/^([1-9][0-9]{0,5})-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    protected const HIGHEST_VERSION = 999999;

    /** @var array<string, array<int, Definition>> */
    protected array $definitions = [];

    /**
     * @throws CloudException when the protocol could not carry the name, the version or the lease
     */
    public function register(Definition $definition): void
    {
        if (preg_match(self::NAME, $definition->name) !== 1) {
            throw new CloudException(
                "'{$definition->name}' cannot name a definition: use at most 64 lowercase letters, digits, dots, "
                . 'underscores or hyphens, starting with a letter or a digit.',
                Code::InvalidRequest,
            );
        }

        if ($definition->version < 1 || $definition->version > self::HIGHEST_VERSION) {
            throw new CloudException(
                "Version {$definition->version} of '{$definition->name}' cannot be carried by a run ID: "
                . 'use a number from 1 to ' . self::HIGHEST_VERSION . '.',
                Code::InvalidRequest,
            );
        }

        if ($definition->leaseTimeout < 1) {
            throw new CloudException(
                "A lease of {$definition->leaseTimeout} seconds cannot be given to '{$definition->name}': use at least 1 second.",
                Code::InvalidRequest,
            );
        }

        $this->definitions[$definition->name][$definition->version] = $definition;
    }

    /**
     * The definition a run executes on.
     *
     * @throws CloudException unknown_definition, unknown_version
     */
    public function pinned(string $name, string $runId): Definition
    {
        $versions = $this->versionsOf($name);
        $version = $this->pinnedVersion($versions, $runId);

        return $versions[$version] ?? throw new CloudException(
            "Version {$version} of '{$name}' is not registered in this process.",
            Code::UnknownVersion,
        );
    }

    /**
     * A definition to read the store with: every version of a name shares one
     * store, so the current one stands in for a version this process lacks.
     *
     * @throws CloudException unknown_definition
     */
    public function reader(string $name, string $runId): Definition
    {
        $versions = $this->versionsOf($name);

        return $versions[$this->pinnedVersion($versions, $runId)] ?? $versions[max(array_keys($versions))];
    }

    /**
     * @return non-empty-array<int, Definition>
     * @throws CloudException unknown_definition
     */
    protected function versionsOf(string $name): array
    {
        return $this->definitions[$name] ?? throw new CloudException(
            "No definition is registered as '{$name}' in this process.",
            Code::UnknownDefinition,
        );
    }

    /**
     * The version of a run is the number at the front of its run ID. A run ID
     * the SDK did not mint belongs to a run started outside it, which counts
     * as the lowest registered version.
     *
     * @param non-empty-array<int, Definition> $versions
     */
    protected function pinnedVersion(array $versions, string $runId): int
    {
        return preg_match(self::MINTED_RUN_ID, $runId, $matches) === 1 ? (int) $matches[1] : min(array_keys($versions));
    }
}
