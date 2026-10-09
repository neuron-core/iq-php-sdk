<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Faults\StoreFault;
use NeuronCore\Cloud\Tests\Support\Faults\StoreJournal;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_key_exists;
use function hash;
use function str_repeat;

/**
 * An abandon over a store that fails at each of its operations in turn: the
 * answer is 503 unavailable wherever the abandon needs the operation, and
 * each row says what the store and the ledger hold afterwards and what the
 * same abandon is answered when the platform sends it again. The reads of
 * the run that only show it are not needed: Neuron discards a run on its
 * control record.
 */
class AbandonStoreFaultTest extends QuestionsTestCase
{
    /** No run holds the workflow ID and the ledger has no record of it. */
    protected const NO_RUN = 'no run';

    /** A run the application started in its own process waits for an event: its run ID is not in the ledger. */
    protected const A_RUN = 'a run';

    /** What an abandon is answered when it left no run to its knowledge and could not read the store for its answer. */
    protected const DISCARDED_UNSEEN = [200, ['run' => null, 'claimed' => true, 'snapshotRead' => false]];

    protected StoreFault $fault;

    /** The run ID the abandon names. */
    protected string $runId;

    /** The attempt the abandon carries: the one of the run, or none when the platform has heard of no run. */
    protected ?int $attempt = null;

    #[DataProvider('faultsWhereTheAbandonNeedsTheStore')]
    public function test_where_the_abandon_needs_the_store_a_failure_is_answered_503_unavailable_with_a_retry_after_and_nothing_of_it(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
    ): void {
        $this->sceneOn($backend, $scene);

        $answer = $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertSame($struck, $this->fault->struck()?->describe());
        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
        self::assertStringNotContainsString('The store', $answer->body);
    }

    #[DataProvider('faultsAtTheFirstLookAtTheRun')]
    public function test_a_first_look_at_the_run_that_fails_does_not_stop_the_abandon(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
    ): void {
        $this->sceneOn($backend, $scene);

        $answer = $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertSame($struck, $this->fault->struck()?->describe());
        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('faultsAtTheReadForTheAnswer')]
    public function test_a_store_that_cannot_be_read_for_the_answer_is_answered_200_with_snapshot_read_false_and_nothing_of_the_failure(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
    ): void {
        $this->sceneOn($backend, $scene);

        $answer = $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertSame($struck, $this->fault->struck()?->describe());
        self::assertSame(self::DISCARDED_UNSEEN, self::answered($answer));
        self::assertStringNotContainsString('The store', $answer->body);
    }

    #[DataProvider('faults')]
    public function test_the_ledger_holds_the_run_id_after_the_failure_exactly_when_its_write_was_committed(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
        bool $entered,
    ): void {
        $this->sceneOn($backend, $scene);

        $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertSame($entered, array_key_exists($this->runId, $this->ledger()));
    }

    #[DataProvider('faults')]
    public function test_the_run_is_gone_after_the_failure_exactly_when_its_removal_was_committed(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
        bool $entered,
        bool $held,
    ): void {
        $this->sceneOn($backend, $scene);
        $before = $this->storedRun();

        $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertSame($held ? $before : null, $this->storedRun());
    }

    /**
     * @param array{int, array<string, mixed>} $resent
     */
    #[DataProvider('faults')]
    public function test_the_abandon_sent_again_is_answered_200_or_409_stale_run_when_the_first_copy_left_no_run_under_its_entry(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
        string $struck,
        bool $entered,
        bool $held,
        array $resent,
    ): void {
        $this->sceneOn($backend, $scene);
        $this->abandonOver(StoreFault::at($mode, $nth));

        $answer = $this->abandonOver(StoreFault::none());

        self::assertSame($resent, self::answered($answer));
    }

    #[DataProvider('faults')]
    public function test_once_the_abandon_was_sent_again_no_run_is_left_and_its_run_id_is_in_the_ledger(
        string $backend,
        string $scene,
        FaultMode $mode,
        int $nth,
    ): void {
        $this->sceneOn($backend, $scene);
        $this->abandonOver(StoreFault::at($mode, $nth));

        $this->abandonOver(StoreFault::none());

        self::assertNull($this->storedRun());
        self::assertArrayHasKey($this->runId, $this->ledger());
    }

