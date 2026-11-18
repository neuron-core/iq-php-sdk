<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Wait;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function array_filter;
use function array_values;
use function count;

/**
 * Row 14 of section 7 of the design: an event arrives before its wait, and an
 * approval is sent twice. signal() throws before anything is queued. A second
 * approval prepared before the first one was admitted is "stale_attempt", or
 * "stale_run" when the first one completed the run; one submitted after that
 * is checked in the request against the wait the run is on then, and refused
 * there: no run is suspended on a wait, or the run moved on to a wait that
 * rejects it.
 *
 * The approval of the shop is the decision of its back office on a payment.
 */
class Row14EarlyEventAndDoubleApprovalTest extends FailureTableTestCase
{
    protected const ORDER = 'R1401';

    public function test_a_payment_that_arrives_before_the_order_waits_for_it_is_refused_in_the_request_and_not_kept(): void
    {
        $beforeTheOrderExists = $this->shop->pay(self::ORDER, 49.9, self::PAYMENT_REFERENCE);
        // The order is placed, and its first node is still executing when the provider tries again.
        $this->shop->faults->plan('await-payment.' . self::ORDER, Faults::HOLD);
        $this->startWorkers();
        $runId = (string) $this->shop->checkout(self::ORDER, self::NOTE)['body']['runId'];
        Wait::until('a worker stands in the first node', fn (): bool => $this->shop->faults->pending('await-payment.' . self::ORDER) === []);
        $whileTheOrderIsExecuting = $this->shop->pay(self::ORDER, 49.9, self::PAYMENT_REFERENCE);
        $this->shop->faults->release('await-payment.' . self::ORDER);
        $waiting = $this->awaitWait($runId, 'wait_for_event');

        self::assertSame([409, 409], [$beforeTheOrderExists, $whileTheOrderIsExecuting]);
        self::assertSame([], $this->carried(self::ORDER, Verb::Signal), 'Nothing was queued.');
        self::assertSame(1, $waiting['run']['attempt'], 'Nothing kept the payment for the wait: the order waits for it.');

        $this->theOrderIsPaid(self::ORDER, $runId);
        self::assertCount(1, $this->carried(self::ORDER, Verb::Signal));
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 0);
    }

    public function test_a_second_approval_prepared_before_the_first_was_admitted_is_refused_as_a_stale_attempt(): void
    {
        $runId = $this->theOrderWaitsForADecisionAndNoWorkerTakesIt();
        $queued = [$this->shop->reviewPayment(self::ORDER, approved: true), $this->shop->reviewPayment(self::ORDER, approved: true)];

        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHIPS_MUCH_LATER);
        [$first, $second] = $this->awaitTheReportsOfBothDecisions();
        $afterTheFirstWasAdmitted = $this->shop->reviewPayment(self::ORDER, approved: true);

        self::assertSame([202, 202], $queued);
        self::assertSame(['suspended', null, 2], [$first['outcome'], $first['code'], $first['executedAttempt']]);
        self::assertSame(['refused', 'stale_attempt', null], [$second['outcome'], $second['code'], $second['executedAttempt']]);
        self::assertSame(['sleep_until', 2], [$second['run']['wait']['type'], $second['run']['attempt']], 'The second approval did not answer the next wait.');
        self::assertSame(409, $afterTheFirstWasAdmitted, 'The order sleeps on a timer, which no decision answers.');
        self::assertCount(2, $this->carried(self::ORDER, Verb::Answer), 'The third approval was never queued.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 0);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_second_decision_is_refused_as_a_stale_run_when_the_first_one_ended_the_order_and_a_third_finds_no_run(): void
    {
        $runId = $this->theOrderWaitsForADecisionAndNoWorkerTakesIt();
        $queued = [$this->shop->reviewPayment(self::ORDER, approved: false), $this->shop->reviewPayment(self::ORDER, approved: false)];

        $this->shop->startWorker();
        [$first, $second] = $this->awaitTheReportsOfBothDecisions();
        $afterTheOrderEnded = $this->shop->reviewPayment(self::ORDER, approved: false);

        self::assertSame([202, 202], $queued);
        self::assertSame(['completed', null, 2, null], [$first['outcome'], $first['code'], $first['executedAttempt'], $first['run']]);
        self::assertSame(['refused', 'stale_run', null, null], [$second['outcome'], $second['code'], $second['executedAttempt'], $second['run']]);
        self::assertSame(400, $afterTheOrderEnded, 'No run is suspended on a wait.');
        self::assertCount(2, $this->carried(self::ORDER, Verb::Answer), 'The third decision was never queued.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'The declined order ended once.');
        self::assertSame('completed', $this->platform->run($runId)['state']);
        self::assertNull($this->stored(self::ORDER));
    }

    /**
     * @return string the run ID
     */
    protected function theOrderWaitsForADecisionAndNoWorkerTakesIt(): string
    {
        $worker = $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $worker->stop();

        return $runId;
    }

    /**
     * @return array<int, array<string, mixed>> the reports of the two answers, in the order the worker dealt with them
     */
    protected function awaitTheReportsOfBothDecisions(): array
    {
        return $this->await('both decisions were reported', function (): array|false {
            $reports = array_values(array_filter(
                $this->reports(self::ORDER),
                static fn (array $report): bool => $report['verb'] === Verb::Answer->value,
            ));

            return count($reports) === 2 ? $reports : false;
        });
    }
}
