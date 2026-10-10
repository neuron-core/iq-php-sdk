<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Wait;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;

use function count;
use function time;

/**
 * Row 6 of section 7 of the design: the platform is down, for an hour in the
 * table and for a few seconds here. Segments keep running. Announcements and
 * observes pile up on their own queue and drain afterwards. Timers fire late.
 */
class Row06PlatformDownTest extends FailureTableTestCase
{
    /** Its timer comes due while the platform is down. */
    protected const PAID_BEFORE_THE_OUTAGE = 'R0601';

    /** Checked out and paid while the platform is down. */
    protected const PLACED_DURING_THE_OUTAGE = 'R0602';

    protected const LATER = SpoolDispatcher::NOTICES . ':later';

    public function test_segments_keep_running_while_the_platform_is_down_and_it_catches_up_once_it_is_back(): void
    {
        $this->startWorkers();
        $before = $this->theOrderWaitsForItsPayment(self::PAID_BEFORE_THE_OUTAGE);
        $this->theOrderIsPaid(self::PAID_BEFORE_THE_OUTAGE, $before);
        $dueAt = $this->platform->run($before)['run']['wait']['wakeAt'];

        $this->platform->stop();
        $during = (string) $this->shop->checkout(self::PLACED_DURING_THE_OUTAGE, self::NOTE)['body']['runId'];
        $this->awaitInTheStore(self::PLACED_DURING_THE_OUTAGE, 'wait_for_event');
        self::assertSame(202, $this->shop->pay(self::PLACED_DURING_THE_OUTAGE, 49.9, self::PAYMENT_REFERENCE));
        $this->awaitInTheStore(self::PLACED_DURING_THE_OUTAGE, 'sleep_until');
        Wait::until('the two announcements and the two observes pile up for later', fn (): bool => count($this->shop->queue()->waiting(self::LATER)) === 4);
        Wait::until('the timer of the first order came due during the outage', static fn (): bool => time() > $dueAt, 4.0);
        self::assertNotNull($this->stored(self::PAID_BEFORE_THE_OUTAGE), 'Nobody woke the order: its timer is the platform\'s.');

        $this->platform->start();
        $this->shop->retryLater();
        $this->awaitClosed($before, 'completed');
        $this->awaitClosed($during, 'completed');

        [$wake] = $this->crossed(WireLog::WAKE, self::PAID_BEFORE_THE_OUTAGE);
        self::assertGreaterThan($dueAt, (int) $wake['headers']['webhook-timestamp'], 'The timer fired late.');
        self::assertSame('application', $this->platform->run($during)['origin'], 'The platform learned of the order from the notices that drained.');
        self::assertSame([], $this->shop->queue()->waiting(self::LATER));
        self::assertSame([], $this->shop->queue()->waiting(SpoolDispatcher::NOTICES . ':failed'));
        $this->assertNodeBodiesFinished(self::PAID_BEFORE_THE_OUTAGE, awaitPayment: 1, ship: 1);
        $this->assertNodeBodiesFinished(self::PLACED_DURING_THE_OUTAGE, awaitPayment: 1, ship: 1);
        self::assertSame([], $this->platform->alerts());
    }

    /**
     * The platform is down and cannot be asked: the store of the shop is.
     */
    protected function awaitInTheStore(string $order, string $waitType): void
    {
        Wait::until(
            "the run of order {$order} is at a wait of type {$waitType} in the store",
            fn (): bool => ($this->stored($order)['wait']['type'] ?? null) === $waitType,
        );
    }
}
