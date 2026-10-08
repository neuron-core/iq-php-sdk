<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Faults\StoredRun;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function count;

/**
 * Section 4 of the design: with no dispatcher set, every message runs in
 * place. That is for scripts and tests: the calling process then posts its
 * own notices, a notice that cannot be posted counts as a dispatcher that
 * threw, and dispatch() executes the segment before it returns.
 */
class WithoutDispatcherTest extends InPlaceTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_run_posts_one_announcement_then_one_report_itself(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $received = count($stage->bench->platform->received());

        $stage->play();

        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($this->requestsSince($received), 0));
    }

    #[DataProvider('kinds')]
    public function test_run_has_posted_the_announcement_and_nothing_else_when_the_first_node_body_runs(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $received = count($stage->bench->platform->received());
        $postedByThen = null;
        $stage->midBody->whenReached(function () use ($received, &$postedByThen): void {
            $postedByThen = $this->requestsSince($received);
        });

        $stage->play();

        self::assertSame([self::ANNOUNCEMENTS], array_column((array) $postedByThen, 0));
        self::assertSame($kind->verb()->value, $postedByThen[0][1]['verb'] ?? null);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_run_posts_says_how_the_segment_ended(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $received = count($stage->bench->platform->received());

        $stage->play();

        [[, $announcement], [, $report]] = $this->requestsSince($received);
        self::assertSame(
            [
                $announcement['invocationId'],
                $kind->verb()->value,
                Origin::APPLICATION,
                self::outcomeOf($ending)->value,
                null,
                $ending === Ending::Fails ? NodeFailed::class : null,
                $kind->continuesARun() ? 2 : 1,
                true,
            ],
            [
                $report['invocationId'],
                $report['verb'],
                $report['origin'],
                $report['outcome'],
                $report['code'],
                $report['errorClass'],
                $report['executedAttempt'],
                $report['snapshotRead'],
            ],
        );
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_run_posts_shows_the_run_the_store_holds(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $received = count($stage->bench->platform->received());

        $stage->play();

        [, [, $report]] = $this->requestsSince($received);
        self::assertTrue(StoredRun::same($stage->stored(), $report['run']));
    }

    #[DataProvider('backends')]
    public function test_a_start_run_in_place_without_a_queue_is_not_entered_in_the_ledger_either(string $backend): void
    {
        $stage = $this->stageWithoutQueue($backend, Kind::Start, Ending::Waits);
        $received = count($stage->bench->platform->received());

        $stage->play();

        [, [, $report]] = $this->requestsSince($received);
        self::assertFalse($report['claimed']);
        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('kinds')]
    public function test_a_platform_that_does_not_record_the_announcement_fails_run_as_dispatch_failed(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $stage->bench->platform->failNext();

        $played = $stage->play();

        self::assertInstanceOf(CloudException::class, $played->thrown);
        self::assertSame(Code::DispatchFailed, $played->thrown->reason);
    }

    #[DataProvider('kinds')]
    public function test_the_dispatch_failed_exception_carries_why_the_announcement_was_not_posted(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $stage->bench->platform->failNext();

        $undelivered = $stage->play()->thrown?->getPrevious();

        self::assertInstanceOf(CloudException::class, $undelivered);
        self::assertSame(Code::ReportUndelivered, $undelivered->reason);
    }

    #[DataProvider('kinds')]
    public function test_nothing_runs_and_nothing_is_written_when_the_announcement_cannot_be_posted(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $stage->bench->platform->failNext();
        $writes = $stage->journal->exposedTo(FaultMode::AfterWrite);

        $played = $stage->play();

        self::assertSame([0, [], 1], [$played->bodiesRun, $played->attemptsOpened, $played->requests]);
        self::assertSame($writes, $stage->journal->exposedTo(FaultMode::AfterWrite));
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_a_platform_that_does_not_record_the_report_does_not_hide_the_state(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $stage->midBody->whenReached(static function () use ($stage): void {
            $stage->bench->platform->failNext();
        });

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(self::outcomeOf($ending)->value, $played->returned->getStatus()->value);
    }

    #[DataProvider('kinds')]
    public function test_a_platform_that_does_not_record_the_report_does_not_hide_the_exception_of_the_node(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $failure = new NodeFailed('The node failed while the platform was down.');
        $stage->midBody->whenReached(static function () use ($stage, $failure): never {
            $stage->bench->platform->failNext();

            throw $failure;
        });

        $played = $stage->play();

        self::assertSame($failure, $played->thrown);
    }

    #[DataProvider('preparedKindsAndEndings')]
    public function test_dispatch_executes_the_segment_before_it_returns(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);

        $played = $stage->play($stage->prepare()->dispatch(...));

        self::assertSame(2, $played->bodiesRun);
        self::assertSame([$kind->continuesARun() ? 2 : 1], $played->attemptsOpened);
    }

    #[DataProvider('preparedKindsAndEndings')]
    public function test_dispatch_returns_the_run_id_of_the_run_it_executed_whatever_the_ending(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $received = count($stage->bench->platform->received());

        $played = $stage->play($stage->prepare()->dispatch(...));

        [, [, $report]] = $this->requestsSince($received);
        self::assertNull($played->thrown, 'What a node throws in a dispatched segment goes to the report, not to the caller.');
        self::assertMatchesRegularExpression(self::MINTED_RUN_ID, (string) $played->returned);
        self::assertSame([$played->returned, self::outcomeOf($ending)->value], [$report['runId'], $report['outcome']]);
    }

    #[DataProvider('preparedKindsAndEndings')]
    public function test_dispatch_announces_and_reports_as_a_queue_job_does(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $received = count($stage->bench->platform->received());

        $stage->play($stage->prepare()->dispatch(...));

        $posted = $this->requestsSince($received);
        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($posted, 0));
        self::assertSame($kind === Kind::Start ? true : null, $posted[1][1]['claimed'], 'A dispatched start is claimed in the ledger.');
    }

    #[DataProvider('preparedKinds')]
    public function test_a_dispatch_whose_announcement_cannot_be_posted_fails_as_dispatch_failed_and_runs_nothing(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $held = $stage->stored();
        $stage->bench->platform->failNext(10);

        $played = $stage->play($stage->prepare()->dispatch(...));

        self::assertInstanceOf(CloudException::class, $played->thrown);
        self::assertSame(Code::DispatchFailed, $played->thrown->reason);
        self::assertSame([0, [], $held], [$played->bodiesRun, $played->attemptsOpened, $stage->stored()]);
    }
}
