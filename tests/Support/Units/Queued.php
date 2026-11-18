<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Units;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Notices;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Verb;

use function time;

/**
 * Invocations as they wait in the application's queue.
 */
final class Queued
{
    public const WORKFLOW_ID = 'order:123';

    public const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /**
     * A start that can still be used for a quarter of an hour.
     */
    public static function start(
        string $definition,
        string $origin = Origin::PLATFORM,
        string $workflowId = self::WORKFLOW_ID,
        string $runId = self::RUN_ID,
    ): Invocation {
        return new Invocation('inv_start', Verb::Start, $origin, $definition, $workflowId, $runId, expiresAt: time() + 900);
    }

    /**
     * The announcement of a segment, as its job leaves it in the queue when the platform did not record it.
     */
    public static function announcementOf(Invocation $segment): Invocation
    {
        return Notices::announce($segment, Notices::announcement($segment, 600));
    }
}
