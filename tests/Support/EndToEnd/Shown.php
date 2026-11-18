<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

/**
 * The run object of the protocol, as a test writes one: what the store of an
 * application would show of a run in a report or in the answer to an inspect.
 * Every run is one the played application started through the SDK, unless
 * the test names another definition, or none: a run with no tag.
 */
final class Shown
{
    /**
     * @param int|null $wakeAt the time of the timer, or the deadline of the event wait; null: a wait with no timer
     * @param string|null $eventName the event the run waits for; null: the run sleeps
     * @param string|null $definition the definition that started the run; null: the run carries no tag
     * @return array<string, mixed>
     */
    public static function suspended(
        string $runId,
        int $attempt,
        ?int $wakeAt,
        int $interruptId = 1,
        ?string $eventName = null,
        ?string $definition = PlayedApplication::DEFINITION,
    ): array {
        return [
            'runId' => $runId,
            'definition' => $definition,
            'status' => 'suspended',
            'attempt' => $attempt,
            'leaseExpiresAt' => null,
            'wait' => [
                'interruptId' => $interruptId,
                'type' => $eventName === null ? 'sleep_until' : 'wait_for_event',
                'eventName' => $eventName,
                'wakeAt' => $wakeAt,
            ],
        ];
    }

    /**
     * @param string|null $definition the definition that started the run; null: the run carries no tag
     * @return array<string, mixed>
     */
    public static function failed(string $runId, int $attempt, ?string $definition = PlayedApplication::DEFINITION): array
    {
        return ['runId' => $runId, 'definition' => $definition, 'status' => 'failed', 'attempt' => $attempt, 'leaseExpiresAt' => null];
    }

    /**
     * @param int|null $leaseExpiresAt null: a run that runs with no lease, started outside the SDK
     * @param string|null $definition the definition that started the run; null: the run carries no tag
     * @return array<string, mixed>
     */
    public static function running(string $runId, int $attempt, ?int $leaseExpiresAt, ?string $definition = PlayedApplication::DEFINITION): array
    {
        return ['runId' => $runId, 'definition' => $definition, 'status' => 'running', 'attempt' => $attempt, 'leaseExpiresAt' => $leaseExpiresAt];
    }
}
