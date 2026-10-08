<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_map;

/**
 * What one call of the application's own code left behind: what the caller
 * got, and what reached the queue, the platform and the node bodies meanwhile.
 */
final class Played
{
    /**
     * @param WorkflowState|string|null $returned the state run() or recover() returned, the run ID dispatch()
     *                                            returned; null when the call threw or recover() had nothing to give
     * @param Throwable|null $thrown what the call threw
     * @param Invocation[] $queued the messages the queue took during the call, in order
     * @param int[] $bodiesRunBefore how many node bodies the call had run when each of them was handed over
     * @param int $bodiesRun how many node bodies ran during the call
     * @param int $requests how many HTTP requests the platform received during the call
     * @param int[] $attemptsOpened the attempt of every segment Neuron opened for the call, as Neuron stamped it
     */
    public function __construct(
        public readonly WorkflowState|string|null $returned,
        public readonly ?Throwable $thrown,
        public readonly array $queued,
        public readonly array $bodiesRunBefore,
        public readonly int $bodiesRun,
        public readonly int $requests,
        public readonly array $attemptsOpened,
    ) {
    }

    /**
     * @return Verb[] the kind of every message the queue took, in order
     */
    public function verbsQueued(): array
    {
        return array_map(static fn (Invocation $message): Verb => $message->verb, $this->queued);
    }

    /**
     * The announcement of the segment, as it waits in the queue.
     */
    public function announce(): Invocation
    {
        return $this->only(Verb::Announce);
    }

    /**
     * The observe of the segment, as it waits in the queue.
     */
    public function observe(): Invocation
    {
        return $this->only(Verb::Observe);
    }

    protected function only(Verb $verb): Invocation
    {
        $found = null;

        foreach ($this->queued as $message) {
            if ($message->verb === $verb) {
                Assert::assertNull($found, "The call queued more than one '{$verb->value}'.");
                $found = $message;
            }
        }

        Assert::assertInstanceOf(Invocation::class, $found, "The call queued no '{$verb->value}'.");

        return $found;
    }
}
