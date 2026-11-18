<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\FlakyNode;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The guard before a wake: a run executes only through the definition that
 * started it. In these tests "flaky" starts a run whose node fails, and
 * "greeting" is another definition, whose own node handles the same start
 * event: had a wake that names "greeting" executed, that node would have run.
 */
class WrongDefinitionTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_is_reported_rejected_as_wrong_definition(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame([Outcome::Rejected, Code::WrongDefinition->value], [$report->outcome, $report->code]);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->errorClass);
        self::assertNull($report->claimed);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Rejected, Code::WrongDefinition->value);
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_does_not_run_the_nodes_of_that_definition_on_a_failed_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame([1, 0], [$bench->executions->count(FlakyNode::class), $bench->executions->count(CompleteNode::class)]);
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_leaves_the_run_as_it_was(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame('failed', $failed->run['status']);
        self::assertSame($failed->run, $report->run);
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_writes_nothing_to_the_store(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);
        $written = [];
        $this->interceptStore(static function (string $operation, string $partition) use (&$written): void {
            if ($operation !== 'get') {
                $written[] = [$operation, $partition];
            }
        });

        $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame([], $written);
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_leaves_nothing_in_the_queue(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('backends')]
    public function test_an_application_wake_that_names_another_definition_is_final_and_returned_not_thrown(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wake('greeting', $failed, self::APPLICATION));

        self::assertSame([Outcome::Rejected, Code::WrongDefinition->value], [$report->outcome, $report->code]);
        self::assertSame(0, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_is_rejected_whatever_its_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wakeOf('greeting', $failed->runId, attempt: 2));

        self::assertSame([Outcome::Rejected, Code::WrongDefinition->value], [$report->outcome, $report->code]);
        self::assertSame($failed->run, $report->run);
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_that_does_not_hold_the_workflow_id_is_refused_as_stale_run_whatever_definition_it_names(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wakeOf('greeting', '1-a-run-that-ended', attempt: 1));

        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$report->outcome, $report->code]);
        self::assertSame($failed->run, $report->run);
        self::assertSame(0, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_the_definition_that_started_the_run_executes(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunOfFlaky($bench);

        $report = $bench->iq->invoke($this->wake('flaky', $failed));

        self::assertSame([Outcome::Completed, 2], [$report->outcome, $report->executedAttempt]);
        self::assertSame(2, $bench->executions->count(FlakyNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_that_carries_no_tag_is_not_checked_and_executes_under_the_definition_it_names(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failed = $this->failedRunStartedOutsideTheSdk($bench);

        $report = $bench->iq->invoke($this->wake('greeting', $failed));

        self::assertSame([Outcome::Completed, 2], [$report->outcome, $report->executedAttempt]);
        self::assertSame(1, $bench->executions->count(CompleteNode::class), 'Nothing says which definition started a run with no tag.');
    }

    /**
     * A worker ran a start of "flaky" and its node threw: the run is failed, and carries the tag "flaky".
     *
     * @return Report the report of that start, which shows the failed run
     */
    protected function failedRunOfFlaky(TestBench $bench): Report
    {
        $executions = $bench->executions;
        $bench->iq->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions));

        return $bench->iq->invoke($this->start('flaky'));
    }

    /**
     * The same workflow failed under Neuron alone, as before the migration to the SDK: its run carries no tag.
     *
     * @return Report what an observe reads from the store: the failed run
     */
    protected function failedRunStartedOutsideTheSdk(TestBench $bench): Report
    {
        try {
            (new FlakyWorkflow($bench->executions))->for(self::WORKFLOW_ID)->setPersistence(($bench->persistence)())->run();
        } catch (NodeFailed) {
            // The run stays failed in the store.
        }

        return $bench->iq->invoke($this->observeOf($this->start('greeting')));
    }

    protected function wakeOf(string $definition, string $runId, int $attempt): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_wake',
            'verb' => Verb::Wake->value,
            'origin' => self::PLATFORM,
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $runId,
            'attempt' => $attempt,
        ]);
    }
}
