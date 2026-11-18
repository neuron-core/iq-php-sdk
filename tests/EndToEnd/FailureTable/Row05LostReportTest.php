<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function array_filter;
use function array_values;
use function count;

/**
 * Row 5 of section 7 of the design: a report is lost. An observe waits in the
 * queue of the shop with the outcome and reports the state as it is then: the
 * outcome was committed before the report was built.
 */
class Row05LostReportTest extends FailureTableTestCase
{
    protected const ORDER = 'R0501';

    public function test_the_observe_of_a_lost_report_still_says_that_the_order_completed(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);

        // The platform fails to record the next report: the one of the segment that ships the order.
        $this->platform->configure(['reportsLost' => 1]);
        $this->awaitClosed($runId, 'completed');

        [$lost, $observed] = $this->reportsCrossed(Verb::Wake);
        [$observe] = $this->carried(self::ORDER, Verb::Observe);
        self::assertSame([503, 200], [$lost['status'], $observed['status']]);
        self::assertSame($lost['fields']['invocationId'], $observed['fields']['invocationId'], 'Both speak for the same segment.');
        self::assertSame(['completed', 3, null], [$observed['fields']['outcome'], $observed['fields']['executedAttempt'], $observed['fields']['run']]);
        self::assertSame('completed', $observe->body['outcome'] ?? null, 'The observe waited in the queue with the outcome.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER));
        self::assertSame([], $this->platform->alerts());
    }

    public function test_the_observe_of_a_lost_report_shows_the_run_as_it_is_when_a_worker_posts_it(): void
    {
        $this->platform->configure(['reportsLost' => 1]);
        // No worker on the notices yet: the observe of the lost report waits in the queue.
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHIPS_MUCH_LATER);
        $runId = $this->fire(self::ORDER);
        $this->await('the observe of the lost report waits in the queue', fn (): bool => $this->shop->queue()->waiting(SpoolDispatcher::NOTICES) !== []);
        self::assertNull($this->platform->run($runId)['run'], 'The platform holds no snapshot of the run yet.');

        // Meanwhile the order is paid: the run has moved on when the observe is posted.
        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        [$lost, $observed] = $this->await('the observe was posted', fn (): array|false => count($this->reportsCrossed(Verb::Start)) === 2 ? $this->reportsCrossed(Verb::Start) : false);

        self::assertSame([503, 200], [$lost['status'], $observed['status']]);
        self::assertSame(['suspended', 1, true], [$observed['fields']['outcome'], $observed['fields']['executedAttempt'], $observed['fields']['claimed']]);
        self::assertSame(['wait_for_event', 1], [$lost['fields']['run']['wait']['type'], $lost['fields']['run']['attempt']]);
        self::assertSame(['sleep_until', 2], [$observed['fields']['run']['wait']['type'], $observed['fields']['run']['attempt']]);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 0);
    }

    /**
     * @return array<int, array{fields: array<string, mixed>, headers: array<string, string>, status: int, answer: array<string, mixed>, body: string}>
     *         the reports of the segments of one verb, as they reached the platform, oldest first
     */
    protected function reportsCrossed(Verb $verb): array
    {
        return array_values(array_filter(
            $this->crossed(WireLog::REPORT, self::ORDER),
            static fn (array $report): bool => $report['fields']['verb'] === $verb->value,
        ));
    }
}
