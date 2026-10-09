<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use DateTimeImmutable;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Runtime\RunProjection;
use NeuronCore\Cloud\Tests\Stub\Units\RefundReview;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The wait projection: what a report shows of the run holding a workflow ID.
 */
class RunProjectionTest extends TestCase
{
    protected const RUN_ID = '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const WAKE_AT = 1791457200;

    public function test_a_run_waiting_for_a_timer_shows_when_to_wake_it(): void
    {
        $timer = (new SleepUntilRequest(new DateTimeImmutable('@' . self::WAKE_AT)))->withId(2);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $timer, attempt: 2));

        self::assertSame([
            'runId' => self::RUN_ID,
            'status' => 'suspended',
            'attempt' => 2,
            'leaseExpiresAt' => null,
            'wait' => ['interruptId' => 2, 'type' => 'sleep_until', 'eventName' => null, 'wakeAt' => self::WAKE_AT],
        ], $run);
    }

    public function test_a_run_waiting_for_an_event_shows_the_name_of_the_event(): void
    {
        $wait = (new WaitForEventRequest('payment.received'))->withId(1);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $wait));

        self::assertSame(
            ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'payment.received', 'wakeAt' => null],
            $run['wait'],
        );
    }

    public function test_a_run_waiting_for_an_event_until_a_deadline_shows_the_deadline_as_the_time_to_wake_it(): void
    {
        $wait = (new WaitForEventRequest('payment.received', new DateTimeImmutable('@' . self::WAKE_AT)))->withId(1);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $wait));

        self::assertSame(
            ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'payment.received', 'wakeAt' => self::WAKE_AT],
            $run['wait'],
        );
    }

    public function test_a_deadline_set_before_1970_is_shown_as_the_instant_0(): void
    {
        $wait = (new WaitForEventRequest('payment.received', new DateTimeImmutable('1969-07-20 20:17:00 UTC')))->withId(1);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $wait));

        self::assertSame(0, $run['wait']['wakeAt']);
    }

    public function test_a_timer_set_before_1970_is_shown_as_the_instant_0(): void
    {
        $timer = (new SleepUntilRequest(new DateTimeImmutable('1969-07-20 20:17:00 UTC')))->withId(2);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $timer));

        self::assertSame(0, $run['wait']['wakeAt']);
    }

    public function test_an_event_name_that_is_not_utf8_is_shown_with_its_invalid_bytes_replaced(): void
    {
        $wait = (new WaitForEventRequest("payment\xFFreceived"))->withId(1);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $wait));

        self::assertSame("payment\u{FFFD}received", $run['wait']['eventName']);
    }

    public function test_a_run_waiting_for_an_approval_shows_an_event_wait_named_approval(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, self::approval()->withId(3)));

        self::assertSame(['runId', 'status', 'attempt', 'leaseExpiresAt', 'wait'], array_keys($run));
        self::assertSame(
            ['interruptId' => 3, 'type' => 'wait_for_event', 'eventName' => 'approval', 'wakeAt' => null],
            $run['wait'],
        );
    }

    public function test_an_approval_that_rejects_itself_at_a_deadline_shows_the_deadline_as_the_time_to_wake_it(): void
    {
        $approval = self::approval(new DateTimeImmutable('@' . self::WAKE_AT))->withId(3);

        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $approval));

        self::assertSame(self::WAKE_AT, $run['wait']['wakeAt']);
    }

    public function test_a_run_waiting_for_tool_results_shows_an_event_wait_named_tool_results(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Suspended, self::toolResults()->withId(4)));

        self::assertSame(
            ['interruptId' => 4, 'type' => 'wait_for_event', 'eventName' => 'tool_results', 'wakeAt' => null],
            $run['wait'],
        );
    }

    /**
     * @param string[] $texts what the request tells a person, all of it in the request's own JSON form
     */
    #[DataProvider('requestsThatCarryText')]
    public function test_no_text_of_a_request_appears_in_the_projection(InterruptRequest $request, array $texts): void
    {
        $ownForm = json_encode($request, JSON_THROW_ON_ERROR);
        $projection = json_encode(RunProjection::of(self::snapshot(WorkflowStatus::Suspended, $request)), JSON_THROW_ON_ERROR);

        foreach ($texts as $text) {
            self::assertStringContainsString($text, $ownForm, 'The test plants a text the request does not carry.');
            self::assertStringNotContainsString($text, $projection);
        }
    }

    public function test_a_running_run_shows_its_lease_deadline_and_no_wait(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Running, leaseExpiresAt: self::WAKE_AT, attempt: 3));

        self::assertSame(
            ['runId' => self::RUN_ID, 'status' => 'running', 'attempt' => 3, 'leaseExpiresAt' => self::WAKE_AT],
            $run,
        );
    }

    public function test_a_run_started_outside_the_sdk_shows_that_it_runs_with_no_lease(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Running));

        self::assertSame(['runId' => self::RUN_ID, 'status' => 'running', 'attempt' => 1, 'leaseExpiresAt' => null], $run);
    }

    public function test_a_failed_run_shows_no_wait(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Failed, attempt: 2));

        self::assertSame(['runId' => self::RUN_ID, 'status' => 'failed', 'attempt' => 2, 'leaseExpiresAt' => null], $run);
    }

    #[DataProvider('statusesThatKeepAnAnsweredWait')]
    public function test_a_wait_still_attached_to_a_run_that_is_not_suspended_is_not_shown(WorkflowStatus $status): void
    {
        $answered = (new WaitForEventRequest('payment.received'))->withId(1);

        $run = RunProjection::of(self::snapshot($status, $answered));

        self::assertArrayNotHasKey('wait', $run);
    }

    public function test_a_completion_retained_outside_the_sdk_shows_as_completed(): void
    {
        $run = RunProjection::of(self::snapshot(WorkflowStatus::Completed));

        self::assertSame(['runId' => self::RUN_ID, 'status' => 'completed', 'attempt' => 1, 'leaseExpiresAt' => null], $run);
    }

    #[DataProvider('runs')]
    public function test_a_projection_is_a_run_of_the_protocol(WorkflowRunSnapshot $snapshot): void
    {
        $answer = ['run' => RunProjection::of($snapshot), 'claimed' => null, 'snapshotRead' => true, 'at' => self::WAKE_AT];

        ProtocolSchema::assertValid('inspect-answer', $answer);
    }

    /**
     * @return array<string, array{InterruptRequest, string[]}>
     */
    public static function requestsThatCarryText(): array
    {
        return [
            'an approval, with its message and its tool inputs' => [
                self::approval()->withId(3),
                ['Refund ACME GmbH above the automatic limit?', 'call_7f3a', 'issue_refund', 'Sends money back', 'Large amount', 'ACME GmbH', 'DE89370400440532013000'],
            ],
            'tool results, with the calls they answer' => [
                self::toolResults()->withId(4),
                ['call_9c1d', 'lookup_customer', 'Reads the CRM', 'jane.doe@example.com'],
            ],
            'a wait an application derives, with its own fields' => [
                (new RefundReview('ACME GmbH', 'Third refund this month'))->withId(5),
                ['ACME GmbH', 'Third refund this month'],
            ],
        ];
    }

    /**
     * @return array<string, array{WorkflowStatus}>
     */
    public static function statusesThatKeepAnAnsweredWait(): array
    {
        return [
            'running, while the answer executes' => [WorkflowStatus::Running],
            'failed, after the answer executed' => [WorkflowStatus::Failed],
        ];
    }

    /**
     * @return array<string, array{WorkflowRunSnapshot}>
     */
    public static function runs(): array
    {
        $timer = (new SleepUntilRequest(new DateTimeImmutable('@' . self::WAKE_AT)))->withId(2);

        return [
            'waiting for a timer' => [self::snapshot(WorkflowStatus::Suspended, $timer)],
            'waiting for an event' => [self::snapshot(WorkflowStatus::Suspended, (new WaitForEventRequest('payment.received'))->withId(1))],
            'waiting for an approval' => [self::snapshot(WorkflowStatus::Suspended, self::approval()->withId(3))],
            'waiting for tool results' => [self::snapshot(WorkflowStatus::Suspended, self::toolResults()->withId(4))],
            'running with a lease' => [self::snapshot(WorkflowStatus::Running, leaseExpiresAt: self::WAKE_AT)],
            'running with no lease' => [self::snapshot(WorkflowStatus::Running)],
            'failed' => [self::snapshot(WorkflowStatus::Failed, attempt: 2)],
            'completed and retained' => [self::snapshot(WorkflowStatus::Completed)],
        ];
    }

    protected static function snapshot(
        WorkflowStatus $status,
        ?InterruptRequest $interrupt = null,
        ?int $leaseExpiresAt = null,
        int $attempt = 1,
    ): WorkflowRunSnapshot {
        return new WorkflowRunSnapshot(self::RUN_ID, $status, $attempt, $interrupt, 'order:123', new StartEvent(), $leaseExpiresAt);
    }

    protected static function approval(?DateTimeImmutable $expiresAt = null): ApprovalRequest
    {
        return new ApprovalRequest(
            'Refund ACME GmbH above the automatic limit?',
            [new Action(
                'call_7f3a',
                'issue_refund',
                'Sends money back',
                reason: 'Large amount',
                inputs: ['customer' => 'ACME GmbH', 'iban' => 'DE89370400440532013000'],
            )],
            $expiresAt,
        );
    }

    protected static function toolResults(): ToolResultsRequest
    {
        return new ToolResultsRequest([
            new ToolCall('lookup_customer', 'call_9c1d', ['email' => 'jane.doe@example.com'], 'Reads the CRM', deferred: true),
        ]);
    }
}
