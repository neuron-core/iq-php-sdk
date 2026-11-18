<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use Closure;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\SyncResult;

use function array_map;
use function in_array;
use function max;
use function preg_replace;
use function str_starts_with;

/**
 * App Sync, the last step of a deploy: it publishes the definitions this
 * process registered and the URL the platform calls. The platform can also
 * ask the endpoint for the same manifest.
 *
 * @internal
 */
final class Sync
{
    /** Seconds: the shortest lease the platform accepts for a deployed definition. Tests may register shorter ones. */
    protected const SHORTEST_LEASE = 60;

    /**
     * @param Closure(): PersistenceInterface $persistence
     */
    public function __construct(
        protected Registry $registry,
        protected Closure $persistence,
        protected PlatformClient $platform,
    ) {
    }

    /**
     * The list is complete and replaces the one the platform holds.
     *
     * @throws IQException unsuitable_persistence or invalid_request, before anything is sent; the reason the
     *                        platform refused the sync for good; report_undelivered, when it gave no usable answer
     */
    public function publish(string $endpointUrl): SyncResult
    {
        $answer = $this->platform->sync($this->manifest($endpointUrl));

        return new SyncResult(
            $answer['modified'],
            $this->retirable($answer['versions'] ?? []),
            $this->stranded($answer['stranded'] ?? []),
        );
    }

    /**
     * What a sync publishes: the body publish() posts, and the one the endpoint answers when the platform asks
     * for a sync. Both are refused for the same reasons.
     *
     * @return array<string, mixed>
     * @throws IQException unsuitable_persistence or invalid_request
     */
    public function manifest(string $endpointUrl): array
    {
        $this->assertStoreIsSharedByProcesses();
        $this->assertPublishable($endpointUrl);

        return [
            'protocols' => [Protocol::VERSION],
            'sdk' => Protocol::SDK,
            'endpoint' => $endpointUrl,
            'definitions' => array_map($this->registration(...), $this->registry->all()),
        ];
    }

    /**
     * The conditional writes of an in-memory or file store do not hold across
     * processes: with it, two workers could run the same start.
     *
     * @throws IQException unsuitable_persistence
     */
    protected function assertStoreIsSharedByProcesses(): void
    {
        $store = ($this->persistence)();

        if ($store instanceof InMemoryPersistence || $store instanceof FilePersistence) {
            throw new IQException(
                'The runs of a deployed application cannot be kept in ' . $store::class . ': its conditional writes do '
                . 'not hold across processes. Give setPersistence() a database or a Redis store.',
                Code::UnsuitablePersistence,
            );
        }
    }

    /**
     * The platform would refuse the sync, or the sync would empty the list the platform holds: the deploy is
     * told before anything is sent.
     *
     * @throws IQException invalid_request
     */
    protected function assertPublishable(string $endpointUrl): void
    {
        $definitions = $this->registry->all();

        if ($definitions === []) {
            throw new IQException(
                'No definition is registered in this process, so nothing was published: an empty list would remove '
                . 'every version the platform holds and strand those with open runs. A bootstrap that did not reach '
                . 'its register() calls is the likeliest cause.',
                Code::InvalidRequest,
            );
        }

        if (!str_starts_with($endpointUrl, 'https://')) {
            // The user and the password a URL may hold stay out of logs and error trackers.
            $shown = preg_replace('~^([a-z][a-z0-9+.-]*://)[^/?#@]*@~i', '$1', $endpointUrl);

            throw new IQException(
                "The endpoint '{$shown}' cannot be published: the platform calls only https URLs.",
                Code::InvalidRequest,
            );
        }

        foreach ($definitions as $definition) {
            if ($definition->leaseTimeout < self::SHORTEST_LEASE) {
                throw new IQException(
                    "Version {$definition->version} of '{$definition->name}' cannot be published with its lease of "
                    . "{$definition->leaseTimeout} seconds: register it with a leaseTimeout of at least "
                    . self::SHORTEST_LEASE . '.',
                    Code::InvalidRequest,
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function registration(Definition $definition): array
    {
        return [
            'definition' => $definition->name,
            'version' => $definition->version,
            'leaseSeconds' => $definition->leaseTimeout,
        ];
    }

    /**
     * A version with no open run can leave the code, unless it is the one new runs start on.
     *
     * @param array<int, array{definition: string, version: int, openRuns: int}> $versions the published versions
     * @return array<string, int[]> name => versions
     */
    protected function retirable(array $versions): array
    {
        $current = [];
        foreach ($this->registry->all() as $definition) {
            $current[$definition->name] = max($current[$definition->name] ?? 0, $definition->version);
        }

        $retirable = [];
        foreach ($versions as ['definition' => $name, 'version' => $version, 'openRuns' => $openRuns]) {
            // A definition this process did not register has no older version here.
            $isOlder = $version < ($current[$name] ?? 0);

            if ($openRuns === 0 && $isOlder && !in_array($version, $retirable[$name] ?? [], true)) {
                $retirable[$name][] = $version;
            }
        }

        return $retirable;
    }

    /**
     * @param array<int, array{definition: string, version: int, openRuns: int}> $versions gone from the code, runs remain
     * @return array<string, array<int, int>> name => [version => open runs]
     */
    protected function stranded(array $versions): array
    {
        $stranded = [];

        foreach ($versions as $gone) {
            $stranded[$gone['definition']][$gone['version']] = $gone['openRuns'];
        }

        return $stranded;
    }
}
