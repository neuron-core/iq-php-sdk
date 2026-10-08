<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use ReflectionClass;
use Throwable;

/**
 * How one call ended, before the store is read for its report.
 *
 * @internal
 */
final class Settlement
{
    /**
     * @param string|null $code a RefusalReason value for refused; an SDK code for rejected and aborted
     * @param int|null $executedAttempt the attempt of the segment this call opened; null when it opened none
     * @param bool|null $claimed true when a start's run ID is known to be in the ledger; null when the ledger was not read
     * @param Throwable|null $cause what aborted the call, for the exception invoke() throws; only its class is reported
     */
    protected function __construct(
        public readonly Outcome $outcome,
        public readonly ?string $code = null,
        public readonly ?string $errorClass = null,
        public readonly ?int $executedAttempt = null,
        public readonly ?bool $claimed = null,
        public readonly ?Throwable $cause = null,
    ) {
    }

    public static function ran(WorkflowStatus $status, ?int $executedAttempt): self
    {
        return new self(
            $status === WorkflowStatus::Completed ? Outcome::Completed : Outcome::Suspended,
            executedAttempt: $executedAttempt,
        );
    }

    /**
     * A throwable surfaced after a segment opened: a node threw, or the store failed while Neuron wrote.
     */
    public static function failed(Throwable $error, int $executedAttempt): self
    {
        return new self(Outcome::Failed, errorClass: self::classOf($error), executedAttempt: $executedAttempt);
    }

    public static function refused(RefusalReason $reason, ?int $executedAttempt = null): self
    {
        return new self(Outcome::Refused, $reason->value, executedAttempt: $executedAttempt);
    }

    public static function rejected(Code $code): self
    {
        return new self(Outcome::Rejected, $code->value);
    }

    public static function aborted(Code $code, ?Throwable $cause = null): self
    {
        return new self(
            Outcome::Aborted,
            $code->value,
            $cause instanceof Throwable ? self::classOf($cause) : null,
            cause: $cause,
        );
    }

    /**
     * The same ending, for a start whose run ID this call wrote in the ledger or found there.
     */
    public function withClaim(): self
    {
        return new self($this->outcome, $this->code, $this->errorClass, $this->executedAttempt, true, $this->cause);
    }

    /**
     * The report fields this call settled. An observe carries them, so that
     * the report it posts later still says how the segment ended.
     *
     * @return array<string, mixed>
     */
    public function facts(Invocation $segment): array
    {
        return [
            'invocationId' => $segment->invocationId,
            'verb' => $segment->verb->value,
            'outcome' => $this->outcome->value,
            'code' => $this->code,
            'errorClass' => $this->errorClass,
            'executedAttempt' => $this->executedAttempt,
        ];
    }

    /**
     * PHP names an anonymous class after its parent, a NUL byte and the path and line of the file that
     * declares it, which must not leave the application: its nearest named parent is reported instead.
     */
    protected static function classOf(Throwable $error): string
    {
        $class = new ReflectionClass($error);

        while ($class->isAnonymous() && $class->getParentClass() instanceof ReflectionClass) {
            $class = $class->getParentClass();
        }

        return $class->getName();
    }
}
