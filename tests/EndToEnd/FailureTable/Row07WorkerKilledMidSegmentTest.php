<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;

use const SIGKILL;

/**
 * Row 7 of section 7 of the design: a worker is killed mid-segment. No report
 * follows. The platform asks when the lease it was told runs out, and then
 * sends a wake: committed steps replay, only the node in flight runs again.
 */
class Row07WorkerKilledMidSegmentTest extends FailureTableTestCase
{
    protected const ORDER = 'R0701';

    public function test_a_worker_killed_in_a_node_body_is_followed_by_a_wake_that_runs_only_that_node_again(): void
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        $doomed = $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        // Once the order has slept, its worker stands in the body of the second node for as long as it lives.
        $this->shop->faults->plan('ship.' . self::ORDER, Faults::HOLD);
        $this->theOrderIsPaid(self::ORDER, $runId);

        $this->await('the worker stands in the body of the second node', fn (): bool => $this->shop->faults->pending('ship.' . self::ORDER) === []);
        $leaseExpiresAt = (int) $this->stored(self::ORDER)['leaseExpiresAt'];
        $this->theClockOfThePlatformRunsAhead();
        $doomed->kill();
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        $completed = $this->awaitClosed($runId, 'completed');

        self::assertSame(SIGKILL, $doomed->endedBy());
        $this->assertTheKilledSegmentWasAnnouncedAndNeverReported($completed);
        self::assertNotSentBeforeTheLeaseHadPassed($leaseExpiresAt, $this->crossed(WireLog::WAKE, self::ORDER)[1]);
        self::assertSame([['attempt' => 3, 'kind' => 'dead']], self::attemptsAndKinds($completed['failures']));
        self::assertSame([2, 3], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'), 'The timer, then the retry of the dead attempt.');
        self::assertSame(['completed', 4], [$this->reports(self::ORDER, 'completed')[0]['outcome'], $this->reports(self::ORDER, 'completed')[0]['executedAttempt']]);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER), 'The committed step was not run again.');
        self::assertSame(3, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'Its first pass, the killed pass and the pass of the retry.');
        self::assertNull($this->stored(self::ORDER));
    }

    /**
     * The platform asked because the lease it was told ran out, by its own clock, with no report, and saw the
     * run still running.
     *
     * @param array<string, mixed> $held what the platform holds for the run
     */
    protected function assertTheKilledSegmentWasAnnouncedAndNeverReported(array $held): void
    {
        $killed = $held['segments'][2];
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);

        self::assertSame(['wake', 2, 2], [$killed['verb'], $killed['attempt'], $killed['leaseSeconds']]);
        self::assertNotNull($killed['announcedAt']);
        self::assertNull($killed['reportedAt']);
        self::assertSame(['running', 3], [$asked['answer']['run']['status'], $asked['answer']['run']['attempt']]);
        self::assertGreaterThanOrEqual($killed['announcedAt'] + 2, (int) $asked['headers']['webhook-timestamp'], 'Not before the lease it was told ran out.');
    }

    /**
     * @param array<int, array<string, mixed>> $failures as the platform counted them
     * @return array<int, array{attempt: int, kind: string}>
     */
    protected static function attemptsAndKinds(array $failures): array
    {
        $counted = [];
        foreach ($failures as $failure) {
            $counted[] = ['attempt' => $failure['attempt'], 'kind' => $failure['kind']];
        }

        return $counted;
    }
}
