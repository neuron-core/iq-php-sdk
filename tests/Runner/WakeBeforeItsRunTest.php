<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use NeuronCore\Cloud\Invocation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The guard before a wake compares the tag on one read of the store. A wake sent
 * for a run that does not hold the workflow ID at that read is refused there, so
 * that Neuron never continues a run whose tag the SDK has not seen.
 */
class WakeBeforeItsRunTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_that_does_not_hold_the_workflow_id_is_refused_as_stale_run_without_asking_neuron(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $reads = 0;
        $this->interceptStore(static function (string $operation, string $partition) use (&$reads): void {
            $reads += (int) ($partition === self::WORKFLOW_ID);
        });

        $report = $bench->cloud->invoke($this->wakeOf('greeting', self::RUN_ID, attempt: 1));

        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$report->outcome, $report->code]);
        self::assertSame(2, $reads, 'The guard reads once and the report reads once: Neuron is not asked.');
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_names_another_definition_does_not_continue_a_run_that_fails_after_the_guard_has_read(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->aRunOfFlakyFailsBeforeTheSecondReadOfTheStore($bench);

        $report = $bench->cloud->invoke($this->wakeOf('greeting', self::RUN_ID, attempt: 1));

        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value], [$report->outcome, $report->code]);
        self::assertSame([1, 0], [$bench->executions->count(FlakyNode::class), $bench->executions->count(CompleteNode::class)]);
        self::assertSame(['failed', 1], [$report->run['status'], $report->run['attempt']]);
    }

    /**
     * The first read of the store finds no run. Just before the second, another worker starts "flaky" under
     * the run ID of these tests and its node throws: the run is failed, and carries the tag "flaky".
     */
    protected function aRunOfFlakyFailsBeforeTheSecondReadOfTheStore(TestBench $bench): void
    {
        $executions = $bench->executions;
        $store = ($bench->persistence)();
        $reads = 0;

        $this->interceptStore(static function (string $operation, string $partition) use ($executions, $store, &$reads): void {
            if ($partition !== self::WORKFLOW_ID || ++$reads !== 2) {
                return;
            }

            try {
                (new FlakyWorkflow($executions))->for(self::WORKFLOW_ID)->setPersistence($store)
                    ->run(ExecutionRequest::start(runId: self::RUN_ID, tag: 'flaky'));
            } catch (NodeFailed) {
                // The run stays failed in the store.
            }
        });
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
