<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd\FailureTable;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\ShipOrderNode;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;

/**
 * Row 13 of section 7 of the design: a timer fires early, because the clock
 * of the platform and the clock of the application differ. Neuron reads its
 * own clock and returns the same wait and attempt; the platform tries again
 * a moment later.
 *
 * Both run on one machine here, so the difference is the platform's: its
 * clock is set three seconds ahead of the true time.
 */
class Row13TimerFiresEarlyTest extends FailureTableTestCase
{
    protected const ORDER = 'R1301';

    protected const SHOP = ['SHOP_SHIPS_AFTER' => '2'];

    protected const PLATFORM = ['clockAhead' => 3] + parent::PLATFORM;

    public function test_a_wake_that_comes_before_the_timer_is_due_by_the_clock_of_the_shop_runs_nothing_and_is_tried_again(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);
        $sleeping = $this->platform->run($runId)['run'];
        $this->awaitClosed($runId, 'completed');

        $wakes = $this->crossed(WireLog::WAKE, self::ORDER);
        $tooEarly = $this->reportsOfWakes('suspended');
        [$onTime] = $this->reportsOfWakes('completed');

        self::assertNotSame([], $tooEarly, 'The platform fired the timer before it was due.');
        foreach ($tooEarly as $report) {
            self::assertLessThan($sleeping['wait']['wakeAt'], $report['at'], 'Too early by the clock of the shop.');
            self::assertNull($report['executedAttempt'], 'No segment opened.');
            self::assertSame($sleeping, $report['run'], 'The same wait and the same attempt.');
        }
        self::assertGreaterThanOrEqual($sleeping['wait']['wakeAt'], $onTime['at']);
        self::assertSame(3, $onTime['executedAttempt']);
        self::assertCount(1, array_unique(array_column($wakes, 'body')), 'Every try is the same wake, with the same fence.');
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(ShipOrderNode::class, self::ORDER), 'Once for the payment and once for the wake that was due.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame([], $this->platform->alerts());
    }

    /**
     * @return array<int, array<string, mixed>> the reports of the wakes of the order with an outcome, oldest first
     */
    protected function reportsOfWakes(string $outcome): array
    {
        return array_values(array_filter(
            $this->reports(self::ORDER, $outcome),
            static fn (array $report): bool => $report['verb'] === 'wake',
        ));
    }
}
