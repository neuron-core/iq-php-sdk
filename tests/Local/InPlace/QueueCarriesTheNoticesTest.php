<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\InPlace\Stage;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function count;
use function time;

/**
 * The web-request half of section 9.8 of the design: with a dispatcher set,
 * the process that runs a segment never calls the platform. A worker posts
 * the announcement later, then one report with the store as it is by then.
 */
class QueueCarriesTheNoticesTest extends InPlaceTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_the_calling_process_makes_no_http_request(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertSame(0, $played->requests);
    }

    #[DataProvider('refusals')]
    public function test_the_calling_process_makes_no_http_request_for_a_segment_neuron_refuses(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $played = $this->stage($backend, $kind, $ending)->playOvertaken();

        self::assertSame(0, $played->requests);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_worker_posts_one_announcement_then_one_report(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->play();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($this->requestsSince($received), 0));
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_worker_posts_the_announcement_as_the_calling_process_prepared_it(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $played = $stage->play();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [[, $announcement]] = $this->requestsSince($received);
        self::assertSame($played->announce()->body, $announcement);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_says_how_the_segment_ended_in_the_calling_process(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $played = $stage->play();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertSame(
            [
                'protocol' => 1,
                'invocationId' => $played->announce()->body['invocationId'] ?? null,
                'verb' => $kind->verb()->value,
                'origin' => Origin::APPLICATION,
                'definition' => Stage::DEFINITION,
                'workflowId' => Stage::WORKFLOW_ID,
                'runId' => self::runIdOf($stage, $played),
                'outcome' => self::outcomeOf($ending)->value,
                'code' => null,
                'errorClass' => $ending === Ending::Fails ? NodeFailed::class : null,
                'executedAttempt' => $kind->continuesARun() ? 2 : 1,
                'run' => $report['run'],
                'claimed' => $report['claimed'],
                'snapshotRead' => true,
                'at' => $report['at'],
            ],
            $report,
        );
    }

    #[DataProvider('refusals')]
    public function test_the_report_of_a_segment_neuron_refused_says_so_with_the_reason(
        string $backend,
        Kind $kind,
        Ending $ending,
        RefusalReason $reason,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->playOvertaken();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertSame(
            ['refused', $reason->value, null, null],
            [$report['outcome'], $report['code'], $report['errorClass'], $report['executedAttempt']],
        );
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_shows_the_run_the_store_holds(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->play();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertTrue(StoredRun::same($stage->stored(), $report['run']));
    }

    #[DataProvider('refusals')]
    public function test_the_report_of_a_refused_segment_shows_the_run_that_holds_the_workflow_id(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->playOvertaken();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertTrue(StoredRun::same($stage->stored(), $report['run']));
    }

    #[DataProvider('backends')]
    public function test_the_snapshot_is_the_store_as_it_is_when_the_worker_reads_it_not_as_the_segment_left_it(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, Ending::Waits);
        $stage->play();
        $leftSuspended = $stage->stored();
        $stage->elsewhere()->submitInputs(Stage::ANSWER)->dispatch();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertSame('suspended', $leftSuspended['status'] ?? null);
        self::assertSame(['suspended', null], [$report['outcome'], $report['run']]);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_is_stamped_when_the_worker_posts_it(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->play();
        $received = count($stage->bench->platform->received());
        $from = time();

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertGreaterThanOrEqual($from, $report['at']);
        self::assertLessThanOrEqual(time(), $report['at']);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_only_the_report_of_a_start_says_whether_its_run_id_is_in_the_ledger(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->play();
        $received = count($stage->bench->platform->received());

        $stage->drain();

        [, [, $report]] = $this->requestsSince($received);
        self::assertSame($kind === Kind::Start ? false : null, $report['claimed']);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_worker_executes_no_node_body_for_the_two_notices(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->play();
        $bodiesRun = $stage->bench->executions->total();

        $stage->drain();

        self::assertSame($bodiesRun, $stage->bench->executions->total());
    }
}
