<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Faults;

/**
 * The two ways a store fails a caller.
 */
enum FaultMode: string
{
    /** The operation throws and nothing was done. */
    case BeforeOperation = 'the store fails at one operation';

    /** The write is committed and its answer is lost. */
    case AfterWrite = 'the store commits one write and loses its answer';
}
