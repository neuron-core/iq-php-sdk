<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Flows;

use NeuronCore\Cloud\Tests\Stub\EndpointFlows\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\EndpointFlows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Stub\EndpointFlows\ShipOrderNode;
use NeuronCore\Cloud\Tests\Stub\EndpointFlows\ShippingFailed;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Move;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\PlatformSimulator;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\PlaysOneFlow;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Secrets;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\Shop;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function in_array;
use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;
use const SIGKILL;

/**
 * The end of milestone 3: example (a) of section 5 of the design, with a
 * script in the place of Neuron Cloud. The platform starts an order, wakes
 * its timer when it is due, retries the segment that failed, finds the run
 * a killed worker left behind and has another worker finish it. It sends
 * every message twice. The endpoint answers in the process of the test, and
 * every message of the queue is executed by a worker process on SQLite.
 */
class OrderPlayedByThePlatformTest extends TestCase
{
    use PlaysOneFlow;

    protected static PlatformSimulator $platform;

    /** @var array<string, Move> what the platform did each time it was asked to advance, by the moment */
    protected static array $moves = [];

    /** @var array<string, array{int, int}> how many times the body of the first and of the second node had been entered, by the moment */
    protected static array $entered = [];

    /** The clock of the application just before the platform sent the wake of the timer. */
    protected static int $timerWokenNotBefore;

    /** The signal that ended the worker of the retry. */
    protected static ?int $workerEndedBy;

    public function test_the_platform_plays_the_order_through_a_timer_a_retry_and_a_killed_worker(): void
    {
        self::assertSame(
            [
                'the order waits for its payment' => Move::Waits,
                'the timer is not due' => Move::Waits,
                'the timer is due' => Move::WokeTimer,
                'no worker has taken the wake yet' => Move::Waits,
                'the segment after the timer failed' => Move::Retried,
                'no worker has taken the retry yet' => Move::Waits,
                'the worker of the retry is silent and its lease runs' => Move::Waits,
                'the lease of the silent worker has passed' => Move::RetriedDead,
                'the order has shipped' => Move::Closed,
            ],
            self::$moves,
        );
    }

    public function test_the_endpoint_queues_every_order_and_answers_every_question_of_the_platform(): void
    {
        $answered = array_map(
            static fn (array $exchange): string => "{$exchange['message']['verb']}: {$exchange['answer']->statusCode}",
            self::shop()->platform->exchanges(),
        );

        self::assertSame(
            ['start: 202', 'start: 202', 'wake: 202', 'wake: 202', 'wake: 202', 'wake: 202', 'inspect: 200', 'inspect: 200', 'wake: 202', 'wake: 202'],
            $answered,
        );
    }

    public function test_the_start_the_platform_sends_runs_the_order_to_its_first_wait(): void
    {
        [$started] = self::shop()->platform->reports();

        self::assertSame(['start', 'platform', self::$platform->runId], [$started['verb'], $started['origin'], $started['runId']]);
        self::assertSame(['suspended', 1, true], [$started['outcome'], $started['executedAttempt'], $started['claimed']]);
        self::assertSame(['wait_for_event', OrderWorkflow::EVENT], [$started['run']['wait']['type'], $started['run']['wait']['eventName']]);
    }

    public function test_the_second_copy_of_the_start_is_rejected_as_already_claimed(): void
    {
        [, $copy] = self::shop()->platform->reports();

        self::assertSame(['start', 'rejected', 'already_claimed', null], [$copy['verb'], $copy['outcome'], $copy['code'], $copy['executedAttempt']]);
        self::assertSame([1, 0], self::$entered['after the start']);
    }

    public function test_the_payment_the_application_delivers_itself_leaves_the_order_on_its_timer(): void
    {
        [, , $paid] = self::shop()->platform->reports();

        self::assertSame(['signal', 'application', 'suspended', 2], [$paid['verb'], $paid['origin'], $paid['outcome'], $paid['executedAttempt']]);
        self::assertSame(['sleep_until', null], [$paid['run']['wait']['type'], $paid['run']['wait']['eventName']]);
        self::assertSame([2, 1], self::$entered['after the payment']);
    }

    public function test_the_platform_does_not_wake_the_timer_before_it_is_due(): void
    {
        [, , $paid] = self::shop()->platform->reports();

        self::assertGreaterThanOrEqual($paid['run']['wait']['wakeAt'], self::$timerWokenNotBefore);
    }

