<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Runner;

use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use WeakReference;

/**
 * Its node throws the first time it runs. Every instance is watched, the
 * copies Neuron binds to a workflow ID included, so that a test can tell
 * whether the one that executed is still alive.
 */
class WatchedWorkflow extends FlakyWorkflow
{
    /** @var array<int, WeakReference<self>> every instance that ever existed, oldest first */
    public static array $instances = [];

    public function __construct(ExecutionLog $executions)
    {
        parent::__construct($executions);

        self::$instances[] = WeakReference::create($this);
    }

    public function __clone()
    {
        self::$instances[] = WeakReference::create($this);
    }

    /**
     * How many instances are still alive.
     */
    public static function alive(): int
    {
        $alive = 0;

        foreach (self::$instances as $instance) {
            $alive += (int) ($instance->get() instanceof self);
        }

        return $alive;
    }
}
