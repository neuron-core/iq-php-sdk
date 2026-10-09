<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\StreamedChat;

use Closure;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Testing\FakeDispatcher;
use RuntimeException;

/**
 * The fake queue, as the tests of a chat application need it: it shows what it
 * was handed, it can go down, and it lets another request be served at the
 * instant a message arrives, as two requests of one thread interleave.
 */
class ChatQueue extends FakeDispatcher
{
    protected bool $down = false;

    /** @var (Closure(): void)|null */
    protected ?Closure $beforeTheNextMessage = null;

    public function goDown(): void
    {
        $this->down = true;
    }

    public function comeBack(): void
    {
        $this->down = false;
    }

    /**
     * @param Closure(): void $act called once, when the next message arrives and before the queue takes it
     */
    public function beforeTheNextMessage(Closure $act): void
    {
        $this->beforeTheNextMessage = $act;
    }

    public function dispatch(Invocation $invocation): void
    {
        if ($this->down) {
            throw new RuntimeException('The queue is down.');
        }

        $act = $this->beforeTheNextMessage;
        $this->beforeTheNextMessage = null;

        if ($act instanceof Closure) {
            $act();
        }

        parent::dispatch($invocation);
    }

    /**
     * @return Invocation[] every message the queue took, in order
     */
    public function taken(): array
    {
        return $this->dispatched;
    }
}
