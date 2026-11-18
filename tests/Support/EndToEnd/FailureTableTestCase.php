<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use Closure;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Confidential;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\OrderWorkflow;
use NeuronCore\IQ\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Shop;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\AssertionFailedError;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * A test of one row of the table "When things go wrong" of section 7 of the
 * design, or of another failure the design names, over real HTTP: the shop
 * of example (a) with its web server and its workers as processes, and the
 * platform simulator reacting by its own rules. The test makes the failure
 * happen, fires the trigger and looks at both sides: the store and the queue
 * of the shop, and the record, the wire and the alerts of the platform.
 */
abstract class FailureTableTestCase extends EndToEndTestCase
{
    /** What the bootstrap of the shop reads: an order ships one second after its payment. */
    protected const SHOP = ['SHOP_SHIPS_AFTER' => '1'];

    /** The durations of the platform, in seconds: what a real one waits minutes for takes a moment here. */
    protected const PLATFORM = [
        'retryDelay' => 0.2,
        'resendDelay' => 0.2,
        'earlyTimerRetry' => 0.3,
        'unreadFollowUp' => 0.3,
        'pingRetry' => 0.2,
    ];

    /** Seconds of the lease of a worker that a test kills or outlives: a sync refuses less than 60, a worker takes it. */
    protected const SHORT_LEASE = ['SHOP_LEASE' => '2'];

    /** Seconds a test puts the clock of the platform ahead of the shop's. */
    protected const CLOCK_AHEAD = 1;

    /** An order that sleeps long after its payment: a test looks at it with no timer about to fire. */
    protected const SHIPS_MUCH_LATER = ['SHOP_SHIPS_AFTER' => '60'];

    /** What the customer wrote at the checkout: the start input of an order the shop starts. */
    protected const NOTE = 'CONFIDENTIAL-5c1d77-written-by-the-customer-at-the-checkout';

    /** What the payment provider sends with a payment: the payload of the signal. */
    protected const PAYMENT_REFERENCE = 'CONFIDENTIAL-a83e02-reference-of-the-payment';

    protected SimulatedPlatform $platform;

    protected DeployedShop $shop;

    /**
     * The shop is deployed and its sync published; no worker runs yet.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $deployment = $this->forThisTest(new Deployment(static::SHOP));
        $this->platform = $deployment->platform;
        $this->shop = $deployment->shop;

        $this->platform->configure(static::PLATFORM);
        self::assertSame(0, $this->shop->sync()['exitCode'], 'The sync of the deploy failed.');
    }

    /**
     * Whatever went wrong, every message that crossed the wire kept to the protocol, every message of the
     * platform was signed, and nothing an order carries left the shop.
     */
    protected function assertPostConditions(): void
    {
        $wire = $this->platform->wire();

        $wire->assertProtocolRespected();
        $wire->assertSignedWith($this->platform->signingKey);
        $this->assertNothingPlantedReachedThePlatform();
    }

    /**
     * The boundary of section 1 of the design: neither the state, the inputs, the payloads nor the message of an
     * exception, in clear or as the queue of the shop carried them.
     */
    protected function assertNothingPlantedReachedThePlatform(): void
    {
        $planted = [Confidential::STATE, Confidential::START_EVENT, Confidential::EXCEPTION, self::NOTE, self::PAYMENT_REFERENCE];
        foreach ($this->shop->queue()->carried() as ['job' => $job]) {
            $message = json_decode($job, true, flags: JSON_THROW_ON_ERROR);
            $planted = [...$planted, ...array_values(array_filter([$message['payload'] ?? null, $message['event'] ?? null]))];
        }

        $reachedThePlatform = $this->platform->memory() . $this->platform->wireTap() . $this->platform->log();
        foreach ($planted as $marker) {
            self::assertStringNotContainsString($marker, $reachedThePlatform, 'Something an order carries reached the platform.');
        }
    }

    /**
     * The endpoint of the shop deals with the next message of the platform and answers too late: the platform,
     * which gives it less time than that, cannot know that the message was queued, and sends it again once the
     * endpoint is free.
     */
    protected function theEndpointAnswersItsNextMessageTooLate(): void
    {
        $this->platform->configure(['endpointTimeout' => 0.4, 'resendDelay' => 0.9]);
        $this->shop->faults->plan('endpoint.answer', Faults::SLEEP . ' 0.8');
    }

