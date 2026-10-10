<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\ShipOrderNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\Cloud\Verb;

use function array_column;
use function base64_decode;
use function base64_encode;
use function count;
use function hash_hmac;
use function random_bytes;
use function str_replace;
use function strlen;
use function substr;
use function time;

/**
 * Row 17 of section 7 of the design: a wrong signing key, an altered body, a
 * replayed request. The first two are answered 401; a replayed start is a
 * duplicate (row 1) and a replayed wake is stale (row 4): nothing unverified
 * runs.
 */
class Row17UnverifiedRequestTest extends FailureTableTestCase
{
    protected const ORDER = 'R1701';

    protected const ORDER_OF_THE_ATTACKER = 'R1702';

    /** Seconds either way: the window in which the endpoint takes a signature. */
    protected const SIGNATURE_WINDOW = 300;

    public function test_an_endpoint_that_holds_another_signing_key_answers_401_and_the_platform_stops_until_a_ping_succeeds(): void
    {
        $this->serveWith(['NEURON_CLOUD_SIGNING_KEY' => self::aSigningKey()]);
        $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $this->await('the platform stopped its deliveries', fn (): bool => $this->platform->manifest()['deliveriesStopped']);
        $deployWithTheWrongKey = $this->shop->sync();

        self::assertSame([401, 'invalid_signature'], [$this->crossed(WireLog::START, self::ORDER)[0]['status'], $this->crossed(WireLog::START, self::ORDER)[0]['answer']['code']]);
        self::assertSame([], $this->platform->tick(), 'Nothing is sent while the endpoint does not verify the signature.');
        self::assertSame(['invalid_signature'], array_column($this->platform->alerts(), 'kind'));
        self::assertSame([], $this->shop->queue()->carried(), 'Nothing unverified was queued.');
        self::assertSame(1, $deployWithTheWrongKey['exitCode'], 'A sync fails the deploy: the ping was answered 401.');
        self::assertStringContainsString('endpoint_unverified', $deployWithTheWrongKey['printed']);

        $this->serveWith([]);
        $this->awaitWait($runId, 'wait_for_event');

        $starts = $this->crossed(WireLog::START, self::ORDER);
        self::assertSame([401, 202], array_column($starts, 'status'), 'The platform kept the start and sent it when a ping had succeeded.');
        self::assertSame($starts[0]['fields'], $starts[1]['fields']);
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_request_whose_body_was_altered_or_whose_signature_was_made_with_another_key_is_answered_401_and_nothing_is_queued(): void
    {
        $this->fire(self::ORDER);
        $this->platform->tick();
        [$genuine] = $this->crossed(WireLog::START, self::ORDER);
        // Whoever sits on the wire points the start at an order of its own choosing.
        $altered = str_replace(self::workflowIdOf(self::ORDER), self::workflowIdOf(self::ORDER_OF_THE_ATTACKER), $genuine['body']);
        $forged = self::signed($altered, $genuine['headers']['webhook-id'], time(), self::aSigningKey());

        self::assertSame(202, $genuine['status']);
        self::assertSame([401, 401], [$this->deliver($genuine['headers'], $altered), $this->deliver($forged, $altered)]);
        self::assertCount(1, $this->shop->queue()->waiting(SpoolDispatcher::SEGMENTS), 'Only the start of the platform waits in the queue.');
        self::assertSame([], $this->carried(self::ORDER_OF_THE_ATTACKER, Verb::Start));

        $this->startWorkers();
        $this->awaitReport(self::ORDER, 'suspended');
        self::assertSame(0, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER_OF_THE_ATTACKER));
        self::assertNull($this->stored(self::ORDER_OF_THE_ATTACKER));
        self::assertSame([], $this->platform->alerts());
    }

    public function test_a_replayed_start_is_a_duplicate_within_the_signature_window_and_is_answered_401_after_it(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        [$start] = $this->crossed(WireLog::START, self::ORDER);
        // The request as the platform signed it, had it been recorded longer ago than the window.
        $recordedLongAgo = self::signed($start['body'], $start['headers']['webhook-id'], time() - self::SIGNATURE_WINDOW - 1, $this->platform->signingKey);

        $afterTheWindow = $this->deliver($recordedLongAgo, $start['body']);
        $withinTheWindow = $this->deliverAgain($start);
        $rejected = $this->awaitReport(self::ORDER, 'rejected');

        self::assertSame([401, 202], [$afterTheWindow, $withinTheWindow]);
        self::assertCount(2, $this->carried(self::ORDER, Verb::Start), 'The start of the platform and its one replay that verified.');
        self::assertSame(['already_claimed', true, null], [$rejected['code'], $rejected['claimed'], $rejected['executedAttempt']]);
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
    }

    public function test_a_wake_replayed_while_its_run_is_still_open_is_refused_by_its_fence_and_runs_nothing(): void
    {
        // The shipment fails, and the platform leaves the failed run alone for the length of the test.
        $this->platform->configure(['retryDelay' => 600]);
        $this->shop->faults->plan('ship.' . self::ORDER, Faults::FAIL);
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->awaitReport(self::ORDER, 'failed');
        [$wake] = $this->crossed(WireLog::WAKE, self::ORDER);
        $failed = $this->stored(self::ORDER);

        $replayed = $this->deliverAgain($wake);
        $reports = $this->await('the replayed wake was reported', fn (): array|false => count($this->reports(self::ORDER)) === 4 ? $this->reports(self::ORDER) : false);

        self::assertSame(202, $replayed);
        self::assertSame(['failed', 3], [$failed['status'] ?? null, $failed['attempt'] ?? null], 'The wake of the timer ran, and left the run open one attempt further.');
        self::assertSame(['wake', 'refused', 'stale_attempt', null], [$reports[3]['verb'], $reports[3]['outcome'], $reports[3]['code'], $reports[3]['executedAttempt']]);
        self::assertSame($failed, $reports[3]['run'], 'The replayed wake carries the attempt the run has left: Neuron refuses it and the run is as it was.');
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'Once for the payment and once for the wake that failed.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 0);
    }

    public function test_a_replayed_wake_is_stale_and_runs_nothing(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->awaitClosed($runId, 'completed');
        [$wake] = $this->crossed(WireLog::WAKE, self::ORDER);

        $replayed = $this->deliverAgain($wake);
        $refused = $this->awaitReport(self::ORDER, 'refused');

        self::assertSame(202, $replayed);
        self::assertSame(['wake', 'stale_run', null, null], [$refused['verb'], $refused['code'], $refused['executedAttempt'], $refused['run']]);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER));
        self::assertSame('completed', $this->platform->run($runId)['state']);
    }

    /**
     * The web tier of the shop is deployed again.
     *
     * @param array<string, string> $environment what it sets differently
     */
    protected function serveWith(array $environment): void
    {
        $this->shop->stopServing();
        $this->shop->serve($environment);
    }

    /**
     * The three headers of a message, signed as appendix A of the design says. Computed here, apart from the SDK
     * and from the simulator.
     *
     * @param string $signingKey "whsec_" followed by the base64 of the key bytes
     * @return array<string, string>
     */
    protected static function signed(string $body, string $id, int $timestamp, string $signingKey): array
    {
        $key = (string) base64_decode(substr($signingKey, strlen('whsec_')), true);

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true)),
        ];
    }

    protected static function aSigningKey(): string
    {
        return 'whsec_' . base64_encode(random_bytes(24));
    }
}
