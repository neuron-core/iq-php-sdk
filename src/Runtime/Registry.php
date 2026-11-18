<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;

use function array_keys;
use function max;
use function min;

/**
 * The definitions this process can build, by name and version.
 *
 * @internal
 */
final class Registry
{
    protected const HIGHEST_VERSION = 999999;

    /** @var array<string, array<int, Definition>> */
    protected array $definitions = [];

    /**
     * @throws IQException when the protocol could not carry the name, the version or the lease
     */
    public function register(Definition $definition): void
    {
        if (!Format::isDefinition($definition->name)) {
            throw new IQException(
                "'{$definition->name}' cannot name a definition: use at most 64 lowercase letters, digits, dots, "
                . 'underscores or hyphens, starting with a letter or a digit.',
                Code::InvalidRequest,
            );
        }

        if ($definition->version < 1 || $definition->version > self::HIGHEST_VERSION) {
            throw new IQException(
                "Version {$definition->version} of '{$definition->name}' cannot be carried by a run ID: "
                . 'use a number from 1 to ' . self::HIGHEST_VERSION . '.',
                Code::InvalidRequest,
            );
        }

        if ($definition->leaseTimeout < 1) {
            throw new IQException(
                "A lease of {$definition->leaseTimeout} seconds cannot be given to '{$definition->name}': use at least 1 second.",
                Code::InvalidRequest,
            );
        }

        $this->definitions[$definition->name][$definition->version] = $definition;
    }

    /**
     * The definition a run executes on.
     *
     * @throws IQException unknown_definition, unknown_version
     */
    public function pinned(string $name, string $runId): Definition
    {
        $versions = $this->versionsOf($name);
        $version = $this->pinnedVersion($versions, $runId);

        return $versions[$version] ?? throw new IQException(
            "Version {$version} of '{$name}' is not registered in this process.",
            Code::UnknownVersion,
        );
    }

    /**
     * The definition a new run starts on: the highest registered version.
     *
     * @throws IQException unknown_definition
     */
    public function current(string $name): Definition
    {
        $versions = $this->versionsOf($name);

        return $versions[max(array_keys($versions))];
    }

    /**
     * A definition to read the store with: every version of a name shares one
     * store, so the current one stands in for a version this process lacks.
     *
     * @throws IQException unknown_definition
     */
    public function reader(string $name, string $runId): Definition
    {
        $versions = $this->versionsOf($name);

        return $versions[$this->pinnedVersion($versions, $runId)] ?? $this->current($name);
    }

    /**
     * Every registered version of every definition: the list a sync publishes.
     *
     * @return Definition[]
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->definitions as $versions) {
            foreach ($versions as $definition) {
                $all[] = $definition;
            }
        }

        return $all;
    }

    /**
     * @return non-empty-array<int, Definition>
     * @throws IQException unknown_definition
     */
    protected function versionsOf(string $name): array
    {
        return $this->definitions[$name] ?? throw new IQException(
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
        return Format::mintedVersion($runId) ?? min(array_keys($versions));
    }
}