    public function test_the_wake_of_the_timer_is_fenced_with_the_run_and_the_attempt_the_application_reported(): void
    {
        [, , $paid] = self::shop()->platform->reports();
        [, , $wake, $again] = array_column(self::shop()->platform->exchanges(), 'message');

        self::assertSame([$paid['run']['runId'], $paid['run']['attempt']], [$wake['runId'], $wake['attempt']]);
        self::assertSame([$paid['run']['runId'], $paid['run']['attempt']], [$again['runId'], $again['attempt']]);
    }

    public function test_the_wake_of_a_timer_that_is_due_executes_the_segment_after_it(): void
    {
        [, , , $woken] = self::shop()->platform->reports();

        self::assertSame(['wake', 'platform', 3], [$woken['verb'], $woken['origin'], $woken['executedAttempt']]);
        self::assertSame([2, 2], self::$entered['after the timer'], 'The node after the timer ran again from its top.');
    }

    public function test_a_segment_that_fails_is_reported_with_the_class_of_its_exception_and_the_failed_run(): void
    {
        [, , , $failed] = self::shop()->platform->reports();

        self::assertSame(['failed', null, ShippingFailed::class], [$failed['outcome'], $failed['code'], $failed['errorClass']]);
        self::assertSame(['failed', 3, null], [$failed['run']['status'], $failed['run']['attempt'], $failed['run']['leaseExpiresAt']]);
    }

    public function test_the_failed_run_is_retried_by_a_wake_fenced_with_the_attempt_the_failure_reported(): void
    {
        [, , , $failed] = self::shop()->platform->reports();
        [, , , , $retry, $again] = array_column(self::shop()->platform->exchanges(), 'message');

        self::assertSame(['wake', $failed['run']['runId'], $failed['run']['attempt']], [$retry['verb'], $retry['runId'], $retry['attempt']]);
        self::assertSame(['wake', $failed['run']['runId'], $failed['run']['attempt']], [$again['verb'], $again['runId'], $again['attempt']]);
    }

    public function test_the_worker_killed_inside_a_node_body_announced_its_segment_and_reported_nothing(): void
    {
        [, , , , $retry] = array_column(self::shop()->platform->exchanges(), 'message');
        $platform = self::shop()->platform;

        self::assertSame(SIGKILL, self::$workerEndedBy);
        self::assertSame([2, 3], self::$entered['at the kill'], 'The worker stood in the body of the node after the timer.');
        self::assertTrue(in_array($retry['invocationId'], array_column($platform->announcements(), 'invocationId'), true));
        self::assertFalse(in_array($retry['invocationId'], array_column($platform->reports(), 'invocationId'), true));
    }

    public function test_the_run_of_the_killed_worker_is_found_by_an_inspect_once_its_lease_has_passed(): void
    {
        $found = self::inspectAnswer();

        self::assertSame(['running', 4], [$found['run']['status'], $found['run']['attempt']]);
        self::assertLessThanOrEqual($found['at'], $found['run']['leaseExpiresAt']);
        self::assertSame([true, true], [$found['claimed'], $found['snapshotRead']]);
    }

    public function test_the_dead_run_is_woken_with_the_attempt_the_inspect_showed(): void
    {
        $found = self::inspectAnswer();
        [, , , , , , , , $wake, $again] = array_column(self::shop()->platform->exchanges(), 'message');

        self::assertSame(['wake', $found['run']['runId'], $found['run']['attempt']], [$wake['verb'], $wake['runId'], $wake['attempt']]);
        self::assertSame($wake, $again, 'A message sent again is the same message, with the same invocation ID.');
    }

    public function test_another_worker_finishes_the_dead_run(): void
    {
        [, , , , , , $finished] = self::shop()->platform->reports();

        self::assertSame(['wake', 'platform', 'completed'], [$finished['verb'], $finished['origin'], $finished['outcome']]);
        self::assertSame([5, null], [$finished['executedAttempt'], $finished['run']], 'It took the run over, and the run is gone.');
    }

    public function test_the_steps_committed_before_the_kill_are_not_executed_again(): void
    {
        self::assertSame([2, 3], self::$entered['at the kill']);
        self::assertSame([2, 4], self::$entered['at the end'], 'Only the node in flight ran again.');
    }

