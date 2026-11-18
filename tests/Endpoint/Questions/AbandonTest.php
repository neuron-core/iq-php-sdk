<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Questions\ReleasedWithAnErrorWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Questions\Holder;
use NeuronCore\IQ\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function str_replace;
use function strlen;
use function time;

/**
 * The matrix of the Abandon paragraph: what an abandon is answered, what
 * "run" and "claimed" say in each answer, and what it leaves in the store
 * and in the ledger.
 */
class AbandonTest extends QuestionsTestCase
{
    protected const NEVER_RAN = '1-a-start-that-never-ran';

    #[DataProvider('backends')]
    public function test_with_no_run_and_no_entry_it_is_answered_200_with_no_run_and_claimed_true(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_with_no_run_and_no_entry_it_enters_the_run_id_in_the_ledger_with_a_use_by_time_one_hour_ahead(string $backend): void
    {
        $this->ordersOn($backend);
        $before = time();

        $this->deliver($this->abandon(self::RUN_ID));

        $ledger = $this->ledger();
        self::assertSame([self::RUN_ID], array_keys($ledger));
        self::assertGreaterThanOrEqual($before + 3600, $ledger[self::RUN_ID]);
        self::assertLessThanOrEqual(time() + 3600, $ledger[self::RUN_ID]);
    }

    #[DataProvider('backends')]
    public function test_a_start_with_the_abandoned_run_id_delivered_afterwards_is_rejected_as_already_claimed_and_runs_nothing(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));

        $accepted = $this->deliver($this->start());
        [$report] = $this->work();