    #[DataProvider('faultsPastTheLastOperation')]
    public function test_the_rows_name_every_operation_an_abandon_asks_of_the_store(string $backend, string $scene, FaultMode $mode, int $nth): void
    {
        $this->sceneOn($backend, $scene);

        $answer = $this->abandonOver(StoreFault::at($mode, $nth));

        self::assertNull($this->fault->struck());
        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_an_endpoint_that_was_given_no_persistence_answers_an_abandon_503_unavailable_with_a_retry_after(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $executions = $bench->executions;
        $unconfigured = (new NeuronCloud(TestBench::API_KEY, $bench->platform->signingKey))
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        $answer = $bench->platform->deliver($unconfigured, $this->abandon(self::RUN_ID));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
    }

    public function test_a_workflow_id_too_long_for_a_database_store_is_answered_503_unavailable_after_its_run_id_was_entered_in_the_ledger(): void
    {
        $this->ordersOn(TestBench::SQLITE);
        // 200 characters, as Neuron's rule allows, in 400 bytes: DatabasePersistence refuses the partition of the run.
        $longerThanThePartitionColumn = str_repeat('é', 200);

        $answer = $this->deliver(['workflowId' => $longerThanThePartitionColumn] + $this->abandon(self::RUN_ID));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertArrayHasKey(self::RUN_ID, $this->ledger($longerThanThePartitionColumn));
    }

    /**
     * One row per operation of the store a fault can hit, in the order an abandon asks for them.
     *
     * @return array<string, array<int, mixed>> the backend, the scene, how the store fails and at which operation,
     *                                          that operation, whether the ledger then holds the run ID, whether
     *                                          the run is still held, and what the abandon sent again is answered
     */
    public static function faults(): array
    {
        return [...self::faultsWhereTheAbandonNeedsTheStore(), ...self::faultsAtTheFirstLookAtTheRun(), ...self::faultsAtTheReadForTheAnswer()];
    }

    /**
     * @return array<string, array<int, mixed>> the faults at the ledger and at what Neuron asks to discard a run
     */
    public static function faultsWhereTheAbandonNeedsTheStore(): array
    {
        $ledger = self::LEDGER_PARTITION_PREFIX . hash('sha256', self::WORKFLOW_ID);
        $run = self::WORKFLOW_ID;
        $failed = FaultMode::BeforeOperation;
        $lostTheAnswer = FaultMode::AfterWrite;

        return self::onEachBackend([
            'no run: the ledger cannot be read' => [
                self::NO_RUN, $failed, 1, "get({$ledger}: starts)", false, false, self::DISCARDED,
            ],
            'no run: the ledger cannot be written' => [
                self::NO_RUN, $failed, 2, "initializeIfAbsent({$ledger}: starts)", false, false, self::DISCARDED,
            ],
            'no run: the ledger is written and the answer is lost' => [
                self::NO_RUN, $lostTheAnswer, 1, "initializeIfAbsent({$ledger}: starts)", true, false, self::ENTRY_WAS_THERE,
            ],
            'a run: the ledger cannot be read' => [
                self::A_RUN, $failed, 1, "get({$ledger}: starts)", false, true, self::DISCARDED,
            ],
            'a run: the ledger cannot be written' => [
                self::A_RUN, $failed, 2, "initializeIfAbsent({$ledger}: starts)", false, true, self::DISCARDED,
            ],
            'a run: the ledger is written and the answer is lost' => [
                self::A_RUN, $lostTheAnswer, 1, "initializeIfAbsent({$ledger}: starts)", true, true, self::DISCARDED,
            ],
            'a run: Neuron cannot read the run it is to discard' => [
                self::A_RUN, $failed, 5, "get({$run}: __control)", true, true, self::DISCARDED,
            ],
            'a run: the run cannot be removed' => [
                self::A_RUN, $failed, 6, "deleteIfUnchanged({$run}: __control)", true, true, self::DISCARDED,
            ],
            'a run: the run is removed and the answer is lost' => [
                self::A_RUN, $lostTheAnswer, 2, "deleteIfUnchanged({$run}: __control)", true, false, self::ENTRY_WAS_THERE,
            ],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the faults at the read that tells whether a run holds the workflow
     *                                          ID: Neuron then says itself whether one does, and discards it
     */
    public static function faultsAtTheFirstLookAtTheRun(): array
    {
        $run = self::WORKFLOW_ID;
        $failed = FaultMode::BeforeOperation;

        return self::onEachBackend([
            'no run: the run cannot be looked for once the ledger is written' => [
                self::NO_RUN, $failed, 3, "get({$run}: __control)", true, false, self::ENTRY_WAS_THERE,
            ],
            'a run: the run cannot be read' => [
                self::A_RUN, $failed, 3, "get({$run}: __control)", true, false, self::ENTRY_WAS_THERE,
            ],
            'a run: the start record of the run cannot be read' => [
                self::A_RUN, $failed, 4, "get({$run}: __ignition)", true, false, self::ENTRY_WAS_THERE,
            ],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the faults at the last read, for the run the answer shows
     */
    public static function faultsAtTheReadForTheAnswer(): array
    {
        $run = self::WORKFLOW_ID;
        $failed = FaultMode::BeforeOperation;

        return self::onEachBackend([
            'no run: the store cannot be read for the answer' => [
                self::NO_RUN, $failed, 4, "get({$run}: __control)", true, false, self::ENTRY_WAS_THERE,
            ],
            'a run: the store cannot be read once the run is removed' => [
                self::A_RUN, $failed, 7, "get({$run}: __control)", true, false, self::ENTRY_WAS_THERE,
            ],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> a fault at the operation after the last one of each scene
     */
    public static function faultsPastTheLastOperation(): array
    {
        return self::onEachBackend([
            'no run: a fifth operation' => [self::NO_RUN, FaultMode::BeforeOperation, 5],
            'no run: a second write' => [self::NO_RUN, FaultMode::AfterWrite, 2],
            'a run: an eighth operation' => [self::A_RUN, FaultMode::BeforeOperation, 8],
            'a run: a third write' => [self::A_RUN, FaultMode::AfterWrite, 3],
        ]);
    }

    /**
     * Set the scene on a store that fails when the fault of the moment says so.
     */
    protected function sceneOn(string $backend, string $scene): void
    {
        $bench = $this->ordersOn($backend);
        $journal = new StoreJournal();
        $this->fault = StoreFault::none();
        $bench->cloud->setPersistence(
            fn (): PersistenceInterface => new FaultyPersistence(($bench->persistence)(), $journal, $this->fault),
        );

        if ($scene === self::NO_RUN) {
            [$this->runId, $this->attempt] = [self::RUN_ID, null];

            return;
        }

        $started = $bench->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run();
        $this->work();
        [$this->runId, $this->attempt] = [(string) $started->getRunId(), 1];
    }

    /**
     * The platform sends the abandon of the scene over a store with one fault: every operation before and
     * after the one it hits is served.
     */
    protected function abandonOver(StoreFault $fault): HttpResponse
    {
        $this->fault = $fault;

        return $this->deliver($this->abandon($this->runId, $this->attempt));
    }
}
