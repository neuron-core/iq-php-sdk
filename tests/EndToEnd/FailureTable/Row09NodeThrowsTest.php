<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Confidential;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\PlannedFailure;

use function array_column;
use function count;
use function implode;

/**
 * Row 9 of section 7 of the design: a node throws. The segment is reported
 * "failed", with the exception class and the snapshot: "failed" is committed
 * before the exception surfaces. The platform retries by its policy.
 */
class Row09NodeThrowsTest extends FailureTableTestCase
{
    protected const ORDER = 'R0901';

    /** The policy retries a run once: its second failure is left to a person. */
    protected const PLATFORM = ['retryAttempts' => 1] + parent::PLATFORM;

    public function test_a_node_that_throws_is_reported_failed_with_its_class_and_the_run_is_retried_under_the_attempt_shown(): void
    {
        $this->shop->faults->plan('await-payment.' . self::ORDER, Faults::FAIL);
        $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $failed = $this->awaitReport(self::ORDER, 'failed');
        $this->awaitWait($runId, 'wait_for_event');

        self::assertSame(['start', PlannedFailure::class, 1, true], [$failed['verb'], $failed['errorClass'], $failed['executedAttempt'], $failed['claimed']]);
        self::assertSame(['failed', 1, null], [$failed['run']['status'], $failed['run']['attempt'], $failed['run']['leaseExpiresAt']]);
        self::assertSame(
            [['attempt' => 1, 'kind' => 'failed', 'errorClass' => PlannedFailure::class]],
            self::withoutInstants($this->platform->run($runId)['failures']),
        );
        self::assertSame([1], array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt'));
        self::assertSame(2, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER), 'The node that threw ran again, once.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertStringNotContainsString(Confidential::EXCEPTION, implode("\n", $this->platform->wire()->crossed()), 'The message of the exception stays in the shop.');
    }

    public function test_a_run_that_keeps_failing_is_left_failed_when_the_policy_gives_up_and_a_person_is_told(): void
    {
        $this->shop->faults->plan('await-payment.' . self::ORDER, Faults::FAIL, Faults::FAIL);
        $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $this->await('the retry failed too', fn (): bool => count($this->reports(self::ORDER, 'failed')) === 2);
        $this->await('a person is told', fn (): bool => $this->platform->alerts() !== []);

        [$alert] = $this->platform->alerts();
        self::assertSame(['retries_exhausted', $runId], [$alert['kind'], $alert['runId']]);
        self::assertSame([1, 2], array_column($this->platform->run($runId)['failures'], 'attempt'), 'One failure per run ID and attempt.');
        self::assertSame([], $this->platform->run($runId)['timers'], 'The platform plans nothing more for the run.');
        self::assertSame([[], [1]], [$this->platform->tick(), array_column(array_column($this->crossed(WireLog::WAKE, self::ORDER), 'fields'), 'attempt')]);
        self::assertSame(['failed', 2], [$this->stored(self::ORDER)['status'] ?? null, $this->stored(self::ORDER)['attempt'] ?? null]);
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 0, ship: 0);
    }

    /**
     * @param array<int, array<string, mixed>> $failures as the platform counted them
     * @return array<int, array<string, mixed>>
     */
    protected static function withoutInstants(array $failures): array
    {
        foreach ($failures as &$failure) {
            unset($failure['countedAt']);
        }

        return $failures;
    }
}