    /**
     * From here on the clock of the platform runs ahead of the shop's, as two clocks do. The platform then asks
     * about a silent segment before the lease that the store holds has run out, and sees the run under a lease
     * that has not passed: rule 11 of appendix B has it wait for the deadline all the same.
     */
    protected function theClockOfThePlatformRunsAhead(): void
    {
        $this->platform->configure(['clockAhead' => self::CLOCK_AHEAD]);
    }

    /**
     * @param int $leaseExpiresAt the deadline of the lease of a run, as the store of the shop held it
     * @param array{headers: array<string, string>} $wake a wake of that run, sent while the clock of the platform ran ahead
     */
    protected static function assertNotSentBeforeTheLeaseHadPassed(int $leaseExpiresAt, array $wake): void
    {
        self::assertGreaterThanOrEqual(
            $leaseExpiresAt,
            (int) $wake['headers']['webhook-timestamp'] - self::CLOCK_AHEAD,
            'The platform woke a running run before its lease had passed by the clock of the shop.',
        );
    }

    /**
     * One worker on the segments and one on the notices.
     *
     * @param array<string, string> $environment what these workers set differently
     * @return Process the worker of the segments
     */
    protected function startWorkers(array $environment = []): Process
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES, $environment);

        return $this->shop->startWorker(SpoolDispatcher::SEGMENTS, $environment);
    }

    /**
     * A trigger of the platform fires for an order. The start leaves with the next tick.
     *
     * @return string the run ID the platform minted
     */
    protected function fire(string $order): string
    {
        return $this->platform->fire(Shop::DEFINITION, self::workflowIdOf($order))['runId'];
    }

    /**
     * Wait for something, in real time; the clock of the platform ticks before every look.
     *
     * @template TSeen
     * @param Closure(): (TSeen|null|false) $seen
     * @return TSeen
     * @throws AssertionFailedError when it did not happen in time
     */
    protected function await(string $what, Closure $seen, float $seconds = 15.0): mixed
    {
        return $this->platform->await($what, $seen, $seconds);
    }

    /**
     * @param string $type "wait_for_event" while the order waits for its payment, "sleep_until" while it waits to ship
     * @return array<string, mixed> what the platform holds for the run, once it holds that wait
     */
    protected function awaitWait(string $runId, string $type): array
    {
        return $this->await(
            "the platform holds the run at a wait of type {$type}",
            function () use ($runId, $type): array|false {
                $held = $this->platform->find($runId);

                return ($held['run']['wait']['type'] ?? null) === $type ? $held : false;
            },
        );
    }

    /**
     * @param string $state how the platform closed the run: "completed", "ended_unknown", "lost", "spent" or "abandoned"
     * @return array<string, mixed> what the platform holds for the run, once it is closed that way
     */
    protected function awaitClosed(string $runId, string $state): array
    {
        return $this->await(
            "the platform closed the run as {$state}",
            function () use ($runId, $state): array|false {
                $held = $this->platform->find($runId);

                return ($held['state'] ?? null) === $state ? $held : false;
            },
        );
    }

    /**
     * @return array<string, mixed> the first report of an order with an outcome, once the platform received it
     */
    protected function awaitReport(string $order, string $outcome): array
    {
        return $this->await(
            "the platform received a report of order {$order} that says {$outcome}",
            fn (): array|false => $this->reports($order, $outcome)[0] ?? false,
        );
    }

    /**
     * The platform starts the order and a worker runs it to the wait for its payment.
     *
     * @return string the run ID
     */
    protected function theOrderWaitsForItsPayment(string $order): string
    {
        $runId = $this->fire($order);
        $this->awaitWait($runId, 'wait_for_event');

        return $runId;
    }

    /**
     * The payment provider calls the webhook, and a worker runs the order to the wait for its shipment. The
     * clock of the platform does not tick meanwhile: the wake of the timer has not left when this returns,
     * however soon it is due, and leaves with the next thing the test waits for.
     */
    protected function theOrderIsPaid(string $order, string $runId): void
    {
        self::assertSame(202, $this->shop->pay($order, 49.9, self::PAYMENT_REFERENCE), 'The payment webhook did not take the payment.');

        Wait::until(
            'the platform holds the run at the wait for its shipment',
            fn (): bool => ($this->platform->find($runId)['run']['wait']['type'] ?? null) === 'sleep_until',
        );
    }

    /**
     * @return array<int, array<string, mixed>> the fields of every report the platform received for an order, oldest first
     */
    protected function reports(string $order, ?string $outcome = null): array
    {
        $reports = array_column($this->platform->wire()->of(WireLog::REPORT, self::workflowIdOf($order)), 'fields');

        return array_values(array_filter(
            $reports,
            static fn (array $report): bool => $outcome === null || $report['outcome'] === $outcome,
        ));
    }

    /**
     * @param string $message one of the constants of WireLog
     * @return array<int, array{fields: array<string, mixed>, headers: array<string, string>, status: int, answer: array<string, mixed>, body: string}>
     *         the messages of one kind about an order that crossed the wire, oldest first
     */
    protected function crossed(string $message, string $order): array
    {
        return $this->platform->wire()->of($message, self::workflowIdOf($order));
    }

    /**
     * A request of the platform reaches the endpoint once more, byte for byte and with the headers it had:
     * what a network that duplicates sends, or whoever recorded the request.
     *
     * @param array{headers: array<string, string>, body: string} $sent a message of the platform, as it crossed the wire
     * @return int the status the endpoint answered
     */
    protected function deliverAgain(array $sent): int
    {
        return $this->deliver($sent['headers'], $sent['body']);
    }

    /**
     * A request reaches the endpoint of the shop from somewhere else than the platform.
     *
     * @param array<string, string> $headers
     * @return int the status the endpoint answered
     */
    protected function deliver(array $headers, string $body): int
    {
        return Http::send(
            'POST',
            "{$this->shop->url()}/iq.php",
            $body,
            array_map(static fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers),
        )['status'];
    }

    /**
     * @return string[] the messages of one verb that wait on the list of the segments, as the queue holds them
     */
    protected function waitingSegments(Verb $verb): array
    {
        return array_values(array_filter(
            $this->shop->queue()->waiting(SpoolDispatcher::SEGMENTS),
            static fn (string $job): bool => json_decode($job, true, flags: JSON_THROW_ON_ERROR)['verb'] === $verb->value,
        ));
    }

    /**
     * The queue delivers a second time the one message of a verb that waits on the list of the segments.
     */
    protected function theQueueDeliversTwice(Verb $verb): void
    {
        [$job] = Wait::until("a {$verb->value} waits in the queue", fn (): array|false => $this->waitingSegments($verb) ?: false);

        $this->shop->queue()->push(SpoolDispatcher::SEGMENTS, $job);
    }

    /**
     * @return Invocation[] the messages of one verb the queue of the shop ever carried for an order, oldest first
     */
    protected function carried(string $order, Verb $verb): array
    {
        $carried = [];

        foreach ($this->shop->queue()->carried() as ['job' => $job]) {
            $message = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

            if ($message->workflowId === self::workflowIdOf($order) && $message->verb === $verb) {
                $carried[] = $message;
            }
        }

        return $carried;
    }

    /**
     * @return array<string, mixed>|null the truth: the run of an order in the store of the shop
     */
    protected function stored(string $order): ?array
    {
        return $this->shop->runOf(self::workflowIdOf($order));
    }

    /**
     * What the third column of the table promises above all, counted across every process of the shop.
     *
     * @param int $awaitPayment how many times the body of the first node ran to its end
     * @param int $ship how many times the body of the second node ran to its end
     */
    protected function assertNodeBodiesFinished(string $order, int $awaitPayment, int $ship): void
    {
        self::assertSame(
            ['await payment' => $awaitPayment, 'ship' => $ship],
            [
                'await payment' => $this->shop->nodeRuns->timesFinished(AwaitPaymentNode::class, $order),
                'ship' => $this->shop->nodeRuns->timesFinished(ShipOrderNode::class, $order),
            ],
            "How many times each node body of order {$order} ran to its end.",
        );
    }

    /**
     * The platform holds for the run exactly what the store of the shop shows.
     */
    protected function assertThePlatformHoldsTheStoredRun(string $order, string $runId): void
    {
        $held = $this->platform->run($runId);

        self::assertSame('open', $held['state']);
        self::assertSame($this->stored($order), $held['run'], 'The platform and the store of the shop disagree.');
    }

    protected static function workflowIdOf(string $order): string
    {
        return OrderWorkflow::workflowIdOf($order);
    }
}
