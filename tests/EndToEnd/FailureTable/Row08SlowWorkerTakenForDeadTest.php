<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;

/**
 * Row 8 of section 7 of the design: a slow worker is taken for dead. Its next
 * write is refused, "stale_attempt". The node it was in has run in both
 * workers, which is why the lease must exceed the slowest node.
 */
class Row08SlowWorkerTakenForDeadTest extends FailureTableTestCase
{
    protected const ORDER = 'R0801';

    public function test_a_node_that_outlives_its_lease_runs_in_a_second_worker_and_the_slow_one_loses_its_next_write(): void
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        // The body of the first node outlives the lease of its worker: it stands there until the run was taken over.
        $this->shop->faults->plan('await-payment.' . self::ORDER, Faults::HOLD);
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        $runId = $this->fire(self::ORDER);
        $this->await('the slow worker stands in the body of the first node', fn (): bool => $this->shop->faults->pending('await-payment.' . self::ORDER) === []);
        $leaseExpiresAt = (int) $this->stored(self::ORDER)['leaseExpiresAt'];
        $this->theClockOfThePlatformRunsAhead();

        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        [$takenOver] = $this->await('a second worker took the run over', fn (): array|false => $this->reports(self::ORDER, 'suspended') ?: false);
        $this->shop->faults->release('await-payment.' . self::ORDER);
        $refused = $this->awaitReport(self::ORDER, 'refused');
        [$wake] = $this->crossed(WireLog::WAKE, self::ORDER);

        self::assertNotSentBeforeTheLeaseHadPassed($leaseExpiresAt, $wake);
        self::assertSame(['wake', 2], [$takenOver['verb'], $takenOver['executedAttempt']], 'The second worker took the run over.');
        self::assertSame(['start', 'stale_attempt', 1, true], [$refused['verb'], $refused['code'], $refused['executedAttempt'], $refused['claimed']]);
        self::assertSame(['suspended', 2], [$refused['run']['status'], $refused['run']['attempt']], 'The slow worker changed nothing.');
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER), 'The node has run in both workers.');
        self::assertSame(['dead'], array_column($this->platform->run($runId)['failures'], 'kind'));
        self::assertSame([1], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'), 'The wake is fenced with the attempt the store showed.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);

        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->awaitClosed($runId, 'completed');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
    }
}
