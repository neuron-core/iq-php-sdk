<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Matrix;

use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;

/**
 * The state of the first copy of an invocation when the second one arrives.
 */
enum FirstCopy
{
    /** It is inside a node body. */
    case Executing;

    case Suspended;

    /** It completed the run and the platform recorded its report. */
    case Completed;

    /** It completed the run and its report did not reach the platform. */
    case CompletedReportLost;

    /**
     * It completed the run while the platform was unreachable, for its
     * announcement as for its report. The platform is still unreachable when
     * the second copy arrives.
     */
    case CompletedPlatformUnreachable;

    case Failed;

    /**
     * How the last node ends the segment of the first copy.
     */
    public function ending(): Ending
    {
        return match ($this) {
            self::Suspended => Ending::Waits,
            self::Failed => Ending::Fails,
            default => Ending::Completes,
        };
    }

    /**
     * How the first copy ends, once it has.
     */
    public function outcome(): Outcome
    {
        return match ($this) {
            self::Suspended => Outcome::Suspended,
            self::Failed => Outcome::Failed,
            default => Outcome::Completed,
        };
    }

    /**
     * How many notices the two copies leave in the queue for the platform.
     */
    public function noticesLeftInQueue(): int
    {
        return match ($this) {
            // The observe that stands in for the lost report.
            self::CompletedReportLost => 1,
            // The announcement and the observe of each copy.
            self::CompletedPlatformUnreachable => 4,
            default => 0,
        };
    }
}
