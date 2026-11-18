<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;

use function is_array;
use function ksort;
use function preg_match;

/**
 * The run a store holds for a workflow ID, read with Neuron's engine alone
 * and told in the words of the protocol: what the "run" of a report must say.
 */
final class StoredRun
{
    /** The form of a definition name, as appendix A of the design gives it. */
    protected const DEFINITION = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    /**
     * @return array<string, mixed>|null null when no run holds the workflow ID
     */
    public static function of(PersistenceInterface $store, string $workflowId): ?array
    {
        $snapshot = (new WorkflowEngine($store))->inspect($workflowId);

        if (!$snapshot instanceof WorkflowRunSnapshot) {
            return null;
        }

        $run = [
            'runId' => $snapshot->runId,
            'definition' => preg_match(self::DEFINITION, (string) $snapshot->tag) === 1 ? $snapshot->tag : null,
            'status' => $snapshot->status->value,
            'attempt' => $snapshot->executionAttempt,
            'leaseExpiresAt' => $snapshot->leaseExpiresAt,
        ];

        if ($snapshot->status === WorkflowStatus::Suspended && $snapshot->interrupt instanceof InterruptRequest) {
            $run['wait'] = self::wait($snapshot->interrupt);
        }

        return $run;
    }

    /**
     * Whether two runs say the same: the order of the fields of a JSON object means nothing.
     *
     * @param array<string, mixed>|null $one
     * @param array<string, mixed>|null $other
     */
    public static function same(?array $one, ?array $other): bool
    {
        return self::sorted($one) === self::sorted($other);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function wait(InterruptRequest $request): array
    {
        $wakeAt = match (true) {
            $request instanceof SleepUntilRequest => $request->getWakeAt(),
            $request instanceof WaitForEventRequest => $request->getExpiresAt(),
            default => null,
        };

        return [
            'interruptId' => $request->getId(),
            'type' => $request->type()->value,
            'eventName' => $request instanceof WaitForEventRequest ? $request->getEventName() : null,
            'wakeAt' => $wakeAt?->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed>|null $run
     * @return array<string, mixed>|null
     */
    protected static function sorted(?array $run): ?array
    {
        if ($run === null) {
            return null;
        }

        ksort($run);
        foreach ($run as $field => $value) {
            $run[$field] = is_array($value) ? self::sorted($value) : $value;
        }

        return $run;
    }
}
