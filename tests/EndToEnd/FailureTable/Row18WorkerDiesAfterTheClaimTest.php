<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Process;
use NeuronCore\IQ\Tests\Support\EndToEnd\Wait;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Faults;
use NeuronCore\IQ\Tests\Support\ExampleApp\FaultyStore;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;
use NeuronCore\IQ\Verb;

use function array_column;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const SIGKILL;

/**
 * Row 18 of section 7 of the design: the worker dies between the claim and
 * Neuron's first write. That start never runs. The platform cannot tell it
 * from a run that completed without a report and shows "ended, outcome
 * unknown"; a local one is lost with its job. The claim is never undone, so
 * nothing can run twice.
 */
class Row18WorkerDiesAfterTheClaimTest extends FailureTableTestCase
{
    protected const ORDER = 'R1801';

    /** A start of the platform lives three seconds, and is asked about a moment after that. */
    protected const PLATFORM = ['shortestStartLifetime' => 3, 'startLifetime' => 3, 'startSilence' => 0.3] + parent::PLATFORM;

    /** The lease of the worker that dies, in seconds: the platform asks about its silence when it has run out. */
    protected const LEASE_OF_THE_DOOMED = ['SHOP_LEASE' => '1'];

    public function test_a_start_of_the_platform_whose_worker_died_after_the_claim_never_runs_and_ends_with_an_unknown_outcome(): void
    {
        $doomed = $this->aWorkerThatStandsBeforeTheFirstWriteOfTheRun();
        $runId = $this->fire(self::ORDER);
        $this->platform->tick();
        $this->killOnceItHasClaimed($doomed);

        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        // The queue delivers the start once more, within its use-by time: the claim is still there.
        $this->shop->queue()->push(SpoolDispatcher::SEGMENTS, json_encode($this->carried(self::ORDER, Verb::Start)[0]->toArray(), JSON_THROW_ON_ERROR));
        $rejected = $this->awaitReport(self::ORDER, 'rejected');
        $ended = $this->awaitClosed($runId, 'ended_unknown');

        self::assertSame(SIGKILL, $doomed->endedBy());
        foreach ($this->crossed(WireLog::INSPECT, self::ORDER) as $asked) {
            self::assertSame([null, true], [$asked['answer']['run'], $asked['answer']['claimed']], 'No run, and a claimed run ID: the answer cannot tell what happened.');
        }
        self::assertNull($ended['run']);
        self::assertSame(['already_claimed', true, null], [$rejected['code'], $rejected['claimed'], $rejected['run']]);
        self::assertCount(1, $this->crossed(WireLog::START, self::ORDER), 'The platform never replaces a start on its own.');
        $this->assertTheOrderNeverRan();
    }

    public function test_a_start_an_inspect_shows_claimed_and_without_a_run_is_not_sent_again_although_its_use_by_time_would_allow_it(): void
    {
        $this->platform->configure(['startLifetime' => 900]);
        $doomed = $this->aWorkerThatStandsBeforeTheFirstWriteOfTheRun();
        $runId = $this->fire(self::ORDER);
        $this->platform->tick();
        $this->killOnceItHasClaimed($doomed);

        // The lease the dead worker announced runs out with no report, and the platform asks.
        [$asked] = $this->await('the platform asked about the silent segment', fn (): array|false => $this->crossed(WireLog::INSPECT, self::ORDER) ?: false);
        $held = $this->platform->run($runId);

        self::assertSame([null, true], [$asked['answer']['run'], $asked['answer']['claimed']]);
        self::assertSame('open', $held['state']);
        self::assertSame(['start_silence'], array_column($held['timers'], 'reason'), 'After the claim nothing sends a start again: only the question of rule 12 is left.');
        self::assertCount(1, $this->crossed(WireLog::START, self::ORDER));
        $this->assertTheOrderNeverRan();
    }

    public function test_a_checkout_whose_worker_died_after_the_claim_is_lost_with_its_job(): void
    {
        $doomed = $this->aWorkerThatStandsBeforeTheFirstWriteOfTheRun();
        $checkout = $this->shop->checkout(self::ORDER, self::NOTE);
        $this->killOnceItHasClaimed($doomed);

        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::SHORT_LEASE);
        $ended = $this->awaitClosed((string) $checkout['body']['runId'], 'ended_unknown');

        [$asked] = $this->crossed(WireLog::INSPECT, self::ORDER);
        self::assertSame(202, $checkout['status'], 'The checkout was told its run ID.');
        self::assertSame('application', $ended['origin']);
        self::assertSame([null, true], [$asked['answer']['run'], $asked['answer']['claimed']]);
        self::assertSame([], $this->reports(self::ORDER), 'No report: the platform knew the run from its announcement alone.');
        self::assertSame([], $this->shop->queue()->waiting(SpoolDispatcher::SEGMENTS), 'Nothing is left to run.');
        $this->assertTheOrderNeverRan();
    }

    /**
     * The first write of a worker for a start is its claim. Its second is Neuron's first write of the run:
     * the worker stands before it for as long as it lives.
     */
    protected function aWorkerThatStandsBeforeTheFirstWriteOfTheRun(): Process
    {
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        $this->shop->faults->plan(FaultyStore::WRITE, Faults::PASS, Faults::HOLD);

        return $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::LEASE_OF_THE_DOOMED);
    }

    /**
     * The clock of the platform does not tick meanwhile: it asks nothing before the claim was written.
     */
    protected function killOnceItHasClaimed(Process $worker): void
    {
        Wait::until('the worker claimed the start', fn (): bool => $this->shop->faults->pending(FaultyStore::WRITE) === []);

        $worker->kill();
    }

    protected function assertTheOrderNeverRan(): void
    {
        self::assertSame(0, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        self::assertNull($this->stored(self::ORDER));
        self::assertCount(1, $this->shop->stored(), 'The store holds the ledger with the claim, and nothing else.');
    }
}
