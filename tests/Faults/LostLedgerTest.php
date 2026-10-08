<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Faults;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function hash;
use function time;

/**
 * A store that lost a ledger record lets a late copy of the start through to
 * Neuron (section 6 of the design). While the run of that start still holds
 * the workflow ID, the copy must not execute it: a start only ever creates a
 * run (9.5).
 */
class LostLedgerTest extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    #[DataProvider('backends')]
    public function test_a_start_that_meets_its_own_failed_run_is_refused_and_does_not_retry_it(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1));
        $start = Invocation::fromArray([
            'invocationId' => 'inv_start',
            'verb' => Verb::Start->value,
            'origin' => Origin::PLATFORM,
            'definition' => 'flaky',
            'workflowId' => self::WORKFLOW_ID,
            'runId' => '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
            'expiresAt' => time() + 900,
        ]);
        $failed = $bench->cloud->invoke($start);
        $this->loseTheLedgerRecord($bench);

        $copy = $bench->cloud->invoke($start);

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::RunInFlight->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertSame($failed->run, $copy->run, 'The failed run waits for the wake of the platform, untouched.');
        self::assertSame(1, $bench->executions->count(FlakyNode::class));
    }

    protected function loseTheLedgerRecord(TestBench $bench): void
    {
        $store = ($bench->persistence)();
        $partition = '__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID);

        self::assertTrue(
            $store->deleteIfUnchanged($partition, 'starts', (string) $store->get($partition, 'starts')),
            'The start left no ledger record to lose.',
        );
    }
}
