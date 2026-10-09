<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use Closure;
use DateTimeImmutable;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Questions\SleepingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Questions\Holder;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function str_starts_with;
use function time;

/**
 * An abandon and another process acting on the same workflow ID at the same
 * moment, one step into the other: each test lets the other process in just
 * before one operation reaches the store.
 */
class AbandonInterleavingTest extends QuestionsTestCase
{
    #[DataProvider('backends')]
    public function test_an_abandon_that_gets_in_before_the_worker_writes_its_claim_is_answered_200_and_the_start_is_rejected(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $answer = null;
        $this->letInJustBefore(self::writeOfTheLedger(...), function () use (&$answer): void {
            $answer = $this->deliver($this->abandon(self::RUN_ID));
        });

        [$report] = $this->work();

        self::assertInstanceOf(HttpResponse::class, $answer);
        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$report->outcome, $report->code]);
        self::assertSame(0, $bench->executions->total(), 'A start never runs after its abandon was answered 200.');
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_arrives_between_the_claim_and_the_first_write_of_neuron_is_answered_409_stale_run_and_the_start_runs(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $answer = null;
        $this->letInJustBefore(self::ignitionOfTheRun(...), function () use (&$answer): void {
            $answer = $this->deliver($this->abandon(self::RUN_ID));
        });

        [$report] = $this->work();

        self::assertInstanceOf(HttpResponse::class, $answer);
        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, 1], [$report->outcome, $report->executedAttempt]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        self::assertSame(self::RUN_ID, $this->storedRun()['runId'] ?? null, 'The run appeared after the answer.');
    }

    #[DataProvider('backends')]
    public function test_an_abandon_overtaken_by_the_worker_that_claims_and_suspends_its_start_discards_the_run_that_worker_left(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->letInJustBefore(self::writeOfTheLedger(...), function (): void {
            $this->settled = $this->work();
        });

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        [$report] = $this->settled;
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Suspended, $report->outcome, 'The start ran before the abandon reached the ledger.');
        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull($this->storedRun());
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_an_abandon_overtaken_at_its_removal_by_a_continuation_of_the_run_is_answered_409_conflict_with_the_run_as_it_is_now(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->work();
        $this->letInJustBefore(self::removalOfTheRun(...), static function () use ($bench): void {
            $bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->signal(WaitingWorkflow::EVENT)->run();
        });

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        $continued = $this->storedRun();
        self::assertSame(2, $continued['attempt'] ?? null);
        self::assertSame(
            [409, ['code' => 'conflict', 'run' => $continued, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_an_abandon_overtaken_at_its_removal_by_the_wake_that_completes_the_run_is_answered_409_conflict_with_no_run(
        string $backend,
    ): void {
        $this->benchOn($backend);
        $this->afterTheFirstSegmentOf(static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('@0')));
        $this->letInJustBefore(self::removalOfTheRun(...), function (): void {
            $this->deliver($this->wake(attempt: 1));
            $this->settled = $this->work();
        });

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        [$report] = $this->settled;
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(
            [409, ['code' => 'conflict', 'run' => null, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_an_abandon_refused_as_executing_whose_run_ends_before_it_is_read_again_is_answered_409_executing_with_no_run(
        string $backend,
    ): void {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Executing, function (): HttpResponse {
            $this->letInJustBefore(self::readOfTheRun(4), function (): void {
                // What the worker of another process does when it completes the run at that moment: it removes its records.
                $store = ($this->bench->persistence)();
                $store->deleteIfUnchanged(self::WORKFLOW_ID, '__control', (string) $store->get(self::WORKFLOW_ID, '__control'));
            });

            return $this->abandonTheHolder();
        });

        self::assertSame(
            [409, ['code' => 'executing', 'run' => null, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    /**
     * @param int $read the read of the run the other holder ends just before: the one of Neuron, or the one
     *                  for the answer, once Neuron has refused the abandon because of that holder
     */
    #[DataProvider('readsTheOtherHolderMayEndBefore')]
    public function test_an_abandon_that_entered_its_run_id_and_whose_other_holder_ends_while_it_is_dealt_with_is_answered_200_with_no_run(
        string $backend,
        int $read,
    ): void {
        $bench = $this->benchOn($backend);
        $bench->cloud->register(self::DEFINITION, static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('@0')));
        $holder = (string) $bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run()->getRunId();
        $this->work();
        $this->letInJustBefore(self::readOfTheRun($read), function () use ($holder): void {
            $this->deliver($this->wake(attempt: 1, fields: ['runId' => $holder]));
            $this->settled = $this->work();
        });

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        [$report] = $this->settled;
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertSame([self::RUN_ID], array_keys($this->ledger()), 'The entry is the one this abandon wrote.');
    }

    #[DataProvider('backends')]
    public function test_a_start_still_queued_is_rejected_once_its_abandon_was_answered_200_although_another_run_held_the_workflow_id(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run();
        $this->work();
        $this->deliver($this->start());
        $this->letInJustBefore(self::readOfTheRun(3), function (): void {
            // What the worker of another process does when it completes the run at that moment: it removes its records.
            $store = ($this->bench->persistence)();
            $store->deleteIfUnchanged(self::WORKFLOW_ID, '__control', (string) $store->get(self::WORKFLOW_ID, '__control'));
        });
        $answer = $this->deliver($this->abandon(self::RUN_ID));

        [$report] = $this->work();

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$report->outcome, $report->code]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class), 'Only the run that held the workflow ID executed a node.');
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_found_its_run_id_and_whose_other_holder_ends_before_neuron_reads_it_is_answered_409_stale_run_with_no_run(
        string $backend,
    ): void {
        $bench = $this->benchOn($backend);
        $bench->cloud->register(self::DEFINITION, static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('@0')));
        $holder = (string) $bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run()->getRunId();
        $this->work();
        (new Ledger($bench->persistence))->claim(self::WORKFLOW_ID, self::RUN_ID, time() + 900);
        $this->letInJustBefore(self::readOfTheRun(3), function () use ($holder): void {
            $this->deliver($this->wake(attempt: 1, fields: ['runId' => $holder]));
            $this->work();
        });

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_an_abandon_whose_claim_loses_four_writes_to_other_processes_still_enters_its_run_id(string $backend): void
    {
        $this->ordersOn($backend);
        $this->letOtherClaimsInBeforeTheNextWritesOfTheLedger(4);

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertCount(5, $this->ledger());
    }

    #[DataProvider('backends')]
    public function test_an_abandon_whose_claim_loses_five_writes_to_other_processes_is_answered_503_unavailable_and_has_entered_nothing(
        string $backend,
    ): void {
        $this->ordersOn($backend);
        $this->letOtherClaimsInBeforeTheNextWritesOfTheLedger(5);

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
        self::assertArrayNotHasKey(self::RUN_ID, $this->ledger());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function readsTheOtherHolderMayEndBefore(): array
    {
        return self::onEachBackend([
            'before Neuron reads the run it is to discard' => [3],
            'before the run is read for the answer' => [4],
        ]);
    }

    /**
     * From now on another process gets in just before the store receives the first operations the test picks.
     *
     * @param Closure(string $operation, string $partition): bool $moment
     * @param Closure(int $time): void $other what the other process does, given how many times it got in so far
     * @param int $times how many of those operations it gets in front of
     */
    protected function letInJustBefore(Closure $moment, Closure $other, int $times = 1): void
    {
        $persistence = $this->bench->persistence;
        $gotIn = 0;
        // One count for every store the SDK builds: what the other process does reaches the store too.
        $justBefore = static function (string $operation, string $partition) use ($moment, $other, $times, &$gotIn): void {
            if ($gotIn < $times && $moment($operation, $partition)) {
                $other(++$gotIn);
            }
        };

        $this->bench->cloud->setPersistence(
            static fn (): PersistenceInterface => new InterceptedPersistence($persistence(), $justBefore),
        );
    }

    /**
     * Other processes claim other starts of the same workflow ID, each just before one conditional write of
     * the ledger: that write is lost.
     */
    protected function letOtherClaimsInBeforeTheNextWritesOfTheLedger(int $times): void
    {
        $ledger = new Ledger($this->bench->persistence);

        $this->letInJustBefore(
            self::writeOfTheLedger(...),
            static function (int $time) use ($ledger): void {
                $ledger->claim(self::WORKFLOW_ID, "1-the-start-of-another-process-{$time}", time() + 900);
            },
            $times,
        );
    }

    protected static function writeOfTheLedger(string $operation, string $partition): bool
    {
        return $operation !== 'get' && str_starts_with($partition, self::LEDGER_PARTITION_PREFIX);
    }

    /**
     * Neuron's first write of a run: the one that creates it.
     */
    protected static function ignitionOfTheRun(string $operation, string $partition): bool
    {
        return $operation === 'initializeIfAbsent' && $partition === self::WORKFLOW_ID;
    }

    protected static function removalOfTheRun(string $operation, string $partition): bool
    {
        return $operation === 'deleteIfUnchanged' && $partition === self::WORKFLOW_ID;
    }

    /**
     * An abandon reads the records of a run that holds the workflow ID four times: twice to see that a run is
     * there, once when Neuron checks what it is to discard, and once more for the run its answer shows.
     *
     * @return Closure(string $operation, string $partition): bool whether the operation is that read
     */
    protected static function readOfTheRun(int $nth): Closure
    {
        $reads = 0;

        return static function (string $operation, string $partition) use ($nth, &$reads): bool {
            return $operation === 'get' && $partition === self::WORKFLOW_ID && ++$reads === $nth;
        };
    }
}
