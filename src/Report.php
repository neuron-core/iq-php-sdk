<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use NeuronCore\Cloud\Runtime\Protocol;

/**
 * How one call ended and what the store held just afterwards: what invoke()
 * returns and what the platform receives.
 */
final class Report
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
    ) {
    }

    /**
     * @internal the SDK serializes its own reports
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
}
