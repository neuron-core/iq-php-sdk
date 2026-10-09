<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Streamed;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Support\Faults\StoreOperation;
use NeuronCore\Cloud\Verb;

use function count;

/**
 * What one segment streamed in the calling process left behind when the
 * store failed once while its consumer was taking it, and what a worker then
 * told the platform.
 */
final class FaultedStream
{
    /**
     * @param string $label names the case in a failure message
     * @param StoreOperation|null $struck the operation the fault hit; null when the stream had no fault
     * @param Taken $taken what the consumer got
     * @param Invocation[] $queued the messages the queue took, in order
     * @param array<class-string, int> $bodiesRun how many times each node body ran while the stream was taken
     * @param int $runsCreated how many times the store created a run for the workflow ID meanwhile
     * @param int[] $attemptsOpened the attempt of every segment Neuron opened for the stream
     * @param int $requestsOfTheCaller how many HTTP requests the process that streams made
     * @param int $spared how many operations a fault could have hit before the stream was taken, while its segment was prepared
     * @param int $exposed how many operations of the stream a fault could have hit
     * @param array<string, mixed>|null $reported the report a worker posted once it ran the queue
     * @param array<string, mixed>|null $leftWhenReported the run the store held when that report was posted
     */
    public function __construct(
        public readonly string $label,
        public readonly ?StoreOperation $struck,
        public readonly Taken $taken,
        public readonly array $queued,
        public readonly array $bodiesRun,
        public readonly int $runsCreated,
        public readonly array $attemptsOpened,
        public readonly int $requestsOfTheCaller,
        public readonly int $spared,
        public readonly int $exposed,
        public readonly ?array $reported,
        public readonly ?array $leftWhenReported,
    ) {
    }

    /**
     * The only observe the queue took, or null when it took none or several.
     *
     * @return array<string, mixed>|null what it carries
     */
    public function observed(): ?array
    {
        $observes = [];
        foreach ($this->queued as $message) {
            if ($message->verb === Verb::Observe) {
                $observes[] = $message->body;
            }
        }

        return count($observes) === 1 ? $observes[0] : null;
    }
}
