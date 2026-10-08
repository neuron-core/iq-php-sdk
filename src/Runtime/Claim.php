<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

/**
 * What the ledger answered to a start that asked to run.
 *
 * @internal
 */
enum Claim
{
    /** This call wrote the run ID: the start may run, here and only here. */
    case Written;

    /** The run ID was already there: another copy of the start owns it. */
    case AlreadyThere;

    /** The use-by time of the start has passed: nothing was written. */
    case Expired;
}
