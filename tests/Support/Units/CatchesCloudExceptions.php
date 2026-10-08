<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Units;

use Closure;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;

/**
 * PHPUnit's expectException() cannot read the reason of a CloudException,
 * which is what tells a caller what to do next.
 */
trait CatchesCloudExceptions
{
    /**
     * @param Closure(): mixed $call
     * @return CloudException|null null when the call threw nothing
     */
    protected static function thrownBy(Closure $call): ?CloudException
    {
        try {
            $call();
        } catch (CloudException $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * @param Closure(): mixed $call
     * @return Code|null null when the call threw nothing
     */
    protected static function reasonThrownBy(Closure $call): ?Code
    {
        return self::thrownBy($call)?->reason;
    }
}
