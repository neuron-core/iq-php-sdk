<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Wait;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\FaultyStore;

use function time;

/**
 * Row 3 of section 7 of the design: a start arrives after its use-by time.
 * It is reported "rejected: expired" and nothing is claimed: the time is
 * checked where execution happens, and again at the claim.
 */
class Row03StartAfterItsUseByTimeTest extends FailureTableTestCase
{
    protected const ORDER = 'R0301';

    /** A start of the platform lives two seconds, and is asked about a moment after that. */
    protected const PLATFORM = ['shortestStartLifetime' => 2, 'startLifetime' => 2, 'startSilence' => 0.3] + parent::PLATFORM;

    public function test_a_start_that_waited_in_the_queue_past_its_use_by_time_is_rejected_before_anything_is_claimed(): void
    {
        $runId = $this->fire(self::ORDER);
        $this->platform->tick();
        $expiresAt = $this->crossed(WireLog::START, self::ORDER)[0]['fields']['expiresAt'];
        Wait::until('the use-by time of the start has passed', static fn (): bool => time() >= $expiresAt, 3.0);

        $this->startWorkers();
        $rejected = $this->awaitReport(self::ORDER, 'rejected');
        $lost = $this->awaitClosed($runId, 'lost');

        self::assertSame(['expired', null, null, true], [$rejected['code'], $rejected['claimed'], $rejected['run'], $rejected['snapshotRead']]);
        self::assertSame([], $this->crossed(WireLog::ANNOUNCEMENT, self::ORDER), 'An expired start is refused before it is announced.');
        $this->assertNothingRanAndNothingWasClaimed();
        self::assertSame('reported rejected', $lost['orders'][0]['ended']);
        self::assertCount(1, $this->crossed(WireLog::START, self::ORDER), 'A start is never sent after its use-by time.');
    }

    public function test_a_start_held_up_between_the_check_of_its_use_by_time_and_its_claim_is_rejected_at_the_claim(): void
    {
        // The first read of the store by a worker is the read of the ledger, for the claim: it takes longer than the start lives.
        $this->shop->faults->plan(FaultyStore::READ, Faults::SLEEP . ' 2');
        $runId = $this->fire(self::ORDER);

        $this->startWorkers();
        $rejected = $this->awaitReport(self::ORDER, 'rejected');
        $this->awaitClosed($runId, 'lost');

        self::assertSame(['expired', null, null, true], [$rejected['code'], $rejected['claimed'], $rejected['run'], $rejected['snapshotRead']]);
        self::assertCount(1, $this->crossed(WireLog::ANNOUNCEMENT, self::ORDER), 'The start had passed the first check: it was announced.');
        $this->assertNothingRanAndNothingWasClaimed();
    }

    /**
     * Seen from both sides: the store of the shop holds nothing at all, and its endpoint told the platform so.
     */
    protected function assertNothingRanAndNothingWasClaimed(): void
    {
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);

        self::assertSame(0, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        self::assertSame([], $this->shop->stored(), 'Neither a run nor a ledger entry was written.');
        self::assertSame([null, false], [$asked['answer']['run'], $asked['answer']['claimed']]);
        self::assertSame([], $this->platform->alerts());
    }
}
