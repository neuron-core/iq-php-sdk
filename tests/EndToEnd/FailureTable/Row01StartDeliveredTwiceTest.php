<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Verb;

use function array_column;
use function count;
use function sort;
use function array_unique;

/**
 * Row 1 of section 7 of the design: the same start is delivered twice, by the
 * platform or by the queue. The second copy finds its run ID in the ledger
 * and reports "rejected: already_claimed".
 */
class Row01StartDeliveredTwiceTest extends FailureTableTestCase
{
    protected const ORDER = 'R0101';

    public function test_a_start_the_platform_sends_twice_runs_once_and_its_second_copy_is_rejected_as_already_claimed(): void
    {
        $this->theEndpointAnswersItsNextMessageTooLate();
        $runId = $this->fire(self::ORDER);
        $this->startWorkers();

        $rejected = $this->awaitReport(self::ORDER, 'rejected');
        $starts = $this->crossed(WireLog::START, self::ORDER);
        [$first] = $this->reports(self::ORDER);

        self::assertSame([0, 202], [$starts[0]['status'], $starts[count($starts) - 1]['status']], 'The platform got no answer to its first copy.');
        self::assertCount(1, array_unique(array_column($starts, 'body')), 'Every copy is the same message, with the same run ID and use-by time.');
        self::assertGreaterThanOrEqual(2, count($this->carried(self::ORDER, Verb::Start)), 'The queue of the shop took the start more than once.');
        self::assertSame(['suspended', 1, true], [$first['outcome'], $first['executedAttempt'], $first['claimed']]);
        self::assertSame(['already_claimed', null, true], [$rejected['code'], $rejected['executedAttempt'], $rejected['claimed']]);
        self::assertSame($this->stored(self::ORDER), $rejected['run'], 'The report of the second copy carries the snapshot.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertSame([], $this->platform->alerts());
    }

    public function test_a_start_the_queue_delivers_to_two_workers_at_once_runs_in_one_of_them(): void
    {
        $runId = $this->fire(self::ORDER);
        $this->platform->tick();
        $this->theQueueDeliversTwice(Verb::Start);

        $this->shop->startWorker();
        $this->startWorkers();
        $reports = $this->await('both copies were reported', fn (): array|false => count($this->reports(self::ORDER)) === 2 ? $this->reports(self::ORDER) : false);

        $outcomes = array_column($reports, 'outcome');
        sort($outcomes);
        self::assertSame(['rejected', 'suspended'], $outcomes);
        self::assertSame('already_claimed', $this->reports(self::ORDER, 'rejected')[0]['code']);
        self::assertCount(1, $this->crossed(WireLog::START, self::ORDER), 'The platform sent the start once.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertSame([], $this->platform->alerts());
    }
}
