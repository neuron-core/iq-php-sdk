<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Matrix;

use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Stub\Matrix\Opening;

/**
 * The kinds of invocation the redelivery matrix delivers twice.
 */
enum Kind
{
    /** A start the platform sent. */
    case PlatformStart;

    /** A start the application dispatched: it carries its serialized event. */
    case DispatchedStart;

    case Answer;

    case Signal;

    /** A wake for a run suspended on a timer that is due. */
    case WakeOfDueTimer;

    case WakeOfFailedRun;

    /** A wake for a run that is running under a lease that has passed. */
    case WakeOfDeadRun;

    public function startsTheRun(): bool
    {
        return $this === self::PlatformStart || $this === self::DispatchedStart;
    }

    /**
     * What the first node of the run does, so that the invocation has the run it needs.
     */
    public function opening(): Opening
    {
        return match ($this) {
            self::PlatformStart, self::DispatchedStart => Opening::Passes,
            self::Answer, self::Signal => Opening::AwaitsEvent,
            self::WakeOfDueTimer => Opening::Sleeps,
            self::WakeOfFailedRun, self::WakeOfDeadRun => Opening::FailsOnce,
        };
    }

    /**
     * The status of the run an invocation of this kind continues; null for a start, which has no run yet.
     */
    public function continues(): ?WorkflowStatus
    {
        return match ($this) {
            self::PlatformStart, self::DispatchedStart => null,
            self::Answer, self::Signal, self::WakeOfDueTimer => WorkflowStatus::Suspended,
            self::WakeOfFailedRun => WorkflowStatus::Failed,
            self::WakeOfDeadRun => WorkflowStatus::Running,
        };
    }
}