        self::assertSame(202, $accepted->statusCode);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value, null], [$report->outcome, $report->code, $report->run]);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_the_attempt_an_abandon_carries_changes_nothing_when_no_run_holds_the_workflow_id(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 3));

        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_with_no_run_and_the_entry_of_a_start_that_was_claimed_and_never_ran_it_is_answered_409_stale_run_with_no_run(
        string $backend,
    ): void {
        $this->ordersOn($backend);
        $this->startThatWasClaimedAndNeverRan();

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_with_no_run_and_the_entry_of_a_run_that_completed_it_is_answered_409_stale_run_with_no_run(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->afterTheFirstSegmentOf(static fn (): Workflow => new CompletingWorkflow($executions));

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_an_abandon_that_found_no_run_is_answered_409_stale_run_with_no_run(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_an_abandon_that_discarded_its_run_is_answered_409_stale_run_with_no_run(string $backend): void
    {
        $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame(self::ENTRY_WAS_THERE, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_an_abandon_leaves_the_ledger_as_the_first_copy_wrote_it(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));
        $written = $this->ledger();

        $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame($written, $this->ledger());
    }

    #[DataProvider('backends')]
    public function test_an_abandon_whose_run_id_is_not_in_the_ledger_reads_it_once_and_writes_it_once(string $backend): void
    {
        $this->ordersOn($backend);
        $operations = $this->watchTheStore();

        $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(['get', 'initializeIfAbsent'], self::askedOfALedger($operations));
    }

    #[DataProvider('backends')]
    public function test_an_abandon_whose_run_id_is_in_the_ledger_reads_it_once_and_writes_nothing_there(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));
        $operations = $this->watchTheStore();

        $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(['get'], self::askedOfALedger($operations));
    }

    #[DataProvider('backends')]
    public function test_an_abandon_adds_its_run_id_to_the_entries_the_ledger_already_holds(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));

        $this->deliver($this->abandon(self::NEVER_RAN));

        self::assertSame([self::RUN_ID, self::NEVER_RAN], array_keys($this->ledger()));
    }

    #[DataProvider('backends')]
    public function test_a_run_the_application_ran_in_its_own_process_and_that_has_ended_is_answered_200_like_a_start_that_never_ran(
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->bench->iq->register(self::DEFINITION, static fn (): Workflow => new CompletingWorkflow($executions));
        $runId = (string) $this->bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run()->getRunId();
        $this->work();

        $answer = $this->deliver($this->abandon($runId, attempt: 1));

        self::assertSame(1, $executions->total());
        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_an_abandon_for_a_definition_this_deployment_lacks_is_answered_404_and_enters_nothing_in_the_ledger(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->abandon(self::RUN_ID));

        self::assertSame([404, ['code' => 'unknown_definition']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $this->ledger());
    }

    #[DataProvider('discardableHoldersOnBackends')]
    public function test_the_right_attempt_discards_the_run_and_is_answered_200_with_no_run_and_claimed_true(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy($holder, fn (): HttpResponse => $this->abandonTheHolder());

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull($this->storedRun());
    }

    #[DataProvider('discardableHoldersOnBackends')]
    public function test_an_abandon_that_carries_no_attempt_discards_the_run_whatever_attempt_it_is_at(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy($holder, fn (): HttpResponse => $this->abandonTheHolder(attemptsAhead: null));

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull($this->storedRun());
    }

    #[DataProvider('everyHolderOnBackends')]
    public function test_a_stale_attempt_is_answered_409_stale_attempt_with_the_run_which_is_left_as_it_was(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        [$held, $answer, $left] = $this->whileHeldBy($holder, function (): array {
            $held = $this->storedRun();

            return [$held, $this->abandonTheHolder(attemptsAhead: 1), $this->storedRun()];
        });

        self::assertSame(
            [409, ['code' => 'stale_attempt', 'run' => $held, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
        self::assertSame($held, $left);
    }

    #[DataProvider('backends')]
    public function test_an_attempt_the_run_has_left_behind_is_stale(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->work();
        $bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->signal(WaitingWorkflow::EVENT)->run();

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        $refusal = self::fieldsOf($answer);
        self::assertSame([409, 'stale_attempt', 2], [$answer->statusCode, $refusal['code'], $refusal['run']['attempt']]);
    }

    #[DataProvider('fencesThatAreNotStale')]
    public function test_a_run_executing_under_a_live_lease_is_answered_409_executing_with_the_run_and_goes_on(string $backend, ?int $attemptsAhead): void
    {
        $this->benchOn($backend);

        [$held, $answer] = $this->whileHeldBy(
            Holder::Executing,
            fn (): array => [$this->storedRun(), $this->abandonTheHolder($attemptsAhead)],
        );

        self::assertSame(
            [409, ['code' => 'executing', 'run' => $held, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
        [$report] = $this->settled;
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Completed, 1], [$report->outcome, $report->executedAttempt], 'The segment in flight was not disturbed.');
    }

    #[DataProvider('fencesThatAreNotStale')]
    public function test_a_completion_retained_by_code_outside_the_sdk_is_answered_409_completed_with_the_run_which_stays(
        string $backend,
        ?int $attemptsAhead,
    ): void {
        $this->benchOn($backend);

        [$held, $answer] = $this->whileHeldBy(
            Holder::Retained,
            fn (): array => [$this->storedRun(), $this->abandonTheHolder($attemptsAhead)],
        );

        self::assertSame(
            [409, ['code' => 'completed', 'run' => $held, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
        self::assertSame($held, $this->storedRun());
    }

    #[DataProvider('backends')]
    public function test_an_abandon_enters_in_the_ledger_the_run_id_of_a_holder_that_was_never_there_also_when_it_is_refused(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $runId = (string) $bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run()->getRunId();
        $this->work();

        $answer = $this->abandonTheHolder(attemptsAhead: 1);

        self::assertSame(409, $answer->statusCode);
        self::assertSame([$runId], array_keys($this->ledger()));
    }

    #[DataProvider('backends')]
    public function test_another_run_holding_the_workflow_id_is_answered_409_stale_run_with_that_run_which_is_left_as_it_was(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->abandon(self::NEVER_RAN)));

        $holder = $this->storedRun();
        self::assertSame(self::RUN_ID, $holder['runId'] ?? null);
        self::assertSame(
            [409, ['code' => 'stale_run', 'run' => $holder, 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_the_attempt_of_the_holder_does_not_let_an_abandon_of_another_run_id_discard_it(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(
            Holder::WaitingForAnEvent,
            fn (): HttpResponse => $this->deliver($this->abandon(self::NEVER_RAN, attempt: 1)),
        );

        self::assertSame([409, 'stale_run'], [$answer->statusCode, self::fieldsOf($answer)['code']]);
        self::assertSame(self::RUN_ID, $this->storedRun()['runId'] ?? null);
    }

    #[DataProvider('backends')]
    public function test_the_run_id_an_abandon_names_can_never_start_also_when_another_run_held_the_workflow_id(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->abandon(self::NEVER_RAN)));

        $this->deliver($this->start(['invocationId' => 'inv_late', 'runId' => self::NEVER_RAN]));
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$report->outcome, $report->code]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('fencesThatAreNotStale')]
    public function test_a_run_whose_start_event_class_no_longer_loads_is_discarded_and_answered_200(string $backend, ?int $attemptsAhead): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->work();
        $this->removeTheClassOfTheStartEvent();

        $answer = $this->deliver($this->abandon(self::RUN_ID, $attemptsAhead === null ? null : 1 + $attemptsAhead));

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull(($this->bench->persistence)()->get(self::WORKFLOW_ID, '__control'));
    }

    #[DataProvider('backends')]
    public function test_a_run_whose_start_event_class_no_longer_loads_is_discarded_also_by_an_abandon_that_names_another_definition(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $executions = $bench->executions;
        $bench->iq->register('nightly-reconciliation', static fn (): Workflow => new CompletingWorkflow($executions));
        $this->deliver($this->start());
        $this->work();
        $this->removeTheClassOfTheStartEvent();

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame(self::DISCARDED, self::answered($answer), 'The tag is kept with the start event: it cannot be read either.');
    }

    #[DataProvider('backends')]
    public function test_a_refused_abandon_of_a_run_that_cannot_be_read_in_full_says_that_no_snapshot_was_read(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->work();
        $this->removeTheClassOfTheStartEvent();

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 7));

        self::assertSame(
            [409, ['code' => 'stale_attempt', 'run' => null, 'claimed' => true, 'snapshotRead' => false]],
            self::answered($answer),
        );
        self::assertNotNull(($this->bench->persistence)()->get(self::WORKFLOW_ID, '__control'), 'The run is left as it was.');
    }

    #[DataProvider('backends')]
    public function test_a_workflow_whose_release_throws_is_answered_503_unavailable_with_a_retry_after_and_nothing_of_what_it_threw(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $this->deliver($this->start());
        $this->work();
        $bench->iq->register(self::DEFINITION, static fn (): Workflow => new ReleasedWithAnErrorWorkflow());

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
        self::assertStringNotContainsString(ReleasedWithAnErrorWorkflow::FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_a_workflow_whose_release_throws_is_answered_503_unavailable_also_when_the_abandon_names_another_definition(
        string $backend,
    ): void {
        $bench = $this->ordersOn($backend);
        $bench->iq->register('nightly-reconciliation', static fn (): Workflow => new ReleasedWithAnErrorWorkflow());
        $this->deliver($this->start());
        $this->work();

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame(self::RUN_ID, $this->storedRun()['runId'] ?? null, 'The run is left as it was.');
    }

    #[DataProvider('backends')]
    public function test_a_completed_abandon_leaves_no_run_for_the_next_inspect_to_show(string $backend): void
    {
        $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(['run' => null, 'claimed' => true, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_the_workflow_id_can_be_started_again_after_a_completed_abandon(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());

        $this->deliver($this->start(['invocationId' => 'inv_again', 'runId' => '1-a-new-run']));
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, '1-a-new-run'], [$report->outcome, $report->run['runId'] ?? null]);
        self::assertSame(2, $bench->executions->count(AwaitPaymentNode::class), 'Once for the abandoned run, once for the new one.');
    }

    #[DataProvider('backends')]
    public function test_the_start_of_the_abandoned_run_sent_again_is_rejected_as_already_claimed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());

        $this->deliver($this->start());
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$report->outcome, $report->code]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    /**
     * A deploy removed the class of the start event of the run that holds the workflow ID. Neuron keeps the
     * event PHP-serialized in the start record of the run, which no longer loads: the run cannot be inspected.
     */
    protected function removeTheClassOfTheStartEvent(): void
    {
        $store = ($this->bench->persistence)();
        $named = static fn (string $class): string => 'O:' . strlen($class) . ':"' . $class . '"';

        $store->writeIfUnchanged(self::WORKFLOW_ID, '__control', (string) $store->get(self::WORKFLOW_ID, '__control'), [
            '__ignition' => str_replace(
                $named(StartEvent::class),
                $named('App\\Events\\RemovedByTheDeploy'),
                (string) $store->get(self::WORKFLOW_ID, '__ignition'),
            ),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function discardableHoldersOnBackends(): array
    {
        return self::onBackends(...Holder::discardable());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function everyHolderOnBackends(): array
    {
        return self::onBackends(...Holder::cases());
    }

    /**
     * @return array<string, array<int, mixed>> the backend, and how far the attempt sent is from the one the run is at
     */
    public static function fencesThatAreNotStale(): array
    {
        return self::onEachBackend(['the right attempt' => [0], 'no attempt' => [null]]);
    }
}
