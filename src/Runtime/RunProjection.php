<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use DateTimeImmutable;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;

use function json_decode;
use function json_encode;
use function max;

use const JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * The part of a run the platform may see: identifiers, the definition that
 * started it, a status, a lease deadline and what the run waits for. It reads
 * typed getters only, because the JSON form of an approval request carries
 * its message and tool inputs.
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
            // A tag of another shape is a label of code outside the SDK, which must not cross.
            'definition' => Format::isDefinition($snapshot->tag) ? $snapshot->tag : null,
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
            'eventName' => $request instanceof WaitForEventRequest ? self::utf8($request->getEventName()) : null,
            'wakeAt' => self::instant(match (true) {
                $request instanceof SleepUntilRequest => $request->getWakeAt(),
                $request instanceof WaitForEventRequest => $request->getExpiresAt(),
                default => null,
            }),
        ];
    }

    /**
     * Neuron lets a timer or a deadline be set before 1970, and an instant of protocol 1 is 0 or more: every
     * report and every inspect of its run would break the protocol. Such a time is long due either way.
     */
    protected static function instant(?DateTimeImmutable $time): ?int
    {
        return $time instanceof DateTimeImmutable ? max(0, $time->getTimestamp()) : null;
    }

    /**
     * Neuron lets an event name hold any bytes, and one that is not UTF-8 cannot be put in JSON: every report
     * and every inspect of its run would fail. Its invalid bytes are replaced.
     */
    protected static function utf8(string $name): string
    {
        return json_decode((string) json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
