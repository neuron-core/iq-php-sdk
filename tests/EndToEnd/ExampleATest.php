<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\EndToEnd\DeployedShop;
use NeuronCore\IQ\Tests\Support\EndToEnd\Deployment;
use NeuronCore\IQ\Tests\Support\EndToEnd\EndToEndTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Wait;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Confidential;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\OrderPlaced;
use NeuronCore\IQ\Tests\Support\ExampleApp\OrderWorkflow;
use NeuronCore\IQ\Tests\Support\ExampleApp\PlannedFailure;
use NeuronCore\IQ\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Shop;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_column;
use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function base64_decode;
use function count;
use function implode;
use function in_array;
use function json_decode;
use function json_encode;
use function max;
use function str_contains;
use function time;
use function unserialize;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The end of milestone 5: example (a) of section 5 of the design from deploy
 * to completion, over real HTTP and with nothing faked. The platform is the
 * simulator, a server of its own that knows the protocol from appendices A
 * and B alone. The shop is a real application: its endpoint, its checkout and
 * its payment webhook on a web server, its workers in processes of their own,
 * SQLite as its workflow store and as its queue.
 *
 * Four orders are played at once, and each test checks one thing about what
 * happened:
 *
 *   A1001  the platform starts it
 *   B1002  the checkout of the shop starts it, on the queue
 *   C1003  the checkout of the shop starts it, with its first segment run in the request
 *   D1004  the platform starts it, and its shipment fails once before it ships
 */
class ExampleATest extends EndToEndTestCase
{
    protected const STARTED_BY_THE_PLATFORM = 'A1001';

    protected const CHECKED_OUT_ON_THE_QUEUE = 'B1002';

    protected const CHECKED_OUT_IN_PLACE = 'C1003';

    protected const SHIPMENT_FAILS_ONCE = 'D1004';

    protected const ORDERS = [
        self::STARTED_BY_THE_PLATFORM,
        self::CHECKED_OUT_ON_THE_QUEUE,
        self::CHECKED_OUT_IN_PLACE,
        self::SHIPMENT_FAILS_ONCE,
    ];

    /** Seconds between the payment of an order and the moment it ships. */
    protected const SHIPS_AFTER = 1;

    /** Seconds the retry policy of the platform waits after a failure. */
    protected const RETRY_DELAY = 1;

    /** What the customer wrote at the checkout: the start input of an order the shop starts. */
    protected const NOTE = 'CONFIDENTIAL-e4a7b2-written-by-the-customer-at-the-checkout';

    /** What the payment provider sends with a payment: the payload of the signal. */
    protected const PAYMENT_REFERENCE = 'CONFIDENTIAL-90d3f5-reference-of-the-payment';

    protected const AWAITING_PAYMENT = 'the orders wait for their payment';

    protected const SLEEPING = 'the orders sleep until they ship';

    protected const SHIPMENT_FAILED = 'the shipment of one order has failed';

    protected const SHIPPED = 'the orders have shipped';

    protected static ?Deployment $deployment = null;

    /** What stopped the flow: every test of the class fails with it, and the flow is not played again. */
    protected static ?Throwable $stoppedBy = null;

    /** @var array{exitCode: int|null, printed: string} how bin/sync.php ended */
    protected static array $sync;

    /** @var array<string, mixed> what the platform held after the sync */
    protected static array $manifest;

    /** @var array<string, array<string, mixed>> what the platform answered when a trigger fired, by order */
    protected static array $fired = [];

    /** @var array<string, mixed> what the platform answered when the firing of the first order was named a second time */
    protected static array $firedAgain;

    /** @var array<string, array{status: int, body: array<string, mixed>}> what the checkout answered, by order */
    protected static array $checkouts = [];

    /** How many times the first node body of the order checked out in place had been entered when its request returned. */
    protected static int $enteredDuringTheCheckout;

    /** @var array<string, int> the status the payment webhook answered, by order */
    protected static array $payments = [];

    /** @var array<int, array<string, mixed>> what the platform sent at the first tick after the timer of every order was due, and at the one before */
    protected static array $sentAroundTheTimers = [];

    /** @var array<string, array<string, array{platform: array<string, mixed>, store: array<string, mixed>|null}>> what the platform held for each order and what the store showed, by the moment */
    protected static array $seen = [];

