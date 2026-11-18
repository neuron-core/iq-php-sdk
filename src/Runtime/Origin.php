<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

/**
 * Who issued an invocation.
 *
 * @internal
 */
final class Origin
{
    /** It arrived through the endpoint. */
    public const PLATFORM = 'platform';

    /** The application's own code created it. */
    public const APPLICATION = 'application';
}
