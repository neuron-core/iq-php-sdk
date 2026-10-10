<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd;

use NeuronCore\Cloud\Tests\Support\EndToEnd\Deployment;
use NeuronCore\Cloud\Tests\Support\EndToEnd\EndToEndTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Wait;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\OrderWorkflow;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;

use function count;
use function substr_count;

/**
 * bin/worker.php of example (a) of section 5 of the design, as a process on
 * the durable queue of the shop: what its loop does with what invoke()
 * throws and with the report invoke() returns, as section 9.5 says a worker
 * you write yourself must.
 */
class WorkerLoopTest extends EndToEndTestCase
{
    protected const ORDER = 'W3001';

    protected const LATER = SpoolDispatcher::NOTICES . ':later';

    protected const FAILED = SpoolDispatcher::NOTICES . ':failed';

    protected Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployment = $this->forThisTest(new Deployment());
        self::assertSame(0, $this->deployment->shop->sync()['exitCode']);
    }

    public function test_a_notice_the_platform_did_not_take_is_put_on_the_later_list(): void
    {
        $this->theCheckoutLeavesItsTwoNoticesOnTheQueue();
        $this->deployment->platform->stop();

        $this->deployment->shop->startWorker(SpoolDispatcher::NOTICES);

        $this->awaitOnTheList(self::LATER, 2);
        self::assertSame([], $this->deployment->shop->queue()->waiting(self::FAILED));
    }

    public function test_the_cron_moves_the_later_list_back_and_the_platform_catches_up_once_it_is_up_again(): void
    {
        $this->theCheckoutLeavesItsTwoNoticesOnTheQueue();
        $this->deployment->platform->stop();
        $this->deployment->shop->startWorker(SpoolDispatcher::NOTICES);
        $this->awaitOnTheList(self::LATER, 2);

        $this->deployment->platform->start();
        $this->deployment->shop->retryLater();

        $held = $this->deployment->platform->await('the platform holds the run of the checkout', $this->heldByThePlatform(...));
        self::assertSame(['application', 'suspended'], [$held['origin'], $held['run']['status'] ?? null]);
        self::assertSame($this->deployment->shop->runOf(self::workflowId()), $held['run']);
    }

    public function test_a_notice_the_platform_refuses_for_good_is_kept_aside_on_the_failed_list(): void
    {
        $this->theCheckoutLeavesItsTwoNoticesOnTheQueue();

        $this->deployment->shop->startWorker(SpoolDispatcher::NOTICES, ['NEURON_CLOUD_API_KEY' => 'a-key-that-was-revoked']);

        $this->awaitOnTheList(self::FAILED, 2);
        self::assertSame([], $this->deployment->shop->queue()->waiting(self::LATER));
        self::assertSame([], $this->deployment->platform->runsOf(self::workflowId()), 'The platform recorded nothing.');
    }

    public function test_the_worker_logs_a_start_of_the_shop_that_did_not_run(): void
    {
        $this->deployment->shop->checkout(self::ORDER, 'no note');
        [$start] = $this->deployment->shop->queue()->waiting(SpoolDispatcher::SEGMENTS);
        // The queue delivers the start a second time.
        $this->deployment->shop->queue()->push(SpoolDispatcher::SEGMENTS, $start);

        $worker = $this->deployment->shop->startWorker();
        Wait::until('the worker dealt with both copies', fn (): bool => $this->deployment->shop->queue()->waiting(SpoolDispatcher::SEGMENTS) === []
            && substr_count($worker->log(), 'start of ' . self::workflowId()) === 2);

        self::assertStringContainsString('): suspended', $worker->log());
        self::assertStringContainsString('): rejected already_claimed. THIS START OF THE SHOP DID NOT RUN.', $worker->log());
        self::assertSame(1, $this->deployment->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
    }

    /**
     * A checkout run in the request hands the queue an announcement and an observe, and no worker runs yet.
     */
    protected function theCheckoutLeavesItsTwoNoticesOnTheQueue(): void
    {
        self::assertSame(200, $this->deployment->shop->checkout(self::ORDER, 'no note', inPlace: true)['status']);
        self::assertCount(2, $this->deployment->shop->queue()->waiting(SpoolDispatcher::NOTICES));
    }

    protected function awaitOnTheList(string $list, int $messages): void
    {
        Wait::until(
            "{$messages} messages wait on the list '{$list}'",
            fn (): bool => count($this->deployment->shop->queue()->waiting($list)) === $messages
                && $this->deployment->shop->queue()->waiting(SpoolDispatcher::NOTICES) === [],
        );
    }

    /**
     * @return array<string, mixed>|false what the platform holds for the run of the order, once a snapshot named it
     */
    protected function heldByThePlatform(): array|false
    {
        $held = $this->deployment->platform->runsOf(self::workflowId())[0] ?? null;

        return ($held['run'] ?? null) === null ? false : $held;
    }

    protected static function workflowId(): string
    {
        return OrderWorkflow::workflowIdOf(self::ORDER);
    }
}
