<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Units;

use Closure;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;

/**
 * PHPUnit's expectException() cannot read the reason of an IQException,
 * which is what tells a caller what to do next.
 */
trait CatchesIQExceptions
{
    /**
     * @param Closure(): mixed $call
     * @return IQException|null null when the call threw nothing
     */
    protected static function thrownBy(Closure $call): ?IQException
    {
        try {
            $call();
        } catch (IQException $thrown) {
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
