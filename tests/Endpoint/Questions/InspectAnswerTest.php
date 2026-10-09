<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Tests\Support\Questions\Holder;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function array_keys;
use function count;
use function end;
use function is_array;
use function str_ends_with;
use function time;

/**
 * The answer to an inspect, field by field: it shows a run exactly as the
 * report of that run did, carries nothing else of the application, and the
 * question leaves no trace in the store, in the queue or on the platform.
 */
class InspectAnswerTest extends QuestionsTestCase
{
    #[DataProvider('backends')]
    public function test_the_answer_carries_the_run_the_claim_whether_the_snapshot_was_read_and_the_time_and_no_other_field(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->inspect(self::RUN_ID)));

        self::assertEqualsCanonicalizing(['run', 'claimed', 'snapshotRead', 'at'], array_keys(self::fieldsOf($answer)));
    }

    #[DataProvider('backends')]
    public function test_the_answer_about_a_workflow_id_nothing_holds_carries_the_same_fields(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver($this->inspect());

        self::assertEqualsCanonicalizing(['run', 'claimed', 'snapshotRead', 'at'], array_keys(self::fieldsOf($answer)));
    }

    #[DataProvider('backends')]
    public function test_the_time_of_the_answer_is_the_clock_of_the_endpoint_in_unix_seconds(string $backend): void
    {
        $this->benchOn($backend);
        $before = time();

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertGreaterThanOrEqual($before, self::fieldsOf($answer)['at']);
        self::assertLessThanOrEqual(time(), self::fieldsOf($answer)['at']);
    }

    #[DataProvider('holdersAReportShowed')]
    public function test_the_answer_shows_the_run_exactly_as_the_report_of_its_segment_did(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy($holder, fn (): HttpResponse => $this->deliver($this->inspect(self::RUN_ID)));

        $report = $this->lastReportThePlatformRecorded();
        self::assertIsArray($report['run']);
        self::assertSame(
            ['run' => $report['run'], 'claimed' => $report['claimed'], 'snapshotRead' => $report['snapshotRead']],
            self::saidBy($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_the_answer_shows_a_turn_run_in_the_request_exactly_as_the_report_of_its_observe_did(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();
        $report = $this->lastReportThePlatformRecorded();

        $answer = $this->deliver($this->aboutTheThread($this->inspect($report['runId'])));

        self::assertIsArray($report['run']);
        self::assertFalse($report['claimed'], 'A start run in the calling process is never entered in the ledger.');
        self::assertSame(
            ['run' => $report['run'], 'claimed' => $report['claimed'], 'snapshotRead' => $report['snapshotRead']],
            self::saidBy($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_the_wait_of_an_approval_carries_its_number_type_event_name_and_time_to_wake_and_no_other_field(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();

        $answer = $this->deliver($this->aboutTheThread($this->inspect()));

        self::assertEqualsCanonicalizing(
            ['interruptId', 'type', 'eventName', 'wakeAt'],
            array_keys(self::fieldsOf($answer)['run']['wait']),
        );
    }

    #[DataProvider('whatAnApprovalHolds')]
    public function test_the_answer_about_an_approval_carries_nothing_of_its_message_its_tool_or_the_inputs_of_the_tool(string $backend, string $kept): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();

        $answer = $this->deliver($this->aboutTheThread($this->inspect()));

        self::assertStringContainsString($kept, $this->approvalAsTheStoreHoldsIt(), 'The approval request holds it.');
        self::assertStringNotContainsString($kept, $answer->body);
    }

    #[DataProvider('everyHolderOnBackends')]
    public function test_an_inspect_writes_nothing_to_the_store(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $operations = $this->whileHeldBy($holder, function (): array {
            $operations = $this->watchTheStore();
            $this->deliver($this->inspect());
            $this->deliver($this->inspect($this->storedRun()['runId']));

            return $operations->getArrayCopy();
        });

        self::assertNotSame([], $operations);
        self::assertSame([], array_filter($operations, static fn (array $operation): bool => $operation[0] !== 'get'));
    }

    #[DataProvider('backends')]
    public function test_an_inspect_that_names_a_run_id_reads_the_ledger_once(string $backend): void
    {
        $this->benchOn($backend);

        $operations = $this->whileHeldBy(Holder::WaitingForAnEvent, function (): array {
            $operations = $this->watchTheStore();
            $this->deliver($this->inspect(self::RUN_ID));

            return $operations->getArrayCopy();
        });

        self::assertSame(['get'], self::askedOfALedger($operations));
    }

    #[DataProvider('backends')]
    public function test_an_inspect_that_names_no_run_id_does_not_read_the_ledger(string $backend): void
    {
        $this->benchOn($backend);

        $operations = $this->whileHeldBy(Holder::WaitingForAnEvent, function (): array {
            $operations = $this->watchTheStore();
            $this->deliver($this->inspect());

            return $operations->getArrayCopy();
        });

        self::assertNotSame([], $operations);
        self::assertSame([], self::askedOfALedger($operations));
    }

    #[DataProvider('holdersNoLeaseHasToPassFor')]
    public function test_an_inspect_puts_nothing_on_the_queue(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $queued = $this->whileHeldBy($holder, function (): array {
            $this->deliver($this->inspect());
            $this->deliver($this->inspect($this->storedRun()['runId']));

            return $this->work();
        });

        self::assertSame([], $queued);
    }

    #[DataProvider('holdersNoLeaseHasToPassFor')]
    public function test_an_inspect_sends_the_platform_nothing(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        $sent = $this->whileHeldBy($holder, function (): int {
            $before = count($this->bench->platform->received());
            $this->deliver($this->inspect());
            $this->deliver($this->inspect($this->storedRun()['runId']));

            return count($this->bench->platform->received()) - $before;
        });

        self::assertSame(0, $sent);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function holdersAReportShowed(): array
    {
        return self::onBackends(Holder::WaitingForAnEvent, Holder::Sleeping, Holder::Failed);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function everyHolderOnBackends(): array
    {
        return self::onBackends(...Holder::cases());
    }

    /**
     * Every holder but the run whose lease has passed: only the clock tells it from one that executes, and each
     * of its rows waits a second for that lease.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function holdersNoLeaseHasToPassFor(): array
    {
        return self::onBackends(...array_filter(Holder::cases(), static fn (Holder $holder): bool => $holder !== Holder::Dead));
    }

    /**
     * @return array<string, mixed> the body of the last report the platform recorded
     */
    protected function lastReportThePlatformRecorded(): array
    {
        $reports = array_filter(
            $this->bench->platform->received(),
            static fn (HttpRequest $request): bool => str_ends_with($request->uri, '/v1/reports') && is_array($request->body),
        );
        $last = end($reports);

        self::assertNotFalse($last, 'The platform recorded no report.');

        return (array) $last->body;
    }

}
