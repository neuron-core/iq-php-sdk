<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Streamed;

use NeuronAI\Workflow\WorkflowStatus;

/**
 * The run that holds a workflow ID when a new start is streamed.
 */
enum Holder
{
    case Suspended;

    case Failed;

    /** Another request is streaming it, under a lease that has not passed. */
    case Running;

    /** It was left running under a lease that has passed: its process is taken for dead. */
    case Dead;

    /** It completed outside the SDK, which kept its outcome until someone acknowledges it. */
    case Completed;

    public function status(): WorkflowStatus
    {
        return match ($this) {
            self::Suspended => WorkflowStatus::Suspended,
            self::Failed => WorkflowStatus::Failed,
            self::Running, self::Dead => WorkflowStatus::Running,
            self::Completed => WorkflowStatus::Completed,
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
            self::Running => 'a run another request is streaming',
            self::Dead => 'a run left running under a lease that has passed',
            self::Completed => 'a completion retained outside the SDK',
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
