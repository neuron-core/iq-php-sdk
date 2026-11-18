<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Row 2 of section 7 of the design: a copy of a start arrives after its run
 * completed and its records are gone. The ledger outlives the run: the copy
 * is rejected as already claimed.
 */
class Row02StartAfterItsRunCompletedTest extends FailureTableTestCase
{
    protected const ORDER = 'R0201';

    public function test_copies_of_a_start_that_arrive_after_the_order_shipped_are_rejected_and_run_nothing(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->awaitClosed($runId, 'completed');
        self::assertNull($this->stored(self::ORDER), 'The records of the run are gone.');
        [$start] = $this->crossed(WireLog::START, self::ORDER);
        [$queued] = $this->carried(self::ORDER, Verb::Start);

        // One copy through the queue, which delivers its message again, and one through the endpoint.
        $this->shop->queue()->push(SpoolDispatcher::SEGMENTS, json_encode($queued->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(202, $this->deliverAgain($start));
        $rejected = $this->await('both copies were reported', fn (): array|false => count($this->reports(self::ORDER, 'rejected')) === 2 ? $this->reports(self::ORDER, 'rejected') : false);

        foreach ($rejected as $report) {
            self::assertSame(
                ['start', 'already_claimed', true, null, null],
                [$report['verb'], $report['code'], $report['claimed'], $report['executedAttempt'], $report['run']],
            );
        }
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER), 'Once for its start and once for its payment.');
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'Once for its payment and once for its timer.');
        self::assertNull($this->stored(self::ORDER), 'No copy created a run.');
        self::assertSame('completed', $this->platform->run($runId)['state'], 'The announcement of a copy never reopens a completed run.');
        self::assertSame([], $this->platform->alerts());
    }
}
