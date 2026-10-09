<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Stub\Flows\ShipOrderNode;
use NeuronCore\Cloud\Tests\Stub\Flows\ShippingFailed;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\Cloud\Tests\Support\Flows\Transcript;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function parse_url;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_URL_PATH;

/**
 * Example (a) of section 5 of the design, end to end against the fake
 * platform and the fake queue, with the platform's side played through the
 * endpoint: the platform starts the order, the payment webhook delivers its
 * event, and the platform wakes the timer and retries the segment that failed.
 */
class PlainPhpExampleTest extends EndpointTestCase
{
    protected Transcript $transcript;

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->transcript)) {
            unlink($this->transcript->path);
        }
    }

    #[DataProvider('backends')]
    public function test_an_order_the_platform_starts_runs_to_its_end_through_the_endpoint_and_the_workers(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);

        $statuses = $this->playTheOrder();

        self::assertSame([202, 202, 202], $statuses, 'The start, the wake of the timer and the wake that retries were queued.');
        self::assertSame(['start: suspended', 'signal: suspended', 'wake: failed', 'wake: completed'], $this->outcomesReported());
        self::assertSame(['placed:order:123', 'shipped:order:123'], $this->transcript->lines());
        self::assertNull(self::fieldsOf($this->deliver($this->inspect()))['run'], 'The run completed: nothing holds the workflow ID.');
    }

    #[DataProvider('backends')]
    public function test_the_platform_is_told_which_segments_it_ordered_itself(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);

        $this->playTheOrder();

        self::assertSame(['platform', 'application', 'platform', 'platform'], array_column($this->reports(), 'origin'));
        self::assertSame(['start', 'signal', 'wake', 'wake'], array_column($this->announcements(), 'verb'));
    }

    #[DataProvider('backends')]
    public function test_the_endpoint_runs_nothing_before_a_worker_takes_the_message(string $backend): void
    {
        $bench = $this->shop($backend);

        $this->deliver($this->start());
        $afterTheStart = $bench->executions->total();
        $this->work();
        $this->paymentWebhook();
        $this->work();
        $beforeTheWake = $bench->executions->total();
        $this->platformWakes();

        self::assertSame(0, $afterTheStart);
        self::assertSame($beforeTheWake, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_the_platform_reads_what_the_order_waits_for_between_its_segments(string $backend): void
    {
        $this->shop($backend);

        $this->deliver($this->start());
        $queued = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)));
        $this->work();
        $waiting = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)));
        $this->paymentWebhook();
        $this->work();
        $sleeping = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)));
        $this->platformWakes();
        $this->work();
        $ended = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)));

        self::assertSame([null, false], [$queued['run'], $queued['claimed']], 'No worker has claimed the start yet.');
        self::assertSame(['wait_for_event', OrderWorkflow::EVENT, 1], [$waiting['run']['wait']['type'], $waiting['run']['wait']['eventName'], $waiting['run']['attempt']]);
        self::assertSame(['sleep_until', 0, 2], [$sleeping['run']['wait']['type'], $sleeping['run']['wait']['wakeAt'], $sleeping['run']['attempt']]);
        self::assertSame([null, true], [$ended['run'], $ended['claimed']], 'The ledger outlives the run.');
    }

    #[DataProvider('backends')]
    public function test_a_start_the_platform_sends_twice_runs_the_order_once(string $backend): void
    {
        $bench = $this->shop($backend);

        $this->deliver($this->start());
        $this->deliver($this->start());
        [$first, $second] = $this->work();

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame(Outcome::Suspended, $first->outcome);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$second->outcome, $second->code]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_start_replayed_after_the_order_ended_does_not_run_it_again(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->playTheOrder();
        $executed = $bench->executions->total();

        $answer = $this->deliver($this->start());
        [$late] = $this->work();

        self::assertSame(202, $answer->statusCode);
        self::assertInstanceOf(Report::class, $late);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$late->outcome, $late->code]);
        self::assertSame($executed, $bench->executions->total());
        self::assertSame(['placed:order:123', 'shipped:order:123'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_the_timer_the_platform_sends_twice_continues_the_order_once(string $backend): void
    {
        $bench = $this->shop($backend, shippingFailures: 1);
        $this->orderSleepingUntilItShips();
        $executed = $bench->executions->count(ShipOrderNode::class);

        $this->platformWakes();
        $this->platformWakes();
        [$first, $second] = $this->work();

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame([Outcome::Failed, ShippingFailed::class], [$first->outcome, $first->errorClass]);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value], [$second->outcome, $second->code]);
        self::assertNull($second->executedAttempt);
        self::assertSame($executed + 1, $bench->executions->count(ShipOrderNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_retry_the_platform_sends_twice_finishes_the_order_once(string $backend): void
    {
        $this->shop($backend, shippingFailures: 1);
        $this->orderSleepingUntilItShips();
        $this->platformWakes();
        $this->work();

        $this->platformWakes();
        $this->platformWakes();
        [$first, $second] = $this->work();

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame(Outcome::Completed, $first->outcome);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$second->outcome, $second->code]);
        self::assertSame(['placed:order:123', 'shipped:order:123'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_wake_the_queue_delivers_twice_continues_the_order_once(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderSleepingUntilItShips();
        $this->platformWakes();

        [$first, $second] = $bench->dispatcher->runAll($bench->cloud, 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame(Outcome::Completed, $first->outcome);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$second->outcome, $second->code]);
        self::assertSame(['placed:order:123', 'shipped:order:123'], $this->transcript->lines());
    }

    /**
     * bootstrap.php: "order-fulfilment" is registered on the store and the queue of the bench.
     *
     * @param int $shippingFailures how many times the node after the timer throws before the order ships
     */
    protected function shop(string $backend, int $shippingFailures = 0): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $transcript = $this->transcript = new Transcript((string) tempnam(sys_get_temp_dir(), 'neuron-cloud-'));

        $bench->cloud->register(
            self::DEFINITION,
            static fn (): Workflow => new OrderWorkflow($executions, $transcript, shippingFailures: $shippingFailures),
        );

        return $bench;
    }

    /**
     * The whole order: the platform starts it, the payment arrives, the platform wakes the timer and, when
     * the node after it failed, wakes the run again.
     *
     * @return int[] the status the endpoint answered each order of the platform with
     */
    protected function playTheOrder(): array
    {
        $statuses = [$this->deliver($this->start())->statusCode];
        $this->work();
        $this->paymentWebhook();
        $this->work();

        do {
            $statuses[] = $this->platformWakes()->statusCode;
            [$report] = $this->work();
        } while ($report instanceof Report && $report->outcome === Outcome::Failed);

        return $statuses;
    }

    /**
     * The order was started by the platform and paid: it waits for its timer, which is due.
     */
    protected function orderSleepingUntilItShips(): void
    {
        $this->deliver($this->start());
        $this->work();
        $this->paymentWebhook();
        $this->work();
    }

    /**
     * The payment webhook of the example: the application delivers the event itself.
     */
    protected function paymentWebhook(): void
    {
        $this->bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->signal(OrderWorkflow::EVENT, ['amount' => 49.9])->dispatch();
    }

    /**
     * What the platform does when a timer comes due or a run has failed.
     */
    protected function platformWakes(): HttpResponse
    {
        return $this->bench->platform->wake($this->bench->cloud, self::WORKFLOW_ID);
    }

    /**
     * @return string[] "verb: outcome" for every report the platform was sent, in order
     */
    protected function outcomesReported(): array
    {
        return array_map(static fn (array $report): string => "{$report['verb']}: {$report['outcome']}", $this->reports());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function reports(): array
    {
        return $this->sentTo('/v1/reports');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function announcements(): array
    {
        return $this->sentTo('/v1/announcements');
    }

    /**
     * @return array<int, array<string, mixed>> the bodies the platform received at a path, in order
     */
    protected function sentTo(string $path): array
    {
        $requests = array_filter(
            $this->bench->platform->received(),
            static fn (HttpRequest $request): bool => parse_url($request->uri, PHP_URL_PATH) === $path && is_array($request->body),
        );

        return array_values(array_map(static fn (HttpRequest $request): array => (array) $request->body, $requests));
    }
}
