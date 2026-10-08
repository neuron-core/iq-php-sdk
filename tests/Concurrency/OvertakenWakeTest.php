<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Concurrency;

use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Concurrency\OvertakenPersistence;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * The one interleaving of two copies of a wake that the worker processes of
 * RacingWakesTest only meet by chance, played in a single process.
 */
class OvertakenWakeTest extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /**
     * Neuron reads the control record of a run and then its start record. A copy of a wake that read the first
     * while the run was still there, and misses the second because the first copy completed the run in between,
     * is refused as stale like any other late copy.
     */
    #[DataProvider('backends')]
    public function test_a_copy_of_a_wake_overtaken_between_the_two_reads_neuron_makes_of_its_run_is_refused_as_a_stale_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $flaky = static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1);
        $failed = $bench->cloud->register('flaky', $flaky)->invoke($this->start());
        $wake = $this->wake($failed->run['attempt']);

        $overtaken = new OvertakenPersistence(
            ($bench->persistence)(),
            static function () use ($bench, $wake): void {
                self::assertSame(Outcome::Completed, $bench->cloud->invoke($wake)->outcome);
            },
        );
        $report = (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence(static fn (): PersistenceInterface => $overtaken)
            ->register('flaky', $flaky)
            ->invoke($wake);

        self::assertSame(Outcome::Refused, $report->outcome);
        self::assertSame(RefusalReason::StaleRun->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->run);
        self::assertSame(2, $bench->executions->count(FlakyNode::class), 'Once in the start that failed, once in the copy that won.');
    }

    protected function start(): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_start',
            'verb' => Verb::Start->value,
            'origin' => Origin::PLATFORM,
            'definition' => 'flaky',
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 900,
        ]);
    }

    protected function wake(int $attempt): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_wake',
            'verb' => Verb::Wake->value,
            'origin' => Origin::PLATFORM,
            'definition' => 'flaky',
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'attempt' => $attempt,
        ]);
    }
}
