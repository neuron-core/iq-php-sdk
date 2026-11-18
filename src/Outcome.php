<?php

declare(strict_types=1);

namespace NeuronCore\IQ;

enum Outcome: string
{
    case Completed = 'completed';

    case Suspended = 'suspended';

    case Failed = 'failed';

    /** Neuron refused the request, or the guard before a wake did. */
    case Refused = 'refused';

    /** The SDK refused it for good. */
    case Rejected = 'rejected';

    /** Nothing ran; it may be sent again unless the start was already claimed. */
    case Aborted = 'aborted';

    /** Nothing is known about the call; only the store was read. */
    case Observed = 'observed';
}
