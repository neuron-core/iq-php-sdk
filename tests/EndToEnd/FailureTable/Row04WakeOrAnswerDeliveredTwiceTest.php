<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\PlannedFailure;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function array_keys;

/**
 * Row 4 of section 7 of the design: a wake or an answer is delivered twice.
 * The second copy is reported "refused: stale_attempt" with the snapshot, or
 * "stale_run" when the first copy completed the run: every admission that
 * executes raises the attempt, and a completed run leaves no record.
 */
class Row04WakeOrAnswerDeliveredTwiceTest extends FailureTableTestCase
{
    protected const ORDER = 'R0401';


    public function test_a_wake_the_platform_sends_twice_ships_the_order_once_and_its_second_copy_is_refused_as_a_stale_run(): void
    {
        $runId = $this->theOrderSleepsAndNoWorkerTakesItsWake();
        $this->theEndpointAnswersItsNextMessageTooLate();
        $wakes = $this->await('the endpoint took a second copy of the wake', function (): array|false {
            $wakes = $this->crossed(WireLog::WAKE, self::ORDER);

            return ($wakes[count($wakes) - 1]['status'] ?? null) === 202 ? $wakes : false;
        });

        $this->shop->startWorker();
        [$first, $second] = $this->awaitTwoReportsOf(Verb::Wake);

        self::assertSame(0, $wakes[0]['status'], 'The platform got no answer to its first copy.');
        self::assertCount(1, array_unique(array_column($wakes, 'body')), 'Every copy is the same message, with the same fence.');
        self::assertSame(['completed', null, 3, null], [$first['outcome'], $first['code'], $first['executedAttempt'], $first['run']]);
        self::assertSame(['refused', 'stale_run', null, null], [$second['outcome'], $second['code'], $second['executedAttempt'], $second['run']]);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER));
        self::assertSame('completed', $this->platform->run($runId)['state']);
        self::assertSame([], $this->platform->alerts());
    }

    public function test_a_wake_the_queue_delivers_twice_is_refused_as_a_stale_attempt_when_the_first_copy_left_the_run_failed(): void
    {
        $runId = $this->theOrderSleepsAndNoWorkerTakesItsWake();
        $this->shop->faults->plan('ship.' . self::ORDER, Faults::FAIL);
        $this->await('the wake of the timer waits in the queue', fn (): bool => $this->waitingSegments(Verb::Wake) !== []);
        $this->theQueueDeliversTwice(Verb::Wake);

        $this->shop->startWorker();
        [$first, $second] = $this->awaitTwoReportsOf(Verb::Wake);
        $this->awaitClosed($runId, 'completed');

        self::assertSame(['failed', PlannedFailure::class, 3], [$first['outcome'], $first['errorClass'], $first['executedAttempt']]);
        self::assertSame(['refused', 'stale_attempt', null], [$second['outcome'], $second['code'], $second['executedAttempt']]);
        self::assertSame(['failed', 3], [$second['run']['status'], $second['run']['attempt']], 'The second copy is reported with the snapshot.');
        self::assertSame([2, 3], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'), 'The timer, then the retry of the failure.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertNull($this->stored(self::ORDER));
    }

    public function test_an_answer_the_queue_delivers_to_two_workers_at_once_is_admitted_once_and_answers_no_later_wait(): void
    {
        $worker = $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $worker->stop();
        self::assertSame(202, $this->shop->reviewPayment(self::ORDER, approved: true));
        $this->theQueueDeliversTwice(Verb::Answer);

        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHIPS_MUCH_LATER);
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHIPS_MUCH_LATER);
        $byOutcome = array_column($this->awaitTwoReportsOf(Verb::Answer), null, 'outcome');

        self::assertEqualsCanonicalizing(['refused', 'suspended'], array_keys($byOutcome), 'One copy was admitted and one refused.');
        self::assertSame(2, $byOutcome['suspended']['executedAttempt']);
        self::assertSame(['stale_attempt', null, 2], [$byOutcome['refused']['code'], $byOutcome['refused']['executedAttempt'], $byOutcome['refused']['run']['attempt']]);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 0);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertSame([], $this->platform->alerts());
    }

    /**
     * The order was started and paid, and sleeps until it ships. The worker of the segments is stopped, so that
     * what the platform sends next waits in the queue.
     *
     * @return string the run ID
     */
    protected function theOrderSleepsAndNoWorkerTakesItsWake(): string
    {
        $worker = $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $worker->stop();

        return $runId;
    }

    /**
     * @return array<int, array<string, mixed>> the reports of the two copies, in the order the worker dealt with them
     */
    protected function awaitTwoReportsOf(Verb $verb): array
    {
        return $this->await("both copies of the {$verb->value} were reported", function () use ($verb): array|false {
            $reports = array_values(array_filter(
                $this->reports(self::ORDER),
                static fn (array $report): bool => $report['verb'] === $verb->value,
            ));

            return count($reports) >= 2 ? $reports : false;
        });
    }
}