    public function test_the_second_copy_of_a_wake_is_refused_as_stale_and_opens_no_segment(): void
    {
        [, , , , $ofTheTimer, $ofTheRetry, , $ofTheDeadRun] = self::shop()->platform->reports();

        self::assertSame(['refused', 'stale_attempt', null], [$ofTheTimer['outcome'], $ofTheTimer['code'], $ofTheTimer['executedAttempt']]);
        self::assertSame(['refused', 'stale_attempt', null], [$ofTheRetry['outcome'], $ofTheRetry['code'], $ofTheRetry['executedAttempt']]);
        self::assertSame(['refused', 'stale_run', null], [$ofTheDeadRun['outcome'], $ofTheDeadRun['code'], $ofTheDeadRun['executedAttempt']]);
    }

    public function test_no_copy_of_a_delivery_enters_a_node_body(): void
    {
        $shop = self::shop();

        // One entry per order that executed: the start and the signal for the first node, the signal, the wake
        // of the timer, the retry and the wake of the dead run for the second.
        self::assertSame(2, $shop->executions->count(AwaitPaymentNode::class));
        self::assertSame(4, $shop->executions->count(ShipOrderNode::class));
        self::assertSame(6, $shop->executions->total());
    }

    public function test_every_node_body_that_completed_was_executed_exactly_once(): void
    {
        self::assertSame(['placed:order:123', 'paid:order:123', 'shipped:order:123'], self::shop()->transcript->lines());
    }

    public function test_every_body_and_every_answer_of_the_flow_respects_the_protocol(): void
    {
        $shop = self::shop();

        self::assertProtocolRespected($shop);
        self::assertCount(9, $shop->platform->announcements());
        self::assertCount(8, $shop->platform->reports());
    }

    public function test_nothing_the_order_carries_crosses_the_wire_in_either_direction(): void
    {
        $shop = self::shop();
        $payloads = array_column($shop->taken(), 'payload');

        self::assertContains(['amount' => 49.9, 'reference' => Secrets::SIGNAL], $payloads, 'The queue did carry what was planted.');
        self::assertNothingPlantedCrossedTheWire($shop);
    }

    protected static function open(): Shop
    {
        return new Shop(shipsAfter: 2, shippingFailures: 1);
    }

    protected static function play(Shop $shop): void
    {
        $platform = self::$platform = new PlatformSimulator($shop->platform, $shop->cloud, Shop::DEFINITION, Shop::WORKFLOW_ID);

        $platform->start();
        $shop->work();
        self::$entered['after the start'] = self::entered($shop);
        self::$moves['the order waits for its payment'] = $platform->advance();

        $shop->paymentWebhook();
        $shop->work();
        self::$entered['after the payment'] = self::entered($shop);
        self::$moves['the timer is not due'] = $platform->advance();

        self::awaitInstant($platform->resumesAt());
        self::$timerWokenNotBefore = time();
        self::$moves['the timer is due'] = $platform->advance();
        self::$moves['no worker has taken the wake yet'] = $platform->advance();
        $shop->work();
        self::$entered['after the timer'] = self::entered($shop);

        self::$moves['the segment after the timer failed'] = $platform->advance();
        self::$moves['no worker has taken the retry yet'] = $platform->advance();
        [$retry, $copy] = $shop->take();
        self::$workerEndedBy = $shop->worker($retry)->killOnce(static fn (): bool => $shop->executions->count(ShipOrderNode::class) === 3);
        $shop->worker($copy)->finish();
        self::$entered['at the kill'] = self::entered($shop);
        $shop->gate->open();

        self::$moves['the worker of the retry is silent and its lease runs'] = $platform->advance();
        self::awaitInstant($platform->resumesAt());
        self::$moves['the lease of the silent worker has passed'] = $platform->advance();
        $shop->work();
        self::$entered['at the end'] = self::entered($shop);

        self::$moves['the order has shipped'] = $platform->advance();
    }

    /**
     * @return array{int, int} how many times the body of the first and of the second node has been entered
     */
    protected static function entered(Shop $shop): array
    {
        return [$shop->executions->count(AwaitPaymentNode::class), $shop->executions->count(ShipOrderNode::class)];
    }

    /**
     * @return array<string, mixed> what the endpoint answered the inspect of the platform
     */
    protected static function inspectAnswer(): array
    {
        [, , , , , , $inspect] = self::shop()->platform->exchanges();

        return json_decode($inspect['answer']->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
