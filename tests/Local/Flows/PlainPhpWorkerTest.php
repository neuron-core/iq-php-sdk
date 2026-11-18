<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Flows;

use Closure;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\Flows\RedisDispatcher;
use NeuronCore\IQ\Tests\Stub\Flows\ShipOrderNode;
use NeuronCore\IQ\Tests\Stub\Local\PickyQueue;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\IQ\Tests\Support\Flows\PlainPhpTestCase;
use NeuronCore\IQ\Tests\Support\Flows\PlainPhpWorker;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * The worker of example (a) of section 5 of the design, given every reason
 * invoke() throws an IQException with (section 9.5): what it keeps for
 * later runs when the cron puts it back, and what cannot run is set aside.
 */
class PlainPhpWorkerTest extends PlainPhpTestCase
{
    protected const LEDGER_PARTITION_PREFIX = '__iq:';

    /** While true, the factory of the current version throws, as when it cannot reach what it builds the workflow from. */
    protected bool $factoryFails = false;

    #[DataProvider('backends')]
    public function test_the_notices_the_platform_did_not_record_are_kept_for_later(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutInTheRequest();
        $bench->platform->failNext(2);

        $this->work();

        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::FAILED));
    }

    #[DataProvider('backends')]
    public function test_the_notices_kept_for_later_reach_the_platform_when_the_cron_puts_them_back(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutInTheRequest();
        $bench->platform->failNext(2);
        $this->work();

        $this->worker->retryLater();
        $this->work();

        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Suspended);
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
    }

    #[DataProvider('finalAnswersOnEveryBackend')]
    public function test_the_notices_the_platform_refuses_for_good_are_set_aside(string $backend, int $status, ?string $code): void
    {
        $this->shop($backend);
        $this->checkoutInTheRequest();

        $this->worker->work($this->workerIQ(platform: $this->platformThatAnswers($status, $code)));

        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
    }

    #[DataProvider('passingAnswersOnEveryBackend')]
    public function test_the_notices_the_platform_answers_any_other_way_are_kept_for_later(string $backend, int $status, ?string $code): void
    {
        $this->shop($backend);
        $this->checkoutInTheRequest();

        $this->worker->work($this->workerIQ(platform: $this->platformThatAnswers($status, $code)));

        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::FAILED));
    }

    #[DataProvider('abortsOnEveryBackend')]
    public function test_a_dispatched_start_aborted_before_its_claim_is_kept_for_later(string $backend, Code $abort): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();
        $queued = $this->redis->lRange(PlainPhpWorker::QUEUE);

        $this->worker->work($this->workerThatAborts($abort));

        self::assertSame($queued, $this->redis->lRange(PlainPhpWorker::LATER), 'The start waits as it was queued.');
        self::assertSame([], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('abortsOnEveryBackend')]
    public function test_the_platform_hears_why_a_dispatched_start_was_aborted(string $backend, Code $abort): void
    {
        $this->shop($backend);
        $this->checkoutOnTheQueue();

        $this->worker->work($this->workerThatAborts($abort));

        [$report] = $this->reportsOf(self::WORKFLOW_ID);
        self::assertSame(['aborted', $abort->value, null], [$report['outcome'], $report['code'], $report['claimed']]);
    }

    #[DataProvider('abortsOnEveryBackend')]
    public function test_a_dispatched_start_kept_for_later_runs_once_when_the_cron_puts_it_back(string $backend, Code $abort): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();
        $this->worker->work($this->workerThatAborts($abort));

        $this->factoryFails = false;
        $this->worker->retryLater();
        $this->work();

        self::assertSame(WorkflowStatus::Suspended, $this->holder(self::WORKFLOW_ID)?->status);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        self::assertSame([], $this->redis->lRange(PlainPhpWorker::LATER));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_aborted_past_its_use_by_time_is_set_aside(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();
        $this->makeTheQueuedMessageExpire();

        $this->worker->work($this->workerThatAborts(Code::UnknownVersion));

        self::assertSame([Verb::Start], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_signal_aborted_by_a_worker_that_lacks_its_version_is_kept_for_later_then_delivered(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForItsPayment();
        $this->paymentWebhook();

        $this->worker->work($this->workerThatAborts(Code::UnknownVersion));
        $kept = $this->verbsIn(PlainPhpWorker::LATER);
        $this->worker->retryLater();
        $this->work();

        self::assertSame([Verb::Signal], $kept);
        self::assertSame(1, $bench->executions->count(ShipOrderNode::class));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_signal_aborted_a_day_after_it_was_queued_is_set_aside(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForItsPayment();
        $this->paymentWebhook();
        $this->makeTheQueuedMessageExpire();

        $this->worker->work($this->workerThatAborts(Code::UnknownVersion));

        self::assertSame([Verb::Signal], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame(0, $bench->executions->count(ShipOrderNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_signal_that_carries_no_attempt_is_set_aside_and_never_executed(string $backend): void
    {
        $bench = $this->shop($backend);
        $run = $this->orderWaitingForItsPayment();
        $unfenced = new Invocation('inv_unfenced', Verb::Signal, Origin::APPLICATION, self::DEFINITION, self::WORKFLOW_ID, $run, signal: 'payment.received', payload: []);
        $this->redis->rPush(PlainPhpWorker::QUEUE, json_encode($unfenced->toArray(), JSON_THROW_ON_ERROR));

        $this->work();

        self::assertSame([Verb::Signal], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame(0, $bench->executions->count(ShipOrderNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_message_given_to_a_worker_whose_bootstrap_sets_no_persistence_is_kept_for_later(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();

        $this->worker->work(new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform));

        self::assertSame([Verb::Start], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame([], $bench->platform->received(), 'Nothing was done and nothing was reported.');
    }

    #[DataProvider('backends')]
    public function test_a_start_aborted_after_its_claim_is_not_kept_because_it_can_never_run_again(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->checkoutOnTheQueue();

        $this->worker->work($this->workerIQ(persistence: $this->storeThatFailsAt(
            static fn (string $operation, string $partition): bool => $operation === 'initializeIfAbsent' && $partition === self::WORKFLOW_ID,
        )));

        [$report] = $this->reportsOf(self::WORKFLOW_ID);
        self::assertSame(['aborted', Code::NotExecuted->value, true], [$report['outcome'], $report['code'], $report['claimed']]);
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::FAILED));
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_the_platform_that_is_aborted_is_left_to_the_platform_to_send_again(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForItsPayment();
        $this->platformWakes(self::WORKFLOW_ID);

        $this->worker->work($this->workerThatAborts(Code::UnknownVersion));

        self::assertSame('wake: aborted', $this->outcomesReported(self::WORKFLOW_ID)[1]);
        self::assertSame([], $this->verbsIn(PlainPhpWorker::LATER));
        self::assertSame([], $this->verbsIn(PlainPhpWorker::FAILED));
    }

    /**
     * @return iterable<string, array{string, Code}> a backend, and the code a start is aborted with before its claim
     */
    public static function abortsOnEveryBackend(): iterable
    {
        $aborts = [Code::UnknownDefinition, Code::UnknownVersion, Code::NotExecuted, Code::AnnounceFailed, Code::Unavailable];

        foreach (self::backends() as $store => [$backend]) {
            foreach ($aborts as $abort) {
                yield "{$abort->value}, on {$store}" => [$backend, $abort];
            }
        }
    }

    /**
     * @return iterable<string, array{string, int, string|null}> a backend, and the status and the code of an answer
     *                                                            of the platform that is final
     */
    public static function finalAnswersOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "401 unauthorized, on {$store}" => [$backend, 401, Code::Unauthorized->value];
            yield "403 with no code, on {$store}" => [$backend, 403, null];
            yield "400 invalid_request, on {$store}" => [$backend, 400, Code::InvalidRequest->value];
            yield "426 unsupported_protocol, on {$store}" => [$backend, 426, Code::UnsupportedProtocol->value];
        }
    }

    /**
     * @return iterable<string, array{string, int, string|null}> a backend, and the status and the code of an answer
     *                                                            of the platform that only means "not recorded"
     */
    public static function passingAnswersOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "503, on {$store}" => [$backend, 503, null];
            yield "429, on {$store}" => [$backend, 429, null];
            yield "400 with no code, on {$store}" => [$backend, 400, null];
            yield "500 that says invalid_request, on {$store}" => [$backend, 500, Code::InvalidRequest->value];
        }
    }

    /**
     * The deploy that added version 2 of "order-fulfilment" is live: requests mint their run IDs on it.
     */
    protected function shop(string $backend, int $shippingFailures = 0): TestBench
    {
        $bench = parent::shop($backend, $shippingFailures);

        $bench->iq->register(
            self::DEFINITION,
            fn (): Workflow => $this->factoryFails ? throw new FactoryFailed('The factory reached a service that is down.') : $this->orderWorkflow(),
            version: 2,
        );

        return $bench;
    }

    /**
     * The IQ of another worker of the shop, with what the test changes in its bootstrap.
     *
     * @param (Closure(): PersistenceInterface)|null $persistence
     * @param int[] $versions the versions of "order-fulfilment" its code holds
     */
    protected function workerIQ(
        ?HttpClientInterface $platform = null,
        ?DispatcherInterface $queue = null,
        ?Closure $persistence = null,
        array $versions = [1, 2],
    ): IQ {
        $iq = (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $platform ?? $this->bench->platform))
            ->setPersistence($persistence ?? $this->bench->persistence)
            ->setDispatcher($queue ?? new RedisDispatcher($this->redis));

        foreach ($versions as $version) {
            $iq->register(self::DEFINITION, $this->orderWorkflow(...), $version);
        }

        return $iq;
    }

    /**
     * A worker that aborts the start of the application it is given, before the claim.
     */
    protected function workerThatAborts(Code $abort): IQ
    {
        return match ($abort) {
            Code::UnknownDefinition => $this->workerIQ(versions: []),
            Code::UnknownVersion => $this->workerIQ(versions: [1]),
            Code::AnnounceFailed => $this->workerIQ(
                platform: $this->bench->platform->failNext(),
                queue: new PickyQueue(new RedisDispatcher($this->redis), Verb::Announce),
            ),
            Code::Unavailable => $this->workerIQ(persistence: $this->storeThatFailsAt(
                static fn (string $operation, string $partition): bool => str_starts_with($partition, self::LEDGER_PARTITION_PREFIX),
            )),
            default => $this->workerWhoseFactoryThrows(),
        };
    }

    protected function workerWhoseFactoryThrows(): IQ
    {
        $this->factoryFails = true;

        return $this->bench->iq;
    }

    /**
     * @param Closure(string $operation, string $partition): bool $fails
     * @return Closure(): PersistenceInterface
     */
    protected function storeThatFailsAt(Closure $fails): Closure
    {
        $persistence = $this->bench->persistence;

        return static fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            static function (string $operation, string $partition) use ($fails): void {
                if ($fails($operation, $partition)) {
                    throw new StoreUnavailable("The store failed on {$operation}.");
                }
            },
        );
    }

    protected function platformThatAnswers(int $status, ?string $code): HttpClientInterface
    {
        return new ScriptedHttpClient(new HttpResponse($status, json_encode(['code' => $code], JSON_THROW_ON_ERROR)));
    }

    /**
     * The checkout dispatched the start and a worker ran it: the order waits for "payment.received".
     *
     * @return string the run ID
     */
    protected function orderWaitingForItsPayment(): string
    {
        $runId = $this->checkoutOnTheQueue();
        $this->work();

        return $runId;
    }

    /**
     * Neuron and the SDK read time(), so a message is made old by the use-by time it carries.
     */
    protected function makeTheQueuedMessageExpire(): void
    {
        $message = json_decode((string) $this->redis->lPop(PlainPhpWorker::QUEUE), true, flags: JSON_THROW_ON_ERROR);

        $this->redis->rPush(PlainPhpWorker::QUEUE, json_encode(['expiresAt' => time() - 1] + $message, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Verb[] the kind of every message a list of the queue holds, in order
     */
    protected function verbsIn(string $list): array
    {
        return array_map(static fn (Invocation $message): Verb => $message->verb, $this->waitingIn($list));
    }
}
