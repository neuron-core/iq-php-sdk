<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Stub\Matrix\EndingNode;
use NeuronCore\Cloud\Tests\Stub\Matrix\OpeningNode;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Faults\StoreFault;
use NeuronCore\Cloud\Tests\Support\Faults\StoreOperation;
use NeuronCore\Cloud\Tests\Support\TestBench;

use function count;
use function is_array;
use function str_ends_with;

/**
 * One segment run in the calling process while the store fails once: with no
 * fault first, then once for every operation of that clean call the fault can
 * hit, each time on a stage of its own. After each call the store is healthy
 * again and a worker runs what the call left in the queue.
 */
final class FaultedCalls
{
    /**
     * @param FaultedCall $clean the call with no fault
     * @param FaultedCall[] $faulted the call once per operation the fault hit
     */
    protected function __construct(public readonly FaultedCall $clean, public readonly array $faulted)
    {
    }

    public static function play(string $backend, Kind $kind, Ending $ending, FaultMode $mode): self
    {
        $clean = self::call($backend, $kind, $ending, $mode, StoreFault::none(), 'no fault');
        $faulted = [];

        for ($nth = 1; $nth <= $clean->exposed; $nth++) {
            $faulted[] = self::call($backend, $kind, $ending, $mode, StoreFault::at($mode, $nth), "fault {$nth} of {$clean->exposed}");
        }

        return new self($clean, $faulted);
    }

    protected static function call(string $backend, Kind $kind, Ending $ending, FaultMode $mode, StoreFault $fault, string $label): FaultedCall
    {
        $bench = TestBench::on($backend);

        try {
            $stage = Stage::on($bench, $kind, $ending);
            $exposed = $stage->journal->exposedTo($mode);
            $runsCreated = $stage->journal->ignitions(Stage::WORKFLOW_ID);
            $bodiesRun = self::bodiesRun($bench);

            $stage->failStore($fault);
            $played = $stage->play();
            $stage->failStore(StoreFault::none());

            $struck = $fault->struck();
            $bodiesRun = [
                OpeningNode::class => self::bodiesRun($bench)[OpeningNode::class] - $bodiesRun[OpeningNode::class],
                EndingNode::class => self::bodiesRun($bench)[EndingNode::class] - $bodiesRun[EndingNode::class],
            ];
            $exposed = $stage->journal->exposedTo($mode) - $exposed;
            $runsCreated = $stage->journal->ignitions(Stage::WORKFLOW_ID) - $runsCreated;

            $stage->drain();

            return new FaultedCall(
                $struck instanceof StoreOperation ? "{$label}, {$struck->describe()}" : $label,
                $struck,
                $played,
                $bodiesRun,
                $runsCreated,
                $exposed,
                $played->queued === [] ? null : self::lastReport($bench),
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
            OpeningNode::class => $bench->executions->count(OpeningNode::class),
            EndingNode::class => $bench->executions->count(EndingNode::class),
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
