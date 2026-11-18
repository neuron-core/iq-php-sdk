<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\IQ\Verb;

use function array_column;

use const SIGKILL;

/**
 * Row 11 of section 7 of the design: a job is accepted and never runs. The
 * platform hears nothing and asks. A wake is sent again. A start is asked
 * about after its use-by time, too late to be sent again, and closed as lost
 * when it is unclaimed: a 202 promised nothing.
 *
 * The queue of the shop hands a message out once: a worker that dies with a
 * message it has just taken loses the job.
 */
class Row11JobAcceptedAndNeverRunTest extends FailureTableTestCase
{
    protected const ORDER = 'R1101';

    /** A start lives two seconds and is asked about a moment later; a wake is asked about after half a second of silence. */
    protected const PLATFORM = ['shortestStartLifetime' => 2, 'startLifetime' => 2, 'startSilence' => 0.3, 'wakeSilence' => 0.5] + parent::PLATFORM;

    public function test_a_wake_that_was_accepted_and_never_ran_is_asked_about_and_sent_again(): void
    {
        $worker = $this->shop->startWorker();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        // The worker takes the payment and runs it, then takes the wake of the timer and dies with it.
        $this->shop->faults->plan('worker.taken', Faults::PASS, Faults::DIE);
        $this->theOrderIsPaid(self::ORDER, $runId);

        $this->await('the worker died with the wake of the timer', static fn (): bool => !$worker->isRunning());
        $this->shop->startWorker();
        $this->awaitClosed($runId, 'completed');

        $wakes = $this->crossed(WireLog::WAKE, self::ORDER);
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame(SIGKILL, $worker->endedBy());
        self::assertSame([202, 202], array_column($wakes, 'status'));
        self::assertSame($wakes[0]['fields'], $wakes[1]['fields'], 'The same wake, with the same fence.');
        self::assertSame(['suspended', 2], [$asked['answer']['run']['status'], $asked['answer']['run']['attempt']], 'The platform asked, and the attempt was unchanged.');
        self::assertCount(2, $this->carried(self::ORDER, Verb::Wake));
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'Once for the payment and once for the wake that ran.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
    }

    public function test_a_start_that_was_accepted_and_never_ran_is_asked_about_after_its_use_by_time_and_closed_as_lost(): void
    {
        $this->shop->faults->plan('worker.taken', Faults::DIE);
        $worker = $this->shop->startWorker();
        $runId = $this->fire(self::ORDER);
        $this->await('the worker died with the start', static fn (): bool => !$worker->isRunning());

        $this->shop->startWorker();
        $this->awaitClosed($runId, 'lost');

        [$start] = $this->crossed(WireLog::START, self::ORDER);
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame(202, $start['status']);
        self::assertCount(1, $this->crossed(WireLog::START, self::ORDER), 'Too late to be sent again, and never replaced.');
        self::assertGreaterThanOrEqual($start['fields']['expiresAt'], (int) $asked['headers']['webhook-timestamp'], 'Asked about only after its use-by time.');
        self::assertSame([$runId, null, false], [$asked['fields']['runId'], $asked['answer']['run'], $asked['answer']['claimed']]);
        self::assertSame([], $this->crossed(WireLog::ANNOUNCEMENT, self::ORDER), 'The platform heard nothing of the start.');
        self::assertSame(0, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        self::assertSame([], $this->shop->stored(), 'Neither a run nor a ledger entry exists.');
    }
}
