<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\InPlace;

use Closure;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Testing\FakeDispatcher;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * The fake queue with a test at its door: it sees each message before the
 * queue takes it, to let another process act first or to keep the message out
 * by throwing, and it notes how far the node bodies had got by then.
 */
class WatchedQueue extends FakeDispatcher
{
    /** @var (Closure(Invocation): void)|null */
    protected ?Closure $beforeTaking = null;

    /** @var int[] how many node bodies had run when each message it took was handed over, in order */
    protected array $bodiesRunBefore = [];

    public function __construct(protected ExecutionLog $executions)
    {
    }

    /**
     * @param Closure(Invocation): void $act called with each message before the queue takes it: what it throws, the queue throws
     */
    public function beforeTaking(Closure $act): void
    {
        $this->beforeTaking = $act;
    }

    public function dispatch(Invocation $invocation): void
    {
        $bodiesRun = $this->executions->total();

        if ($this->beforeTaking instanceof Closure) {
            ($this->beforeTaking)($invocation);
        }

        parent::dispatch($invocation);
        $this->bodiesRunBefore[] = $bodiesRun;
    }

    /**
     * @return Invocation[] every message the queue took, in order
     */
    public function taken(): array
    {
        return $this->dispatched;
    }

    /**
     * @return int[] how many node bodies had run when each message of taken() was handed over
     */
    public function bodiesRunBefore(): array
    {
        return $this->bodiesRunBefore;
    }
}
