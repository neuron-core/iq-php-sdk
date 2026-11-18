<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Questions\Holder;
use NeuronCore\IQ\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function hash;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * "claimed" in the answer to an inspect, as the table of report fields
 * defines it: read from the ledger for the run ID the question names, and
 * null when it names none.
 */
class InspectClaimedTest extends QuestionsTestCase
{
    protected const OTHER_RUN_ID = '1-another-run';

    #[DataProvider('backends')]
    public function test_claimed_is_null_for_a_question_that_names_no_run_id_although_the_start_of_the_holder_is_in_the_ledger(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertNull(self::fieldsOf($answer)['claimed']);
        self::assertArrayHasKey(self::RUN_ID, $this->ledger());
    }

    #[DataProvider('backends')]
    public function test_claimed_is_false_for_a_run_id_no_start_ever_claimed(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(['run' => null, 'claimed' => false, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_is_false_while_the_start_still_waits_in_the_queue(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->start());

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(['run' => null, 'claimed' => false, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_for_the_holder_whose_start_the_platform_sent_through_the_queue(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->inspect(self::RUN_ID)));

        $shown = self::fieldsOf($answer);
        self::assertSame([self::RUN_ID, true], [$shown['run']['runId'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_for_the_holder_whose_start_the_application_dispatched_to_its_queue(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $runId = $bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->dispatch();
        $this->work();

        $answer = $this->deliver($this->inspect($runId));

        $shown = self::fieldsOf($answer);
        self::assertSame([$runId, true], [$shown['run']['runId'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_claimed_is_false_for_the_holder_whose_start_ran_in_the_calling_process_although_it_ran(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $runId = (string) $bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->start()->run()->getRunId();
        $this->work();

        $answer = $this->deliver($this->inspect($runId));

        $shown = self::fieldsOf($answer);
        self::assertSame([$runId, 'suspended', false], [$shown['run']['runId'], $shown['run']['status'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_claimed_is_false_for_a_run_id_that_is_not_the_holder_and_was_never_claimed(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(
            Holder::WaitingForAnEvent,
            fn (): HttpResponse => $this->deliver($this->inspect(self::OTHER_RUN_ID)),
        );

        $shown = self::fieldsOf($answer);
        self::assertSame([self::RUN_ID, false], [$shown['run']['runId'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_and_no_run_is_shown_for_a_start_that_was_claimed_and_never_ran(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        $report = $this->startThatWasClaimedAndNeverRan();

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Aborted, Code::NotExecuted->value], [$report->outcome, $report->code]);
        self::assertSame(0, $bench->executions->total());
        self::assertSame(['run' => null, 'claimed' => true, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_for_a_run_that_completed_and_left_no_record_but_its_ledger_entry(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->afterTheFirstSegmentOf(static fn (): Workflow => new CompletingWorkflow($executions));

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(1, $executions->total());
        self::assertSame(['run' => null, 'claimed' => true, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_for_a_run_that_ended_while_another_run_now_holds_the_workflow_id(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->afterTheFirstSegmentOf(static fn (): Workflow => new CompletingWorkflow($executions));
        $this->bench->iq->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));
        $this->deliver($this->start(['invocationId' => 'inv_second', 'runId' => self::OTHER_RUN_ID]));
        $this->work();

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        $shown = self::fieldsOf($answer);
        self::assertSame([self::OTHER_RUN_ID, true], [$shown['run']['runId'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_claimed_is_true_for_a_run_id_an_abandon_entered_in_the_ledger(string $backend): void
    {
        $this->ordersOn($backend);
        $this->deliver($this->abandon(self::RUN_ID));

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(['run' => null, 'claimed' => true, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_is_read_from_the_ledger_of_the_workflow_id_the_question_names(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(
            Holder::WaitingForAnEvent,
            fn (): HttpResponse => $this->deliver(['workflowId' => 'order:456'] + $this->inspect(self::RUN_ID)),
        );

        self::assertSame(['run' => null, 'claimed' => false, 'snapshotRead' => true], self::saidBy($answer));
    }

    #[DataProvider('backends')]
    public function test_claimed_stays_true_for_an_entry_more_than_an_hour_past_its_use_by_time_until_a_claim_drops_it(string $backend): void
    {
        $this->ordersOn($backend);
        $this->enterInTheLedger(self::RUN_ID, useBy: time() - 3700);

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertTrue(self::fieldsOf($answer)['claimed'], 'A question drops nothing from the ledger.');
    }

    #[DataProvider('backends')]
    public function test_claimed_turns_false_once_a_later_claim_dropped_the_entry_of_a_start_that_ran_long_ago(string $backend): void
    {
        $this->ordersOn($backend);
        $this->enterInTheLedger(self::RUN_ID, useBy: time() - 3700);
        $this->deliver($this->abandon(self::OTHER_RUN_ID));

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertFalse(self::fieldsOf($answer)['claimed']);
    }

    /**
     * Write the ledger of the workflow ID as an earlier claim left it.
     */
    protected function enterInTheLedger(string $runId, int $useBy): void
    {
        ($this->bench->persistence)()->initializeIfAbsent(
            self::LEDGER_PARTITION_PREFIX . hash('sha256', self::WORKFLOW_ID),
            'starts',
            json_encode([$runId => $useBy], JSON_THROW_ON_ERROR),
        );
    }
}
