<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Matrix;

use Closure;

/**
 * What a test does while a node body is running: it runs once, in the first
 * body that reaches it.
 */
class MidBody
{
    /** @var (Closure(): void)|null */
    protected ?Closure $action = null;

    /**
     * @param Closure(): void $action
     */
    public function whenReached(Closure $action): void
    {
        $this->action = $action;
    }

    public function reached(): void
    {
        $action = $this->action;
        // Cleared first: a node body that the action itself makes run must not run the action again.
        $this->action = null;

        if ($action instanceof Closure) {
            $action();
        }
    }
}
