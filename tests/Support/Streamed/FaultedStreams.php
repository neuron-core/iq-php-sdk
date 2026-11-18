<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Streamed;

use NeuronCore\IQ\Tests\Stub\Streamed\CloseTaleNode;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Stub\Streamed\OpenTaleNode;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\StoreFault;
use NeuronCore\IQ\Tests\Support\Faults\StoreOperation;
use NeuronCore\IQ\Tests\Support\TestBench;

use function count;
use function is_array;
use function str_ends_with;

/**
 * One segment streamed in the calling process while the store fails once:
 * with no fault first, then once for every operation the healthy stream
 * asks of the store while its consumer takes it, each time on a stage of its
 * own. After each stream the store is healthy again and a worker runs what
 * the stream left in the queue.
 */
final class FaultedStreams
{
    /**
     * @param FaultedStream $clean the stream with no fault
     * @param FaultedStream[] $faulted the stream once per operation the fault hit
     */
    protected function __construct(public readonly FaultedStream $clean, public readonly array $faulted)
    {
    }

    public static function play(string $backend, Kind $kind, Ending $ending, FaultMode $mode): self
    {
        $clean = self::stream($backend, $kind, $ending, $mode, StoreFault::none(), 'no fault');
        $faulted = [];

        for ($nth = 1; $nth <= $clean->exposed; $nth++) {
            // Preparing the segment asks the store too: the fault spares those operations and counts from the first step.
            $fault = StoreFault::at($mode, $clean->spared + $nth);
            $faulted[] = self::stream($backend, $kind, $ending, $mode, $fault, "fault {$nth} of {$clean->exposed}");
        }

        return new self($clean, $faulted);
    }

    protected static function stream(string $backend, Kind $kind, Ending $ending, FaultMode $mode, StoreFault $fault, string $label): FaultedStream
    {
        $bench = TestBench::on($backend);

        try {
            $stage = Stage::on($bench, $kind, $ending);
            $bodiesRun = self::bodiesRun($bench);
            $runsCreated = $stage->journal->ignitions(Stage::WORKFLOW_ID);
            $operations = $stage->journal->exposedTo($mode);

            $stage->failStore($fault);
            $events = $stage->stream();
            $spared = $stage->journal->exposedTo($mode) - $operations;
            $taken = $stage->take($events);
            $stage->failStore(StoreFault::none());

            $struck = $fault->struck();
            $exposed = $stage->journal->exposedTo($mode) - $operations - $spared;
            $bodiesRun = [
                OpenTaleNode::class => self::bodiesRun($bench)[OpenTaleNode::class] - $bodiesRun[OpenTaleNode::class],
                CloseTaleNode::class => self::bodiesRun($bench)[CloseTaleNode::class] - $bodiesRun[CloseTaleNode::class],
            ];
            $runsCreated = $stage->journal->ignitions(Stage::WORKFLOW_ID) - $runsCreated;
            $queued = $stage->queued();
            $requestsOfTheCaller = $stage->requestsOfTheCaller();

            $stage->drain();

            return new FaultedStream(
                $struck instanceof StoreOperation ? "{$label}, {$struck->describe()}" : $label,
                $struck,
                $taken,
                $queued,
                $bodiesRun,
                $runsCreated,
                $stage->attemptsOpened(),
                $requestsOfTheCaller,
                $spared,
                $exposed,
                self::lastReport($bench),
                $stage->stored(),
            );
        } finally {
            $bench->cleanUp();
        }
    }

    /**
     * @return array<class-string, int>
     */
    protected static function bodiesRun(TestBench $bench): array
    {
        return [
            OpenTaleNode::class => $bench->executions->count(OpenTaleNode::class),
            CloseTaleNode::class => $bench->executions->count(CloseTaleNode::class),
        ];
    }

    /**
     * @return array<string, mixed>|null the last report the platform received
     */
    protected static function lastReport(TestBench $bench): ?array
    {
        $received = $bench->platform->received();

        for ($index = count($received) - 1; $index >= 0; $index--) {
            if (str_ends_with($received[$index]->uri, '/v1/reports') && is_array($received[$index]->body)) {
                return $received[$index]->body;
            }
        }

        return null;
    }
}
