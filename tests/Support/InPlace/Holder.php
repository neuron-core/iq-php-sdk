<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;

/**
 * The state of the run that holds a workflow ID when a new start arrives.
 */
enum Holder
{
    case Suspended;

    case Failed;

    /** It is inside a node body, under a lease that has not passed. */
    case Running;

    /** It is inside a node body, under a lease that has passed: its process is taken for dead. */
    case Dead;

    public function status(): WorkflowStatus
    {
        return match ($this) {
            self::Suspended => WorkflowStatus::Suspended,
            self::Failed => WorkflowStatus::Failed,
            self::Running, self::Dead => WorkflowStatus::Running,
        };
    }

    /**
     * The words that name the holder in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::Suspended => 'a suspended run',
            self::Failed => 'a failed run',
            self::Running => 'a run that is executing',
            self::Dead => 'a run left running under a lease that has passed',
        };
    }

    public function isExecuting(): bool
    {
        return $this === self::Running || $this === self::Dead;
    }

    /**
     * How the last node ends the segment of the holder.
     */
    public function ending(): Ending
    {
        return match ($this) {
            self::Suspended => Ending::Waits,
            self::Failed => Ending::Fails,
            self::Running, self::Dead => Ending::Completes,
        };
    }

    /**
     * Seconds: a lease a test can wait out for a dead holder, the lease of the stage for any other.
     */
    public function lease(): int
    {
        return $this === self::Dead ? 1 : Stage::LEASE;
    }
}
