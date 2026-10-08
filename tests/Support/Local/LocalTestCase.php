<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Local;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\Runner\InspectableDispatcher;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use NeuronCore\Cloud\Tests\Support\Units\Queued;
use NeuronCore\Cloud\Verb;

use function array_map;

/**
 * A test of what the application's own code calls: it reads what the call
 * handed to the queue, and lets the fake worker run it.
 */
abstract class LocalTestCase extends CloudTestCase
{
    use CatchesCloudExceptions;

    protected const WORKFLOW_ID = Queued::WORKFLOW_ID;

    protected const RUN_ID = Queued::RUN_ID;

    /** The queue the cloud hands its messages to, in place of the bench's own, whose messages cannot be read. */
    protected InspectableDispatcher $queue;

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);

        $this->queue = new InspectableDispatcher();
        $bench->cloud->setDispatcher($this->queue);

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
