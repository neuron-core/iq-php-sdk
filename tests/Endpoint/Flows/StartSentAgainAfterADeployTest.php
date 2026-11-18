<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Flows;

use NeuronCore\IQ\Tests\Stub\EndpointFlows\OrderWorkflow;
use NeuronCore\IQ\Tests\Support\EndpointFlows\Move;
use NeuronCore\IQ\Tests\Support\EndpointFlows\PlatformSimulator;
use NeuronCore\IQ\Tests\Support\EndpointFlows\PlaysOneFlow;
use NeuronCore\IQ\Tests\Support\EndpointFlows\Shop;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Row 12 of "When things go wrong" and rule 13 of appendix B: a deploy has
 * brought version 2 to the web tier and not yet to the workers. The platform
 * starts a run on version 2; the endpoint queues it, a worker that still
 * runs the old code aborts it, and the platform sends the same start again,
 * which a worker with the new code then executes once.
 */
class StartSentAgainAfterADeployTest extends TestCase
{
    use PlaysOneFlow;

    protected static PlatformSimulator $platform;

    protected static Move $afterTheAbort;

    protected static Move $afterTheSecondSending;

    /** How many node bodies had been entered when the old workers were done. */
    protected static int $enteredByTheOldWorkers;

    public function test_the_endpoint_queues_a_start_on_a_version_the_web_tier_holds(): void
    {
        [$start, $copy] = self::shop()->platform->exchanges();

        self::assertSame(['start', 202], [$start['message']['verb'], $start['answer']->statusCode]);
        self::assertSame(['start', 202], [$copy['message']['verb'], $copy['answer']->statusCode]);
    }

    public function test_a_worker_that_lacks_the_version_aborts_the_start_as_unknown_version(): void
    {
        [$aborted, $copy] = self::shop()->platform->reports();

        self::assertSame(['start', 'aborted', 'unknown_version', null], [$aborted['verb'], $aborted['outcome'], $aborted['code'], $aborted['errorClass']]);
        self::assertSame(['aborted', 'unknown_version'], [$copy['outcome'], $copy['code']]);
    }

    public function test_an_aborted_start_ran_nothing_and_claimed_nothing(): void
    {
        [$aborted] = self::shop()->platform->reports();

        self::assertSame(0, self::$enteredByTheOldWorkers);
        self::assertSame([null, null, null, true], [$aborted['executedAttempt'], $aborted['claimed'], $aborted['run'], $aborted['snapshotRead']]);
    }

    public function test_the_platform_sends_the_same_start_again(): void
    {
        [$first, , $again] = array_column(self::shop()->platform->exchanges(), 'message');

        self::assertSame(Move::SentStartAgain, self::$afterTheAbort);
        self::assertSame($first, $again, 'The same run ID, invocation ID and use-by time.');
    }

    public function test_a_worker_that_holds_the_version_executes_the_start_that_was_sent_again(): void
    {
        [, , $started] = self::shop()->platform->reports();

        self::assertSame(['start', self::$platform->runId, 'suspended', 1, true], [$started['verb'], $started['runId'], $started['outcome'], $started['executedAttempt'], $started['claimed']]);
        self::assertSame(OrderWorkflow::EVENT, $started['run']['wait']['eventName']);
    }

    public function test_the_start_that_was_sent_again_runs_the_order_once(): void
    {
        [, , , $copy] = self::shop()->platform->reports();

        self::assertSame(['rejected', 'already_claimed'], [$copy['outcome'], $copy['code']]);
        self::assertSame(1, self::shop()->executions->total());
        self::assertSame(['placed:order:123'], self::shop()->transcript->lines());
    }

    public function test_the_platform_does_not_send_again_a_start_that_was_claimed(): void
    {
        self::assertSame(Move::Waits, self::$afterTheSecondSending);
        self::assertCount(4, self::shop()->platform->exchanges());
    }

    public function test_every_body_and_every_answer_of_the_flow_respects_the_protocol(): void
    {
        self::assertProtocolRespected(self::shop());
    }

    protected static function open(): Shop
    {
        return new Shop(shipsAfter: 60, versions: [1, 2]);
    }

    protected static function play(Shop $shop): void
    {
        $platform = self::$platform = new PlatformSimulator($shop->platform, $shop->iq, Shop::DEFINITION, Shop::WORKFLOW_ID, version: 2);

        $shop->workersHold(1);
        $platform->start();
        $shop->work();
        self::$enteredByTheOldWorkers = $shop->executions->total();
        self::$afterTheAbort = $platform->advance();

        $shop->workersHold(1, 2);
        $shop->work();
        self::$afterTheSecondSending = $platform->advance();
    }
}
