<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use NeuronAI\UniqueIdGenerator;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Verb;

use function time;

/**
 * The two notices the SDK sends the platform about a segment, and the form in
 * which each one waits in the application's queue.
 *
 * @internal
 */
final class Notices
{
    /**
     * "A segment of this run is starting; its lease is $leaseSeconds."
     *
     * @return array<string, mixed> the body the platform receives
     */
    public static function announcement(Invocation $segment, int $leaseSeconds): array
    {
        return [
            'protocol' => Protocol::VERSION,
            'invocationId' => $segment->invocationId,
            'verb' => $segment->verb->value,
            'origin' => $segment->origin,
            'definition' => $segment->definition,
            'workflowId' => $segment->workflowId,
            'runId' => $segment->runId,
            'attempt' => $segment->attempt,
            'leaseSeconds' => $leaseSeconds,
            'at' => time(),
        ];
    }

    /**
     * A prepared announcement, for a worker to post later.
     *
     * @param array<string, mixed> $announcement
     */
    public static function announce(Invocation $segment, array $announcement): Invocation
    {
        return self::about($segment, Verb::Announce, 'ann_', $announcement);
    }

    /**
     * "Read the store and tell the platform", for a worker to do later.
     *
     * @param array<string, mixed> $facts the report fields already settled: the segment's invocation ID and verb,
     *                                    and how it ended when that is known
     */
    public static function observe(Invocation $segment, array $facts): Invocation
    {
        return self::about($segment, Verb::Observe, 'obs_', $facts);
    }

    /**
     * A notice is a queue message of its own, so it gets its own ID: its body names the segment it speaks for.
     *
     * @param array<string, mixed> $body
     */
    protected static function about(Invocation $segment, Verb $verb, string $idPrefix, array $body): Invocation
    {
        return new Invocation(
            UniqueIdGenerator::generateId($idPrefix),
            $verb,
            $segment->origin,
            $segment->definition,
            $segment->workflowId,
            $segment->runId,
            body: $body,
        );
    }
}
