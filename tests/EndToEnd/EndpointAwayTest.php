<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Verb;

use function array_column;
use function array_fill;
use function array_unique;
use function time;

/**
 * Rule 13 of appendix B of the design, against the real endpoint: the web
 * tier of the shop is away for longer than the bounded re-sends of the
 * platform last, as during a deploy. The platform alerts and does not end
 * the order: it counts it as accepted and silent, asks once the endpoint
 * answers again, and sends it again. A timer that came due meanwhile fires
 * once, and so does a start whose use-by time has not passed.
 */
class EndpointAwayTest extends FailureTableTestCase
{
    protected const ORDER = 'E5001';

    /** Two re-sends within half a second, and half a second of silence before the platform asks. */
    protected const PLATFORM = ['resendDelay' => 0.1, 'resendDelayCap' => 0.3, 'uncertainResends' => 2, 'wakeSilence' => 0.5] + parent::PLATFORM;

    public function test_a_timer_that_came_due_while_the_endpoint_was_away_fires_once_after_it_is_back(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $dueAt = $this->platform->run($runId)['run']['wait']['wakeAt'];

        // The endpoint stays away until the wake has run out of re-sends, and after it the question about its silence.
        $this->shop->stopServing();
        $this->awaitUnanswered(times: 2);
        $planned = $this->platform->run($runId);
        self::assertGreaterThanOrEqual($dueAt, time(), 'The timer came due while the endpoint was away.');
        self::assertSame([0, 0, 0], array_column($this->crossed(WireLog::WAKE, self::ORDER), 'status'), 'The wake and its two re-sends met no endpoint.');
        self::assertSame([0, 0, 0], array_column($this->crossed(WireLog::INSPECT, self::ORDER), 'status'), 'Neither did the question and its two re-sends.');
        self::assertSame(['open', ['wake_silence']], [$planned['state'], array_column($planned['timers'], 'reason')], 'Nothing is ended: the run is still planned.');
        self::assertNotNull($this->stored(self::ORDER), 'Nobody woke the order.');

        $this->shop->serve();
        $this->awaitClosed($runId, 'completed');

        $wakes = $this->crossed(WireLog::WAKE, self::ORDER);
        $asked = $this->crossed(WireLog::INSPECT, self::ORDER)[3];
        self::assertSame([0, 0, 0, 202], array_column($wakes, 'status'), 'One more copy, once the endpoint answered again.');
        self::assertCount(1, array_unique(array_column($wakes, 'body')), 'The same wake, with the same fence.');
        self::assertSame([200, 'suspended', 2], [$asked['status'], $asked['answer']['run']['status'], $asked['answer']['run']['attempt']], 'The platform asked first, and the attempt was unchanged.');
        self::assertCount(1, $this->carried(self::ORDER, Verb::Wake), 'One wake reached the queue of the shop.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame(['unanswered', 'unanswered'], array_column($this->platform->alerts(), 'kind'));
    }

    public function test_a_start_sent_while_the_endpoint_was_away_runs_once_after_it_is_back_within_its_use_by_time(): void
    {
        $this->startWorkers();
        $this->shop->stopServing();
        $runId = $this->fire(self::ORDER);
        $this->awaitUnanswered(times: 1);
        self::assertSame([0, 0, 0], array_column($this->crossed(WireLog::START, self::ORDER), 'status'), 'The start and its two re-sends met no endpoint.');
        self::assertSame('open', $this->platform->run($runId)['state'], 'The order is not ended.');

        $this->shop->serve();
        $this->awaitWait($runId, 'wait_for_event');

        $starts = $this->crossed(WireLog::START, self::ORDER);
        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame([0, 0, 0, 202], array_column($starts, 'status'), 'One more copy, once the endpoint answered again.');
        self::assertCount(1, array_unique(array_column($starts, 'body')), 'The same start, with its run ID and its use-by time.');
        self::assertLessThan($starts[3]['fields']['expiresAt'], (int) $starts[3]['headers']['webhook-timestamp'], 'Sent again within its use-by time.');
        self::assertSame([$runId, null, false], [$asked['fields']['runId'], $asked['answer']['run'], $asked['answer']['claimed']], 'The platform asked first: no copy had claimed the start.');
        self::assertCount(1, $this->carried(self::ORDER, Verb::Start), 'One start reached the queue of the shop.');
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertSame(['unanswered'], array_column($this->platform->alerts(), 'kind'));
    }

    /**
     * The clock of the platform ticks until a message has run out of re-sends, as many times as said, and a
     * person was told each time.
     */
    protected function awaitUnanswered(int $times): void
    {
        $this->await(
            "the platform ran out of re-sends {$times} times and raised an alert each time",
            fn (): bool => array_column($this->platform->alerts(), 'kind') === array_fill(0, $times, 'unanswered'),
        );
    }
}
