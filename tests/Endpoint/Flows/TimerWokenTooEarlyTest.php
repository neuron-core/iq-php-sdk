<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Flows;

use NeuronCore\Cloud\Tests\Support\EndpointFlows\Move;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\PlatformSimulator;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\PlaysOneFlow;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Shop;
use PHPUnit\Framework\TestCase;

use function count;
use function time;

/**
 * Row 13 of "When things go wrong" and rule 8 of appendix B: the clock of
 * the platform runs two minutes ahead of the application's, so the wake of
 * a timer that is due in one minute arrives too early. Neuron reads its own
 * clock and returns the same wait and attempt; the platform tries again later.
 */
class TimerWokenTooEarlyTest extends TestCase
{
    use PlaysOneFlow;

    /** Seconds the clock of the platform runs ahead: within the window of a signature. */
    protected const CLOCK_AHEAD = 120;

    protected static Move $whenThePlatformBelievedTheTimerDue;

    protected static Move $afterTheWake;

    /** When the platform plans to wake the timer again, by the application's clock. */
    protected static ?int $triesAgainAt;

    /** When the platform heard that its wake changed nothing. */
    protected static int $heardAt;

    /** How many messages the endpoint had been delivered before the platform heard that. */
    protected static int $deliveredBefore;

    /** @var array{int, int} how many node bodies had been entered before the wake, and after the workers took it */
    protected static array $entered;

    public function test_a_platform_whose_clock_runs_ahead_wakes_a_timer_that_is_not_due(): void
    {
        [, , $paid] = self::shop()->platform->reports();

        self::assertSame(Move::WokeTimer, self::$whenThePlatformBelievedTheTimerDue);
        self::assertGreaterThan(self::$heardAt, $paid['run']['wait']['wakeAt'], 'By the clock of the application the timer was not due.');
    }

    public function test_the_endpoint_queues_a_wake_that_comes_too_early(): void
    {
        [, , $wake, $again] = self::shop()->platform->exchanges();

        self::assertSame(['wake', 202], [$wake['message']['verb'], $wake['answer']->statusCode]);
        self::assertSame(['wake', 202], [$again['message']['verb'], $again['answer']->statusCode]);
    }

    public function test_a_wake_before_its_time_is_reported_suspended_with_no_executed_attempt(): void
    {
        [, , , $early, $again] = self::shop()->platform->reports();

        self::assertSame(['wake', 'suspended', null, null], [$early['verb'], $early['outcome'], $early['code'], $early['executedAttempt']]);
        self::assertSame(['wake', 'suspended', null, null], [$again['verb'], $again['outcome'], $again['code'], $again['executedAttempt']]);
    }

    public function test_a_wake_before_its_time_returns_the_same_wait_and_attempt(): void
    {
        [, , $paid, $early, $again] = self::shop()->platform->reports();

        self::assertSame(2, $paid['run']['attempt']);
        self::assertSame($paid['run'], $early['run']);
        self::assertSame($paid['run'], $again['run']);
    }

    public function test_a_wake_before_its_time_enters_no_node_body(): void
    {
        [$before, $after] = self::$entered;

        self::assertSame(3, $before);
        self::assertSame($before, $after);
    }

    public function test_the_platform_tries_the_timer_again_one_to_sixty_seconds_later_and_sends_nothing_meanwhile(): void
    {
        self::assertSame(Move::Waits, self::$afterTheWake);
        self::assertGreaterThanOrEqual(1, self::$triesAgainAt - self::$heardAt);
        self::assertLessThanOrEqual(60, self::$triesAgainAt - self::$heardAt);
        self::assertCount(self::$deliveredBefore, self::shop()->platform->exchanges());
    }

    public function test_every_body_and_every_answer_of_the_flow_respects_the_protocol(): void
    {
        self::assertProtocolRespected(self::shop());
    }

    protected static function open(): Shop
    {
        return new Shop(shipsAfter: 60);
    }

    protected static function play(Shop $shop): void
    {
        $platform = new PlatformSimulator($shop->platform, $shop->cloud, Shop::DEFINITION, Shop::WORKFLOW_ID, clockAhead: self::CLOCK_AHEAD);

        $platform->start();
        $shop->work();
        $platform->advance();
        $shop->paymentWebhook();
        $shop->work();
        $before = $shop->executions->total();

        self::$whenThePlatformBelievedTheTimerDue = $platform->advance();
        $shop->work();
        self::$entered = [$before, $shop->executions->total()];

        self::$deliveredBefore = count($shop->platform->exchanges());
        self::$heardAt = time();
        self::$afterTheWake = $platform->advance();
        self::$triesAgainAt = $platform->resumesAt();
    }
}
