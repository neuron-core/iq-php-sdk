<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Wait;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\OrderProgress;
use NeuronCore\Cloud\Verb;

use function array_column;
use function array_map;

/**
 * Row 15 of section 7 of the design: a browser closes a streamed segment.
 * Neuron fails a run it was still executing. The observe carries no outcome
 * and the report says "observed", with the snapshot. When PHP itself aborts
 * the request no observe is queued, and the platform asks when the announced
 * lease has passed. Either way it plans from the snapshot, and retries the
 * failed run by its policy.
 *
 * A server learns that a connection was closed at the second thing it sends
 * after that. The order therefore stands at two points after the step at
 * which the customer closes the page, each until the test lets it go on: the
 * request then learns that nobody reads at a moment the test chose, while
 * the first node is still executing or when the run has just suspended.
 */
class Row15BrowserClosesStreamedSegmentTest extends FailureTableTestCase
{
    protected const ORDER = 'R1501';

    /** The points of the first node where the order stands until the customer has left, by the step at which that is. */
    protected const PAUSES_AFTER = [
        OrderProgress::RECEIVED => ['', '.checked'],
        OrderProgress::CHECKED => ['.checked', '.told'],
    ];

    public function test_a_stream_the_request_lets_go_of_when_its_browser_has_left_is_reported_observed_and_the_failed_run_is_retried(): void
    {
        $shown = $this->theCustomerLeavesThePageOfACheckoutServedWith(['SHOP_OUTLIVES_ITS_BROWSER' => '1']);
        $observed = $this->awaitReport(self::ORDER, 'observed');
        $this->awaitWait($observed['runId'], 'wait_for_event');

        [$observe] = $this->carried(self::ORDER, Verb::Observe);
        self::assertSame(['status' => 200, 'frames' => [OrderProgress::RECEIVED]], $shown);
        self::assertArrayNotHasKey('outcome', (array) $observe->body, 'The observe says nothing of how the segment ended.');
        self::assertSame(['start', 'application', null, null, null], [$observed['verb'], $observed['origin'], $observed['code'], $observed['errorClass'], $observed['executedAttempt']]);
        self::assertSame(['failed', 1, null], [$observed['run']['status'], $observed['run']['attempt'], $observed['run']['leaseExpiresAt']], 'Neuron failed the run it was executing.');
        self::assertSame([], $this->crossed(WireLog::INSPECT, self::ORDER), 'The platform did not have to ask.');
        $this->assertTheFailedRunWasRetriedOnce($observed['runId']);
    }

    public function test_a_stream_that_php_itself_aborts_queues_no_observe_and_the_platform_asks_when_the_announced_lease_has_passed(): void
    {
        $shown = $this->theCustomerLeavesThePageOfACheckoutServedWith(self::SHORT_LEASE);
        [$announcement] = $this->await('the announcement of the segment was posted', fn (): array|false => $this->crossed(WireLog::ANNOUNCEMENT, self::ORDER) ?: false);
        $runId = $announcement['fields']['runId'];
        $this->awaitWait($runId, 'wait_for_event');

        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame(['status' => 200, 'frames' => [OrderProgress::RECEIVED]], $shown);
        self::assertSame([], $this->carried(self::ORDER, Verb::Observe), 'PHP ended the request: nothing handed the queue an observe.');
        self::assertSame([], $this->reports(self::ORDER, 'observed'));
        self::assertGreaterThanOrEqual($announcement['fields']['at'] + $announcement['fields']['leaseSeconds'], (int) $asked['headers']['webhook-timestamp'], 'Not before the announced lease had passed.');
        self::assertSame(['failed', 1, null], [$asked['answer']['run']['status'], $asked['answer']['run']['attempt'], $asked['answer']['run']['leaseExpiresAt']], 'Neuron failed the run it was executing.');
        $this->assertTheFailedRunWasRetriedOnce($runId);
    }

