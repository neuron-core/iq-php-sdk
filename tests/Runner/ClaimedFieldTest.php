<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Stub\Runner\MisroutedWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_key_last;
use function time;

/**
 * The "claimed" row of the report table of appendix A, for the report of a
 * start's own job: true when the call wrote the entry or found it there, null
 * when it ended before the claim or the claim failed. The start that expires
 * between its first check and its claim is in ClaimTest, and the reports of an
 * observe are in ObserveTest.
 */
class ClaimedFieldTest extends RunnerTestCase
{
    /** How a start ends => the outcome and the code it reports, and the "claimed" the table gives it. */
    protected const TABLE = [
        'the run completes' => ['completed', null, true],
        'the run suspends' => ['suspended', null, true],
        'a node throws' => ['failed', null, true],
        'another run holds the workflow ID' => ['refused', 'run_in_flight', true],
        'Neuron cannot write the run' => ['aborted', 'not_executed', true],
        'a copy claimed it before' => ['rejected', 'already_claimed', true],
        'the definition is unknown' => ['aborted', 'unknown_definition', null],
        'the version is unknown' => ['aborted', 'unknown_version', null],
        'the use-by time has passed' => ['rejected', 'expired', null],
        'the factory throws' => ['aborted', 'not_executed', null],
        'no node handles the start event' => ['rejected', 'invalid_start', null],
        'the announcement can go nowhere' => ['aborted', 'announce_failed', null],
        'five conditional writes are lost' => ['aborted', 'unavailable', null],
        'the store fails at the claim' => ['aborted', 'unavailable', null],
    ];

    /**
     * @return iterable<string, array{string, key-of<self::TABLE>, string, string|null, bool|null}>
     */
    public static function startEndings(): iterable
    {
        foreach (self::backends() as $name => [$backend]) {
            foreach (self::TABLE as $ending => [$outcome, $code, $claimed]) {
                yield "{$ending}, on {$name}" => [$backend, $ending, $outcome, $code, $claimed];
            }
        }
    }

    /**
     * @param key-of<self::TABLE> $ending
     */
    #[DataProvider('startEndings')]
    public function test_the_report_of_a_start_says_claimed_as_the_table_of_appendix_a_does(
        string $backend,
        string $ending,
        string $outcome,
        ?string $code,
        ?bool $claimed,
    ): void {
        $this->registerEveryDefinition($this->benchOn($backend));

        $this->settle($this->startThatEndsWhen($ending));

        $reports = $this->reportsSent();
        $report = $reports[array_key_last($reports)];
        self::assertSame([$outcome, $code], [$report['outcome'], $report['code']]);
        self::assertSame($claimed, $report['claimed']);
    }

    /**
     * Arrange the ending, and give the start that meets it.
     *
     * @param key-of<self::TABLE> $ending
     */
    protected function startThatEndsWhen(string $ending): Invocation
    {
        return match ($ending) {
            'the run completes' => $this->start('greeting'),
            'the run suspends' => $this->start('order'),
            'a node throws' => $this->start('flaky'),
            'another run holds the workflow ID' => $this->startBehindAnotherRun(),
            'Neuron cannot write the run' => $this->startOnAStoreFailingOn('initializeIfAbsent', self::WORKFLOW_ID),
            'a copy claimed it before' => $this->startDeliveredBefore(),
            'the definition is unknown' => $this->start('retired'),
            'the version is unknown' => $this->start('greeting', fields: ['runId' => '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12']),
            'the use-by time has passed' => $this->start('greeting', fields: ['expiresAt' => time() - 1]),
            'the factory throws' => $this->start('unbuildable'),
            'no node handles the start event' => $this->start('misrouted'),
            'the announcement can go nowhere' => $this->applicationStartNobodyHearsOf(),
            'five conditional writes are lost' => $this->startRacedForTheLedger(),
            'the store fails at the claim' => $this->startOnAStoreFailingOn('get', $this->ledgerPartition()),
        };
    }

    protected function startBehindAnotherRun(): Invocation
    {
        $this->bench->iq->invoke($this->start('order'));

        return $this->start('order', fields: ['runId' => '1-another-run']);
    }

    protected function startDeliveredBefore(): Invocation
    {
        $this->bench->iq->invoke($this->start('greeting'));

        return $this->start('greeting');
    }

    protected function startOnAStoreFailingOn(string $operation, string $partition): Invocation
    {
        $this->failStoreOn($operation, $partition);

        return $this->start('greeting');
    }

    /**
     * Only a segment of the application's own stops when its announcement can be neither posted nor queued.
     */
    protected function applicationStartNobodyHearsOf(): Invocation
    {
        $this->bench->platform->failNext();
        $this->bench->iq->setDispatcher(new UnreachableQueue());

        return $this->start('greeting', self::APPLICATION);
    }

    protected function startRacedForTheLedger(): Invocation
    {
        $this->rivalsWinTheLedger(5);

        return $this->start('greeting');
    }

    /**
     * The runner reports before it throws, so the report is on the platform either way.
     */
    protected function settle(Invocation $start): void
    {
        try {
            $this->bench->iq->invoke($start);
        } catch (IQException) {
        }
    }

    protected function registerEveryDefinition(TestBench $bench): void
    {
        $executions = $bench->executions;

        $bench->iq
            ->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions))
            ->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1))
            ->register('misrouted', static fn (): MisroutedWorkflow => new MisroutedWorkflow($executions))
            ->register('unbuildable', static fn (): Workflow => throw new FactoryFailed('The container could not build the workflow.'));
    }
}
