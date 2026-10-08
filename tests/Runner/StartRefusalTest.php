<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 6 of the runner for a start that Neuron refuses after its claim.
 */
class StartRefusalTest extends RunnerTestCase
{
    protected const RIVAL_CONTROL = 'the control record of a run that ends at once';

    #[DataProvider('backends')]
    public function test_a_start_on_a_workflow_id_held_by_another_run_is_refused_as_run_in_flight(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions));
        $holder = $bench->cloud->invoke($this->start('order'));

        $report = $bench->cloud->invoke($this->start('order', fields: ['invocationId' => 'inv_other', 'runId' => '1-another-run']));

        self::assertSame(Outcome::Refused, $report->outcome);
        self::assertSame(RefusalReason::RunInFlight->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertSame($holder->run, $report->run, 'A start never replaces a run.');
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::RunInFlight->value);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_meets_a_conflict_is_tried_once_more_in_place(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalRunEndsDuringTheNextIgnitions(1);

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $report->executedAttempt);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_start_that_meets_a_conflict_twice_is_refused_as_conflict_and_stays_claimed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalRunEndsDuringTheNextIgnitions(2);

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Refused, $report->outcome);
        self::assertSame(RefusalReason::Conflict->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertTrue($report->claimed);
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::Conflict->value);
    }

    /**
     * Neuron answers "conflict" to a start that found the workflow ID taken and, on reading it, free again. A
     * rival run does that here for the next $ignitions: it holds the workflow ID when Neuron tries to create
     * the run, and is gone when Neuron reads which run that is.
     */
    protected function rivalRunEndsDuringTheNextIgnitions(int $ignitions): void
    {
        $store = ($this->bench->persistence)();

        $this->interceptStore(static function (string $operation, string $partition) use ($store, &$ignitions): void {
            if ($partition !== self::WORKFLOW_ID) {
                return;
            }

            if ($operation === 'initializeIfAbsent' && $ignitions > 0) {
                $ignitions--;
                $store->initializeIfAbsent(self::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
            }

            if ($operation === 'get') {
                $store->deleteIfUnchanged(self::WORKFLOW_ID, '__control', self::RIVAL_CONTROL);
            }
        });
    }
}
