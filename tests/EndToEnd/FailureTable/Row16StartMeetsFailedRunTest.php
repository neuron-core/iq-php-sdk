<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Verb;

/**
 * Row 16 of section 7 of the design: a start meets a run that failed earlier
 * on the same workflow ID. The caller gets RunInFlightException, in the
 * request: a start never replaces a run, and recover() is the explicit verb.
 */
class Row16StartMeetsFailedRunTest extends FailureTableTestCase
{
    protected const ORDER = 'R1601';

    /** The policy of the platform retries nothing: the failed run stays as it is until a person acts. */
    protected const PLATFORM = ['retryAttempts' => 0] + parent::PLATFORM;

    public function test_a_checkout_that_meets_the_failed_run_of_its_order_is_refused_in_the_request_and_replaces_nothing(): void
    {
        $failed = $this->theCheckoutOfTheOrderFailed();

        $onTheQueue = $this->shop->checkout(self::ORDER, self::NOTE);
        $inPlace = $this->shop->checkout(self::ORDER, self::NOTE, inPlace: true);
        $refused = $this->awaitReport(self::ORDER, 'refused');
        $spent = $this->awaitClosed($refused['runId'], 'spent');

        self::assertSame([409, 409], [$onTheQueue['status'], $inPlace['status']]);
        self::assertSame($failed, $this->stored(self::ORDER), 'The failed run holds the order as before.');
        self::assertCount(1, $this->carried(self::ORDER, Verb::Start), 'Neither checkout queued a start.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        self::assertSame(['run_in_flight', null, $failed], [$refused['code'], $refused['executedAttempt'], $refused['run']]);
        self::assertNotSame($failed['runId'], $refused['runId'], 'The start run in the request had reserved a run ID that never ran.');
        self::assertNull($spent['code'], 'A start of the shop has no operator: the platform marks it spent and shows its code to nobody.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $failed['runId']);
    }

    public function test_recover_finishes_the_failed_run_under_its_own_run_id(): void
    {
        $failed = $this->theCheckoutOfTheOrderFailed();

        $recovered = $this->shop->recover(self::ORDER);
        $this->awaitWait($failed['runId'], 'wait_for_event');

        [$report] = $this->reports(self::ORDER, 'suspended');
        self::assertSame(['status' => 200, 'body' => ['recovered' => true]], $recovered);
        self::assertSame(['wake', 'application', 2], [$report['verb'], $report['origin'], $report['executedAttempt']]);
        self::assertSame([], $this->crossed(WireLog::WAKE, self::ORDER), 'The platform sent no wake: its policy retries nothing.');
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $failed['runId']);
    }

    /**
     * @return array<string, mixed> the failed run of the order, as the store of the shop shows it
     */
    protected function theCheckoutOfTheOrderFailed(): array
    {
        $this->shop->faults->plan('await-payment.' . self::ORDER, Faults::FAIL);
        $this->startWorkers();
        self::assertSame(202, $this->shop->checkout(self::ORDER, self::NOTE)['status']);
        $this->awaitReport(self::ORDER, 'failed');

        $failed = (array) $this->stored(self::ORDER);
        self::assertSame(['failed', 1], [$failed['status'], $failed['attempt']]);

        return $failed;
    }
}
