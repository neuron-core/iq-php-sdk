<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * The part of a run the platform may see: identifiers, a status, a lease
 * deadline and what the run waits for. It reads typed getters only, because
 * the JSON form of an approval request carries its message and tool inputs.
 *
 * @internal
 */
final class RunProjection
{
    /**
     * @return array<string, mixed> the "run" object of a report
     */
    public static function of(WorkflowRunSnapshot $snapshot): array
    {
        $run = [
            'runId' => $snapshot->runId,
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
     * @return array<string, mixed>
     */
    protected static function wait(InterruptRequest $request): array
    {
        return [
            'interruptId' => $request->getId(),
            'type' => $request->type()->value,
            'eventName' => $request instanceof WaitForEventRequest ? $request->getEventName() : null,
            'wakeAt' => match (true) {
                $request instanceof SleepUntilRequest => $request->getWakeAt()->getTimestamp(),
                $request instanceof WaitForEventRequest => $request->getExpiresAt()?->getTimestamp(),
                default => null,
            },
        ];
    }
}
