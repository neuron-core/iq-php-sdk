<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use JsonSerializable;
use NeuronCore\Cloud\Runtime\Protocol;
use Throwable;

use function get_object_vars;

/**
 * How one call ended and what the store held just afterwards: what invoke()
 * returns and what the platform receives.
 */
final class Report implements JsonSerializable
{
    /**
     * @internal the SDK builds its own reports
     *
     * @param Verb $verb the kind of segment: start, wake, answer or signal
     * @param string $origin 'platform' or 'application'
     * @param string|null $code a RefusalReason value for refused; an SDK code for rejected and aborted
     * @param string|null $errorClass the exception class of a failed or aborted call, never its message
     * @param int|null $executedAttempt the attempt this call executed under; null when it opened no segment
     * @param array<string, mixed>|null $run the run holding the workflow ID when the report was built, or null
     * @param bool|null $claimed whether a start's run ID is in the ledger; null when that is not known
     * @param bool $snapshotRead false when the store could not be read: $run and $claimed then mean nothing
     * @param Throwable|null $exception what a failed or aborted call threw, in the process that executed it and
     *                                  nowhere else: it is no field of the protocol, toArray() leaves it out, a
     *                                  report encoded as JSON does not hold it, and a report that is serialized
     *                                  comes back without it
     */
    public function __construct(
        public readonly string $invocationId,
        public readonly Verb $verb,
        public readonly string $origin,
        public readonly string $definition,
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly Outcome $outcome,
        public readonly ?string $code,
        public readonly ?string $errorClass,
        public readonly ?int $executedAttempt,
        public readonly ?array $run,
        public readonly ?bool $claimed,
        public readonly bool $snapshotRead,
        public readonly int $at,
        public readonly ?Throwable $exception = null,
    ) {
    }

    /**
     * The report as the platform receives it, field by field.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'protocol' => Protocol::VERSION,
            'invocationId' => $this->invocationId,
            'verb' => $this->verb->value,
            'origin' => $this->origin,
            'definition' => $this->definition,
            'workflowId' => $this->workflowId,
            'runId' => $this->runId,
            'outcome' => $this->outcome->value,
            'code' => $this->code,
            'errorClass' => $this->errorClass,
            'executedAttempt' => $this->executedAttempt,
            'run' => $this->run,
            'claimed' => $this->claimed,
            'snapshotRead' => $this->snapshotRead,
            'at' => $this->at,
        ];
    }

    /**
     * A report encoded as JSON is the report of the protocol. An exception
     * can have public properties of its own, as the HTTP exception of a
     * provider has its request and its response: encoded with the report,
     * they would reach a log or a channel.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * An exception of the application holds its message and its trace, which
     * never leave the process: a report that a queue or a cache keeps is kept
     * without it.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $fields = get_object_vars($this);
        unset($fields['exception']);

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function __unserialize(array $fields): void
    {
        $this->invocationId = $fields['invocationId'];
        $this->verb = $fields['verb'];
        $this->origin = $fields['origin'];
        $this->definition = $fields['definition'];
        $this->workflowId = $fields['workflowId'];
        $this->runId = $fields['runId'];
        $this->outcome = $fields['outcome'];
        $this->code = $fields['code'];
        $this->errorClass = $fields['errorClass'];
        $this->executedAttempt = $fields['executedAttempt'];
        $this->run = $fields['run'];
        $this->claimed = $fields['claimed'];
        $this->snapshotRead = $fields['snapshotRead'];
        $this->at = $fields['at'];
        $this->exception = null;
    }
}
