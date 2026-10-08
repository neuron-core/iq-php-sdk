<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * Section 9.2 of the design: a start executed in the calling process exists
 * in one copy and is not entered in the ledger. Nothing in the SDK puts such
 * a start in a queue, so the copies these tests give to a worker are made by
 * hand: they show what stands between that run ID and a second execution
 * (Neuron, while the run holds the workflow ID) and what does not (the
 * ledger, once the run has completed and its records are gone).
 */
class StartOutsideTheLedgerTest extends InPlaceTestCase
{
    #[DataProvider('endingsOfAStart')]
    public function test_a_start_run_in_place_writes_nothing_in_the_ledger(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);

        $stage->play();

        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('endingsOfAStart')]
    public function test_a_start_run_in_place_reads_and_writes_no_record_but_those_of_its_run(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $partitionsReached = [];
        $stage->interceptStore(static function (string $operation, string $partition) use (&$partitionsReached): void {
            $partitionsReached[$partition] = true;
        });

        $stage->play();

        self::assertSame([Stage::WORKFLOW_ID => true], $partitionsReached);
    }

    #[DataProvider('endingsOfAStart')]
    public function test_the_worker_that_posts_its_notices_writes_nothing_in_the_ledger_either(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $stage->play();

        $stage->drain();

        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('endingsThatKeepTheRun')]
    public function test_a_copy_of_the_start_given_to_a_worker_while_its_run_holds_the_workflow_id_executes_nothing(
        string $backend,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $runId = self::runIdOf($stage, $stage->play());

        $copied = $stage->play(static fn (): string => $stage->cloud->invoke(self::copyOfTheStart($runId))->runId);

        self::assertNull($copied->thrown);
        self::assertSame([0, []], [$copied->bodiesRun, $copied->attemptsOpened]);
    }

    #[DataProvider('endingsThatKeepTheRun')]
    public function test_such_a_copy_is_reported_as_refused_by_neuron_because_its_own_run_is_in_flight(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $runId = self::runIdOf($stage, $stage->play());

        $report = $stage->cloud->invoke(self::copyOfTheStart($runId));

        self::assertSame(
            [Outcome::Refused, RefusalReason::RunInFlight->value, null],
            [$report->outcome, $report->code, $report->executedAttempt],
        );
        self::assertSame($runId, $report->run['runId'] ?? null);
    }

    #[DataProvider('endingsThatKeepTheRun')]
    public function test_such_a_copy_leaves_the_run_as_it_was(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $runId = self::runIdOf($stage, $stage->play());
        $held = $stage->stored();

        $stage->cloud->invoke(self::copyOfTheStart($runId));

        self::assertSame($held, $stage->stored());
    }

    #[DataProvider('endingsThatKeepTheRun')]
    public function test_such_a_copy_claims_the_run_id_in_the_ledger(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $runId = self::runIdOf($stage, $stage->play());

        $report = $stage->cloud->invoke(self::copyOfTheStart($runId));

        self::assertTrue($report->claimed);
        self::assertTrue((new Ledger($stage->bench->persistence))->has(Stage::WORKFLOW_ID, $runId));
    }

    #[DataProvider('endingsThatKeepTheRun')]
    public function test_a_later_copy_is_rejected_as_already_claimed_even_once_the_run_is_gone(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $runId = self::runIdOf($stage, $stage->play());
        $stage->cloud->invoke(self::copyOfTheStart($runId));
        (new WorkflowEngine(($stage->bench->persistence)()))->abandon(Stage::WORKFLOW_ID);
        $bodiesRun = $stage->bench->executions->total();

        $report = $stage->cloud->invoke(self::copyOfTheStart($runId));

        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value, null], [$report->outcome, $report->code, $report->run]);
        self::assertSame($bodiesRun, $stage->bench->executions->total());
    }

    /**
     * The limit of "exists in one copy": Neuron has forgotten a run that
     * completed, and the ledger never knew its run ID. What keeps this from
     * happening is that the SDK never queues a start it ran in place, and
     * that a PendingSegment is used once.
     */
    #[DataProvider('backends')]
    public function test_a_copy_of_the_start_given_to_a_worker_after_its_run_completed_in_place_runs_the_workflow_again(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, Ending::Completes);
        $runId = self::runIdOf($stage, $stage->play());
        $bodiesRun = $stage->bench->executions->total();

        $report = $stage->cloud->invoke(self::copyOfTheStart($runId));

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Completed, 1, true], [$report->outcome, $report->executedAttempt, $report->claimed]);
        self::assertSame(2, $stage->bench->executions->total() - $bodiesRun);
    }

    /**
     * @return array<string, array{string, Ending}>
     */
    public static function endingsOfAStart(): array
    {
        return self::onBothBackends([
            'a start that completes the run' => [Ending::Completes],
            'a start that stops at a wait' => [Ending::Waits],
            'a start whose last node throws' => [Ending::Fails],
        ]);
    }

    /**
     * @return array<string, array{string, Ending}>
     */
    public static function endingsThatKeepTheRun(): array
    {
        return self::onBothBackends([
            'a start that stopped at a wait' => [Ending::Waits],
            'a start whose last node threw' => [Ending::Fails],
        ]);
    }

    /**
     * A start of the application as a queue would hold it, under the run ID of a start that was run in place.
     */
    protected static function copyOfTheStart(string $runId): Invocation
    {
        return new Invocation(
            'inv_copy',
            Verb::Start,
            Origin::APPLICATION,
            Stage::DEFINITION,
            Stage::WORKFLOW_ID,
            $runId,
            expiresAt: time() + 900,
        );
    }
}