    /** @var string[] every value the workflow store held, while the orders slept and when the shipment had failed */
    protected static array $stored = [];

    protected static WireLog $wire;

    /** @var array<int, array<string, mixed>> */
    protected static array $alerts;

    /** The bytes of the only memory of the platform and of the file it taps the wire into. */
    protected static string $memoryOfThePlatform;

    /** @var array<int, array{list: string, job: string}> every message the queue of the shop carried */
    protected static array $carried;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$deployment instanceof Deployment) {
            self::$deployment = self::forTheClass(new Deployment(['SHOP_SHIPS_AFTER' => (string) self::SHIPS_AFTER]));

            try {
                self::play(self::$deployment);
            } catch (Throwable $stopped) {
                self::$stoppedBy = $stopped;
            }
        }

        if (self::$stoppedBy instanceof Throwable) {
            throw self::$stoppedBy;
        }
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        self::$deployment = null;
        self::$stoppedBy = null;
        self::$fired = self::$checkouts = self::$payments = self::$sentAroundTheTimers = self::$seen = self::$stored = [];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function orders(): array
    {
        return [
            'the order the platform starts' => [self::STARTED_BY_THE_PLATFORM],
            'the order checked out on the queue' => [self::CHECKED_OUT_ON_THE_QUEUE],
            'the order checked out in place' => [self::CHECKED_OUT_IN_PLACE],
            'the order whose shipment fails once' => [self::SHIPMENT_FAILS_ONCE],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ordersOfTheCheckout(): array
    {
        return [
            'on the queue' => [self::CHECKED_OUT_ON_THE_QUEUE],
            'in place' => [self::CHECKED_OUT_IN_PLACE],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ordersOfThePlatform(): array
    {
        return [
            'the order that ships at once' => [self::STARTED_BY_THE_PLATFORM],
            'the order whose shipment fails once' => [self::SHIPMENT_FAILS_ONCE],
        ];
    }

    // ----------------------------------------------------------------
    // The deploy
    // ----------------------------------------------------------------

    public function test_the_sync_script_ends_the_deploy_with_what_the_platform_answered(): void
    {
        self::assertSame(0, self::$sync['exitCode'], self::$sync['printed']);
        self::assertSame(
            ['modified' => true, 'retirable' => [], 'stranded' => []],
            json_decode(self::$sync['printed'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_the_platform_holds_the_endpoint_and_the_definition_the_sync_published(): void
    {
        self::assertSame(
            [
                'endpoint' => DeployedShop::PUBLIC_URL . '/iq.php',
                'definitions' => [['definition' => Shop::DEFINITION, 'version' => 1, 'leaseSeconds' => 600]],
                'deliveriesStopped' => false,
            ],
            self::$manifest,
        );
    }

    public function test_the_platform_pings_the_endpoint_before_it_accepts_the_sync(): void
    {
        [$first, $second] = self::$wire->exchanges;

        self::assertSame([WireLog::PING, 200, '{"protocols":[1]}'], [$first['message'], $first['status'], $first['answer']]);
        self::assertSame([WireLog::SYNC, 200], [$second['message'], $second['status']]);
    }

    // ----------------------------------------------------------------
    // The orders the platform starts
    // ----------------------------------------------------------------

    public function test_a_firing_named_twice_issues_one_start(): void
    {
        self::assertSame(self::$fired[self::STARTED_BY_THE_PLATFORM]['runId'], self::$firedAgain['runId']);
        self::assertCount(1, self::$wire->of(WireLog::START, self::workflowIdOf(self::STARTED_BY_THE_PLATFORM)));
    }

    public function test_no_two_starts_of_the_platform_share_a_run_id(): void
    {
        $runIds = array_map(static fn (array $start): string => $start['fields']['runId'], self::$wire->of(WireLog::START));

        self::assertCount(2, $runIds);
        self::assertSame($runIds, array_unique($runIds));
    }

    #[DataProvider('ordersOfThePlatform')]
    public function test_the_start_of_the_platform_names_the_current_version_and_a_use_by_time_within_the_hour(string $order): void
    {
        [$start] = self::$wire->of(WireLog::START, self::workflowIdOf($order));
        $lifetime = $start['fields']['expiresAt'] - (int) $start['headers']['webhook-timestamp'];

        self::assertMatchesRegularExpression('/^1-[0-9a-f-]{36}$/', $start['fields']['runId']);
        self::assertSame(Shop::DEFINITION, $start['fields']['definition']);
        self::assertGreaterThanOrEqual(600, $lifetime);
        self::assertLessThanOrEqual(3600, $lifetime);
    }

    #[DataProvider('ordersOfThePlatform')]
    public function test_the_endpoint_answers_the_start_202_and_a_worker_runs_it_to_its_first_wait(string $order): void
    {
        [$start] = self::$wire->of(WireLog::START, self::workflowIdOf($order));
        [$report] = self::reportsOf($order, Verb::Start);

        self::assertSame(202, $start['status']);
        self::assertSame($start['fields']['invocationId'], $report['invocationId']);
        self::assertSame(['platform', 'suspended', 1, true], [$report['origin'], $report['outcome'], $report['executedAttempt'], $report['claimed']]);
    }

    #[DataProvider('ordersOfThePlatform')]
    public function test_the_webhook_id_of_a_start_is_its_invocation_id(string $order): void
    {
        [$start] = self::$wire->of(WireLog::START, self::workflowIdOf($order));

        self::assertSame($start['fields']['invocationId'], $start['headers']['webhook-id']);
    }

    // ----------------------------------------------------------------
    // The orders the checkout of the shop starts
    // ----------------------------------------------------------------

    public function test_a_checkout_on_the_queue_is_answered_202_with_the_run_id_the_platform_then_holds(): void
    {
        $checkout = self::$checkouts[self::CHECKED_OUT_ON_THE_QUEUE];

        self::assertSame(202, $checkout['status']);
        self::assertSame(self::held(self::AWAITING_PAYMENT, self::CHECKED_OUT_ON_THE_QUEUE)['runId'], $checkout['body']['runId']);
    }

    public function test_a_checkout_on_the_queue_puts_its_start_on_the_list_of_the_segments(): void
    {
        self::assertSame(
            [SpoolDispatcher::SEGMENTS],
            array_column(self::carriedFor(self::CHECKED_OUT_ON_THE_QUEUE, Verb::Start), 'list'),
        );
    }

    public function test_a_checkout_in_place_runs_the_first_segment_inside_its_request(): void
    {
        self::assertSame(['status' => 200, 'body' => ['orderId' => self::CHECKED_OUT_IN_PLACE]], self::$checkouts[self::CHECKED_OUT_IN_PLACE]);
        self::assertSame(1, self::$enteredDuringTheCheckout);
    }

    public function test_a_checkout_in_place_queues_no_start_and_leaves_its_two_notices_on_their_own_list(): void
    {
        self::assertSame([], self::carriedFor(self::CHECKED_OUT_IN_PLACE, Verb::Start));
        self::assertSame(
            [SpoolDispatcher::NOTICES],
            array_column(self::carriedFor(self::CHECKED_OUT_IN_PLACE, Verb::Announce, Verb::Start), 'list'),
        );
        self::assertSame(
            [SpoolDispatcher::NOTICES],
            array_column(self::carriedFor(self::CHECKED_OUT_IN_PLACE, Verb::Observe, Verb::Start), 'list'),
        );
    }

    #[DataProvider('ordersOfTheCheckout')]
    public function test_the_platform_never_sends_a_start_for_an_order_the_shop_started(string $order): void
    {
        $held = self::held(self::SHIPPED, $order);

        self::assertSame([], self::$wire->of(WireLog::START, self::workflowIdOf($order)));
        self::assertSame('application', $held['origin']);
        self::assertNotContains('start', array_column($held['orders'], 'verb'));
    }

    #[DataProvider('ordersOfTheCheckout')]
    public function test_the_platform_learns_of_a_checkout_from_its_announcement_and_its_report_alone(string $order): void
    {
        [$announcement] = self::$wire->of(WireLog::ANNOUNCEMENT, self::workflowIdOf($order));
        [$report] = self::reportsOf($order, Verb::Start);

        self::assertSame(['start', 'application', null, 600], [
            $announcement['fields']['verb'],
            $announcement['fields']['origin'],
            $announcement['fields']['attempt'],
            $announcement['fields']['leaseSeconds'],
        ]);
        self::assertSame($announcement['fields']['invocationId'], $report['invocationId']);
        self::assertSame(['application', 'suspended', 1], [$report['origin'], $report['outcome'], $report['executedAttempt']]);
    }

    public function test_a_start_run_in_the_request_is_reported_as_never_claimed(): void
    {
        [$queued] = self::reportsOf(self::CHECKED_OUT_ON_THE_QUEUE, Verb::Start);
        [$inPlace] = self::reportsOf(self::CHECKED_OUT_IN_PLACE, Verb::Start);

        self::assertTrue($queued['claimed']);
        self::assertFalse($inPlace['claimed']);
    }

    // ----------------------------------------------------------------
    // Every order, whoever started it
    // ----------------------------------------------------------------

    #[DataProvider('orders')]
    public function test_the_order_waits_for_its_payment_under_one_timer_for_the_deadline(string $order): void
    {
        $held = self::held(self::AWAITING_PAYMENT, $order);
        $wait = $held['run']['wait'];

        self::assertSame(['suspended', 1], [$held['run']['status'], $held['run']['attempt']]);
        self::assertSame(['wait_for_event', OrderWorkflow::PAYMENT_RECEIVED], [$wait['type'], $wait['eventName']]);
        self::assertSame(
            [['action' => 'wake', 'reason' => "timer:{$wait['interruptId']}", 'at' => $wait['wakeAt'], 'held' => null]],
            $held['timers'],
        );
    }

    #[DataProvider('orders')]
    public function test_the_payment_webhook_is_answered_202_and_a_worker_delivers_the_signal_under_the_fence_captured_there(string $order): void
    {
        [$report] = self::reportsOf($order, Verb::Signal);
        [$announcement] = array_values(array_filter(
            self::$wire->of(WireLog::ANNOUNCEMENT, self::workflowIdOf($order)),
            static fn (array $announcement): bool => $announcement['fields']['verb'] === 'signal',
        ));

        self::assertSame(202, self::$payments[$order]);
        self::assertSame(['application', 1], [$announcement['fields']['origin'], $announcement['fields']['attempt']]);
        self::assertSame(['suspended', 2, null], [$report['outcome'], $report['executedAttempt'], $report['claimed']]);
    }

    #[DataProvider('orders')]
    public function test_the_paid_order_sleeps_under_one_timer_for_the_time_it_ships(string $order): void
    {
        $held = self::held(self::SLEEPING, $order);
        $wait = $held['run']['wait'];

        self::assertSame(['suspended', 2], [$held['run']['status'], $held['run']['attempt']]);
        self::assertSame(['sleep_until', null], [$wait['type'], $wait['eventName']]);
        self::assertSame(
            [['action' => 'wake', 'reason' => "timer:{$wait['interruptId']}", 'at' => $wait['wakeAt'], 'held' => null]],
            $held['timers'],
        );
    }

    #[DataProvider('orders')]
    public function test_the_platform_wakes_the_order_when_its_timer_is_due_and_not_before(string $order): void
    {
        $sleeping = self::held(self::SLEEPING, $order);
        $sentAt = (int) self::wakeOfTheTimer($order)['headers']['webhook-timestamp'];

        self::assertGreaterThanOrEqual($sleeping['run']['wait']['wakeAt'], $sentAt);
        self::assertContains($sleeping['runId'], array_column(self::$sentAroundTheTimers, 'runId'), 'At the first tick after its timer was due, at the latest.');
    }

    #[DataProvider('orders')]
    public function test_the_wake_of_the_timer_carries_the_run_the_attempt_and_the_definition_the_application_reported(string $order): void
    {
        $reported = self::held(self::SLEEPING, $order);
        $wake = self::wakeOfTheTimer($order);

        self::assertSame(
            [$reported['run']['runId'], $reported['run']['attempt'], $reported['definition']],
            [$wake['fields']['runId'], $wake['fields']['attempt'], $wake['fields']['definition']],
        );
        self::assertSame(202, $wake['status']);
    }

    #[DataProvider('orders')]
    public function test_the_platform_records_the_shipped_order_as_completed_and_plans_nothing_more_for_it(string $order): void
    {
        $held = self::held(self::SHIPPED, $order);

        self::assertSame(['completed', null, []], [$held['state'], $held['run'], $held['timers']]);
        self::assertSame('completed', self::lastReportOf($order)['outcome']);
        self::assertNull(self::lastReportOf($order)['run'], 'A run that completes under the SDK is gone.');
    }

    #[DataProvider('orders')]
    public function test_the_record_of_the_platform_matches_the_store_of_the_shop_at_every_step(string $order): void
    {
        $steps = array_filter(self::$seen, static fn (array $orders): bool => isset($orders[$order]));
        self::assertGreaterThanOrEqual(3, count($steps));

        foreach ($steps as $moment => $orders) {
            self::assertSame($orders[$order]['store'], $orders[$order]['platform']['run'], "When {$moment}.");
        }
    }

    #[DataProvider('orders')]
    public function test_the_platform_holds_one_run_for_the_workflow_id_of_the_order(string $order): void
    {
        self::assertCount(1, self::deployment()->platform->runsOf(self::workflowIdOf($order)));
    }

    // ----------------------------------------------------------------
    // The shipment that fails once
    // ----------------------------------------------------------------

    public function test_the_failed_shipment_leaves_the_run_failed_in_the_store_and_on_the_platform(): void
    {
        ['platform' => $held, 'store' => $stored] = self::$seen[self::SHIPMENT_FAILED][self::SHIPMENT_FAILS_ONCE];

        self::assertSame(['failed', 3, null], [$stored['status'] ?? null, $stored['attempt'] ?? null, $stored['leaseExpiresAt'] ?? null]);
        self::assertSame($stored, $held['run']);
    }

    public function test_the_platform_counts_the_failure_once_and_knows_only_the_class_of_its_exception(): void
    {
        $failures = self::held(self::SHIPPED, self::SHIPMENT_FAILS_ONCE)['failures'];

        self::assertCount(1, $failures);
        self::assertSame([3, 'failed', PlannedFailure::class], [$failures[0]['attempt'], $failures[0]['kind'], $failures[0]['errorClass']]);
    }

    public function test_the_platform_retries_the_failed_run_with_a_wake_fenced_with_the_attempt_of_the_failure(): void
    {
        $failed = self::held(self::SHIPMENT_FAILED, self::SHIPMENT_FAILS_ONCE);
        $retry = self::wakeOf(self::SHIPMENT_FAILS_ONCE, 3);

        self::assertSame([$failed['runId'], 3, 202], [$retry['fields']['runId'], $retry['fields']['attempt'], $retry['status']]);
        self::assertSame(
            [['action' => 'wake', 'reason' => 'retry', 'at' => $failed['failures'][0]['countedAt'] + self::RETRY_DELAY, 'held' => null]],
            $failed['timers'],
        );
    }

    public function test_the_platform_waits_the_delay_of_its_retry_policy_before_it_retries(): void
    {
        $failure = self::held(self::SHIPPED, self::SHIPMENT_FAILS_ONCE)['failures'][0];
        $retry = self::wakeOf(self::SHIPMENT_FAILS_ONCE, 3);

        self::assertGreaterThanOrEqual((int) $failure['countedAt'] + self::RETRY_DELAY, (int) $retry['headers']['webhook-timestamp']);
    }

    public function test_the_retry_ships_the_order_at_the_next_attempt(): void
    {
        $shipped = self::lastReportOf(self::SHIPMENT_FAILS_ONCE);

        self::assertSame(['wake', 'platform', 'completed', 4], [$shipped['verb'], $shipped['origin'], $shipped['outcome'], $shipped['executedAttempt']]);
    }

    // ----------------------------------------------------------------
    // What must hold at the end
    // ----------------------------------------------------------------

    #[DataProvider('orders')]
    public function test_every_node_body_ran_to_its_end_exactly_once(string $order): void
    {
        $runs = self::deployment()->shop->nodeRuns;

        self::assertSame(1, $runs->timesFinished(AwaitPaymentNode::class, $order));
        self::assertSame(1, $runs->timesFinished(ShipOrderNode::class, $order));
    }

    #[DataProvider('orders')]
    public function test_every_node_body_was_entered_once_for_each_segment_that_reached_it(string $order): void
    {
        $runs = self::deployment()->shop->nodeRuns;
        // A body runs again from its top when its wait is answered, and once more for the shipment that failed.
        $segmentsThatShip = $order === self::SHIPMENT_FAILS_ONCE ? 3 : 2;

        self::assertSame(2, $runs->timesEntered(AwaitPaymentNode::class, $order));
        self::assertSame($segmentsThatShip, $runs->timesEntered(ShipOrderNode::class, $order));
    }

    public function test_every_message_either_side_sent_and_every_answer_match_their_json_schema(): void
    {
        self::$wire->assertProtocolRespected();
    }

    public function test_every_message_of_the_platform_is_signed_as_appendix_a_says(): void
    {
        self::$wire->assertSignedWith(self::deployment()->platform->signingKey);
    }

    public function test_the_platform_sent_the_endpoint_nothing_but_the_orders_of_the_flow(): void
    {
        // The ping of the sync, the start of two orders, the timer of four and the retry of one: no question was needed.
        self::assertSame([WireLog::PING => 1, WireLog::START => 2, WireLog::WAKE => 5], self::$wire->sentByThePlatform());
    }

    public function test_the_platform_raised_no_alert(): void
    {
        self::assertSame([], self::$alerts);
    }

    public function test_every_segment_reached_the_platform_as_an_announcement_and_a_report(): void
    {
        $announced = array_column(array_column(self::$wire->of(WireLog::ANNOUNCEMENT), 'fields'), 'invocationId');
        $reported = array_column(array_column(self::$wire->of(WireLog::REPORT), 'fields'), 'invocationId');

        // Per order a start, a signal and a wake, and one wake more for the retry.
        self::assertCount(13, $announced);
        self::assertEqualsCanonicalizing($announced, $reported);
    }

    public function test_the_workers_kept_no_message_aside(): void
    {
        $lists = array_unique(array_column(self::$carried, 'list'));

        self::assertEqualsCanonicalizing([SpoolDispatcher::SEGMENTS, SpoolDispatcher::NOTICES], $lists);
    }

    /**
     * The message of the planned failure is the one thing planted that neither the store nor the queue holds:
     * Neuron keeps no message of a failed run. That it was thrown is what the failure the platform counted says.
     */
    public function test_what_an_order_carries_was_really_planted_in_the_application(): void
    {
        $stored = implode("\n", self::$stored);
        $queued = implode("\n", self::inputsCarriedByTheQueue());

        self::assertStringContainsString(Confidential::STATE, $stored);
        self::assertStringContainsString(Confidential::START_EVENT, $stored);
        self::assertStringContainsString(self::NOTE, $stored);
        self::assertStringContainsString(self::PAYMENT_REFERENCE, $stored);
        self::assertStringContainsString(self::NOTE, $queued);
        self::assertStringContainsString(self::PAYMENT_REFERENCE, $queued);
    }

    public function test_nothing_planted_in_an_order_is_in_the_memory_of_the_platform_or_in_what_crossed_the_wire(): void
    {
        $held = json_encode([
            array_map(static fn (string $order): array => self::deployment()->platform->runsOf(self::workflowIdOf($order)), self::ORDERS),
            self::$alerts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $places = array_merge([self::$memoryOfThePlatform, self::deployment()->platform->log(), $held], self::$wire->crossed());

        $leaks = [];
        foreach (self::planted() as $where => $marker) {
            foreach ($places as $place) {
                if (str_contains($place, $marker)) {
                    $leaks[] = "What was planted in {$where} reached the platform.";
                }
            }
        }

        self::assertNotSame(0, self::$wire->count(), 'Nothing crossed the wire: there is no boundary to check.');
        self::assertSame([], array_values(array_unique($leaks)));
    }

    // ----------------------------------------------------------------
    // The flow
    // ----------------------------------------------------------------

    protected static function play(Deployment $deployment): void
    {
        $platform = $deployment->platform;
        $shop = $deployment->shop;

        $platform->configure(['retryAttempts' => 3, 'retryDelay' => self::RETRY_DELAY]);

        self::$sync = $shop->sync();
        self::$manifest = $platform->manifest();

        $shop->startWorker();
        $shop->startWorker();
        $shop->startWorker(SpoolDispatcher::NOTICES);
        $shop->faults->plan('ship.' . self::SHIPMENT_FAILS_ONCE, Faults::FAIL);

        self::placeTheOrders($deployment);
        $deployment->await('every order waits for its payment', static fn (): bool => self::everyOrderWaitsFor($deployment, 'wait_for_event'));
        self::look($deployment, self::AWAITING_PAYMENT, ...self::ORDERS);

        foreach (self::ORDERS as $order) {
            self::$payments[$order] = $shop->pay($order, 49.9, self::PAYMENT_REFERENCE);
        }

        // The platform sends nothing unless its clock ticks, and here it ticks only where the flow says so: no
        // wake leaves before the four sleeping orders were looked at, and no retry before the failed run was.
        Wait::until('every order sleeps until it ships', static fn (): bool => self::everyOrderWaitsFor($deployment, 'sleep_until'));
        self::look($deployment, self::SLEEPING, ...self::ORDERS);

        $lastDue = max(array_map(static fn (string $order): int => self::held(self::SLEEPING, $order)['run']['wait']['wakeAt'], self::ORDERS));
        $sentBefore = $platform->tick();
        Wait::until('the timer of every order is due', static fn (): bool => time() >= $lastDue);
        self::$sentAroundTheTimers = [...$sentBefore, ...$platform->tick()];

        Wait::until(
            'the shipment of one order has failed',
            static fn (): bool => (self::heldNow($deployment, self::SHIPMENT_FAILS_ONCE)['run']['status'] ?? null) === 'failed',
        );
        self::look($deployment, self::SHIPMENT_FAILED, self::SHIPMENT_FAILS_ONCE);

        $deployment->await('every order has shipped and every report has arrived', static fn (): bool => self::everyOrderIsSettled($deployment));
        self::look($deployment, self::SHIPPED, ...self::ORDERS);

        self::$wire = $platform->wire();
        self::$alerts = $platform->alerts();
        self::$memoryOfThePlatform = $platform->memory() . $platform->wireTap();
        self::$carried = $shop->queue()->carried();
    }

    /**
     * Two orders by a trigger of the platform, two by the checkout of the shop.
     */
    protected static function placeTheOrders(Deployment $deployment): void
    {
        $platform = $deployment->platform;
        $shop = $deployment->shop;
        $first = self::STARTED_BY_THE_PLATFORM;

        self::$fired[$first] = $platform->fire(Shop::DEFINITION, self::workflowIdOf($first), 'the-nightly-firing-for-order-A1001');
        self::$firedAgain = $platform->fire(Shop::DEFINITION, self::workflowIdOf($first), 'the-nightly-firing-for-order-A1001');
        self::$fired[self::SHIPMENT_FAILS_ONCE] = $platform->fire(Shop::DEFINITION, self::workflowIdOf(self::SHIPMENT_FAILS_ONCE));

        self::$checkouts[self::CHECKED_OUT_ON_THE_QUEUE] = $shop->checkout(self::CHECKED_OUT_ON_THE_QUEUE, self::NOTE);
        self::$checkouts[self::CHECKED_OUT_IN_PLACE] = $shop->checkout(self::CHECKED_OUT_IN_PLACE, self::NOTE, inPlace: true);
        self::$enteredDuringTheCheckout = $shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::CHECKED_OUT_IN_PLACE);
    }

    /**
     * Keep what the platform holds for each order and what the store of the shop shows, side by side.
     */
    protected static function look(Deployment $deployment, string $moment, string ...$orders): void
    {
        foreach ($orders as $order) {
            self::$seen[$moment][$order] = [
                'platform' => (array) self::heldNow($deployment, $order),
                'store' => $deployment->shop->runOf(self::workflowIdOf($order)),
            ];
        }

        if ($moment === self::SLEEPING || $moment === self::SHIPMENT_FAILED) {
            self::$stored = array_merge(self::$stored, $deployment->shop->stored());
        }
    }

    /**
     * @return array<string, mixed>|null what the platform holds for the run of an order; null while it has heard of none
     */
    protected static function heldNow(Deployment $deployment, string $order): ?array
    {
        return $deployment->platform->runsOf(self::workflowIdOf($order))[0] ?? null;
    }

    protected static function everyOrderWaitsFor(Deployment $deployment, string $waitType): bool
    {
        foreach (self::ORDERS as $order) {
            if ((self::heldNow($deployment, $order)['run']['wait']['type'] ?? null) !== $waitType) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every order is recorded as completed, and no segment waits for its announcement or its report.
     */
    protected static function everyOrderIsSettled(Deployment $deployment): bool
    {
        foreach (self::ORDERS as $order) {
            $held = self::heldNow($deployment, $order);
            $segments = $held['segments'] ?? [];

            $isHeardInFull = !in_array(null, array_merge(array_column($segments, 'announcedAt'), array_column($segments, 'reportedAt')), true);
            if (($held['state'] ?? null) !== 'completed' || !$isHeardInFull) {
                return false;
            }
        }

        return true;
    }

    // ----------------------------------------------------------------
    // What the tests read
    // ----------------------------------------------------------------

    protected static function deployment(): Deployment
    {
        return self::$deployment ?? self::fail('The flow was not played.');
    }

    protected static function workflowIdOf(string $order): string
    {
        return OrderWorkflow::workflowIdOf($order);
    }

    /**
     * @return array<string, mixed> what the platform held for an order at a moment of the flow
     */
    protected static function held(string $moment, string $order): array
    {
        return self::$seen[$moment][$order]['platform'];
    }

    /**
     * @return array<int, array<string, mixed>> the reports the platform received of the segments of one kind of an order, oldest first
     */
    protected static function reportsOf(string $order, Verb $verb): array
    {
        $reports = array_column(self::$wire->of(WireLog::REPORT, self::workflowIdOf($order)), 'fields');

        return array_values(array_filter($reports, static fn (array $report): bool => $report['verb'] === $verb->value));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function lastReportOf(string $order): array
    {
        $reports = array_column(self::$wire->of(WireLog::REPORT, self::workflowIdOf($order)), 'fields');

        return $reports[count($reports) - 1];
    }

    /**
     * @return array{fields: array<string, mixed>, headers: array<string, string>, status: int, answer: array<string, mixed>}
     */
    protected static function wakeOfTheTimer(string $order): array
    {
        return self::wakeOf($order, 2);
    }

    /**
     * @return array{fields: array<string, mixed>, headers: array<string, string>, status: int, answer: array<string, mixed>} the one wake the platform sent an order with an attempt
     */
    protected static function wakeOf(string $order, int $attempt): array
    {
        $wakes = array_values(array_filter(
            self::$wire->of(WireLog::WAKE, self::workflowIdOf($order)),
            static fn (array $wake): bool => $wake['fields']['attempt'] === $attempt,
        ));
        self::assertCount(1, $wakes, "The platform sent order {$order} one wake fenced with attempt {$attempt}.");

        return $wakes[0];
    }

    /**
     * @param Verb|null $about for a notice: the kind of segment it speaks for
     * @return array<int, array{list: string, message: Invocation}> the messages of one verb the queue carried for an order
     */
    protected static function carriedFor(string $order, Verb $verb, ?Verb $about = null): array
    {
        $carried = [];

        foreach (self::$carried as ['list' => $list, 'job' => $job]) {
            $message = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));
            $isAbout = !$about instanceof Verb || ($message->body['verb'] ?? null) === $about->value;

            if ($message->workflowId === self::workflowIdOf($order) && $message->verb === $verb && $isAbout) {
                $carried[] = ['list' => $list, 'message' => $message];
            }
        }

        return $carried;
    }

    /**
     * @return string[] the note of every start event and the reference of every payment the queue carried, read
     *                  back as a worker reads them
     */
    protected static function inputsCarriedByTheQueue(): array
    {
        $inputs = [];

        foreach (self::$carried as ['job' => $job]) {
            $message = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));
            $event = $message->event === null ? null : unserialize(base64_decode($message->event));

            $inputs[] = $event instanceof OrderPlaced ? $event->note : '';
            $inputs[] = (string) ($message->payload['reference'] ?? '');
        }

        return array_values(array_filter($inputs));
    }

    /**
     * @return array<string, string> where it was planted => what must never reach the platform, in clear and in
     *                               the form a queued message carries it
     */
    protected static function planted(): array
    {
        $planted = [
            'the state of a run' => Confidential::STATE,
            'the start event of an order the platform started' => Confidential::START_EVENT,
            'the start input of a checkout' => self::NOTE,
            'the payload of the payment signal' => self::PAYMENT_REFERENCE,
            'the message of the exception of a node' => Confidential::EXCEPTION,
        ];

        foreach (self::$carried as $number => ['job' => $job]) {
            $message = json_decode($job, true, flags: JSON_THROW_ON_ERROR);

            foreach (array_filter(['payload' => $message['payload'], 'event' => $message['event']]) as $field => $carried) {
                $planted["the {$field} that message {$number} of the queue carried"] = $carried;
            }
        }

        return $planted;
    }
}