    public function test_a_stream_let_go_of_on_its_last_frame_leaves_the_suspended_run_as_it_is_and_its_observe_says_suspended(): void
    {
        $shown = $this->theCustomerLeavesThePageOfACheckoutServedWith(['SHOP_OUTLIVES_ITS_BROWSER' => '1'], OrderProgress::CHECKED);
        $suspended = $this->awaitReport(self::ORDER, 'suspended');

        [$observe] = $this->carried(self::ORDER, Verb::Observe);
        self::assertSame(['status' => 200, 'frames' => [OrderProgress::RECEIVED, OrderProgress::CHECKED]], $shown, 'The customer left before the order waited.');
        self::assertSame('suspended', $observe->body['outcome'] ?? null, 'Neuron had reported the end of the segment.');
        self::assertSame(['start', 1, null], [$suspended['verb'], $suspended['executedAttempt'], $suspended['errorClass']]);
        self::assertSame([], $this->platform->run($suspended['runId'])['failures'], 'Nothing failed: nothing is retried.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $suspended['runId']);
    }

    public function test_a_stream_that_php_aborts_on_its_last_frame_leaves_the_suspended_run_as_it_is_and_the_platform_learns_it_by_asking(): void
    {
        $shown = $this->theCustomerLeavesThePageOfACheckoutServedWith(self::SHORT_LEASE, OrderProgress::CHECKED);
        [$announcement] = $this->await('the announcement of the segment was posted', fn (): array|false => $this->crossed(WireLog::ANNOUNCEMENT, self::ORDER) ?: false);
        $runId = $announcement['fields']['runId'];
        $this->awaitWait($runId, 'wait_for_event');

        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame(['status' => 200, 'frames' => [OrderProgress::RECEIVED, OrderProgress::CHECKED]], $shown, 'The customer left before the order waited.');
        self::assertSame([], $this->carried(self::ORDER, Verb::Observe), 'PHP ended the request: nothing handed the queue an observe.');
        self::assertSame([], $this->reports(self::ORDER), 'The platform learned of the run by asking.');
        self::assertSame(['suspended', 1], [$asked['answer']['run']['status'], $asked['answer']['run']['attempt']]);
        self::assertSame([], $this->platform->run($runId)['failures'], 'Nothing failed: nothing is retried.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_browser_that_stays_is_shown_every_step_and_the_order_waits_for_its_payment(): void
    {
        $this->startWorkers();
        $shown = $this->shop->checkoutWatched(self::ORDER, self::NOTE);
        $suspended = $this->awaitReport(self::ORDER, 'suspended');

        self::assertSame(
            ['status' => 200, 'frames' => [OrderProgress::RECEIVED, OrderProgress::CHECKED, OrderProgress::AWAITING_PAYMENT, 'suspended']],
            $shown,
        );
        self::assertSame(['start', 1, false], [$suspended['verb'], $suspended['executedAttempt'], $suspended['claimed']]);
        self::assertSame('suspended', $this->carried(self::ORDER, Verb::Observe)[0]->body['outcome'] ?? null);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $suspended['runId']);
    }

    /**
     * @param array<string, string> $environment what the web server of the shop sets differently
     * @param string $leavesAt the step shown when the customer closes the page: the request learns it two steps later
     * @return array{status: int, frames: string[]} what the page showed before the customer closed it
     */
    protected function theCustomerLeavesThePageOfACheckoutServedWith(array $environment, string $leavesAt = OrderProgress::RECEIVED): array
    {
        $this->shop->stopServing();
        $this->shop->serve($environment);
        $points = array_map(static fn (string $point): string => 'await-payment.' . self::ORDER . $point, self::PAUSES_AFTER[$leavesAt]);
        foreach ($points as $point) {
            $this->shop->faults->plan($point, Faults::HOLD);
        }
        $this->startWorkers();

        $shown = $this->shop->checkoutWatched(self::ORDER, self::NOTE, $leavesAt);

        // The page is closed. The request goes on one point at a time: it has written its next step when it
        // stands at the second, and the write after that one tells it that nobody reads.
        [$first, $second] = $points;
        Wait::until("the request stands at {$first}", fn (): bool => $this->shop->faults->pending($first) === []);
        $this->shop->faults->release($first);
        Wait::until("the request stands at {$second}", fn (): bool => $this->shop->faults->pending($second) === []);
        $this->shop->awaitTheResetOfItsConnection();
        $this->shop->faults->release($second);

        return $shown;
    }

    /**
     * The platform counted one failure from the snapshot, woke the run under the attempt shown, and a worker
     * ran the first node again, to its wait.
     */
    protected function assertTheFailedRunWasRetriedOnce(string $runId): void
    {
        self::assertSame(['failed'], array_column($this->platform->run($runId)['failures'], 'kind'));
        self::assertSame([1], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'));
        self::assertSame(['wake', 2], [$this->reports(self::ORDER, 'suspended')[0]['verb'], $this->reports(self::ORDER, 'suspended')[0]['executedAttempt']]);
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER), 'Once in the request that streamed, once in the worker that retried.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }
}
