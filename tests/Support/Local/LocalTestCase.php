<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Local;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\Runner\InspectableDispatcher;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Tests\Support\Units\Queued;
use NeuronCore\IQ\Verb;

use function array_map;

/**
 * A test of what the application's own code calls: it reads what the call
 * handed to the queue, and lets the fake worker run it.
 */
abstract class LocalTestCase extends IQTestCase
{
    use CatchesIQExceptions;

    protected const WORKFLOW_ID = Queued::WORKFLOW_ID;

    protected const RUN_ID = Queued::RUN_ID;

    /** The queue the service hands its messages to, in place of the bench's own, whose messages cannot be read. */
    protected InspectableDispatcher $queue;

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);

        $this->queue = new InspectableDispatcher();
        $bench->iq->setDispatcher($this->queue);

        return $bench;
    }

    /**
     * @return Verb[] the kind of every message handed to the queue, in order
     */
    protected function verbsQueued(): array
    {
        return array_map(static fn (Invocation $message): Verb => $message->verb, $this->queue->dispatched());
    }
}
