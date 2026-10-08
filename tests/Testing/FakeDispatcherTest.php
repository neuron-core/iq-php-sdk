<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Testing;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Units\Queued;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function time;

class FakeDispatcherTest extends CloudTestCase
{
    protected function setUp(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
    }

    public function test_a_dispatched_message_waits_and_runs_nothing(): void
    {
        $this->register('greeting');

        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        self::assertSame(0, $this->bench->executions->total());
        self::assertSame([], $this->bench->platform->received());
    }

    public function test_assert_dispatched_passes_for_a_verb_that_was_dispatched(): void
    {
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $this->bench->dispatcher->assertDispatched(Verb::Start);
    }

    public function test_assert_dispatched_passes_for_a_verb_that_was_dispatched_for_a_workflow_id(): void
    {
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $this->bench->dispatcher->assertDispatched(Verb::Start, Queued::WORKFLOW_ID);
    }

    public function test_assert_dispatched_still_passes_after_the_message_ran(): void
    {
        $this->register('greeting');
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));
        $this->bench->dispatcher->runAll($this->bench->cloud);

        $this->bench->dispatcher->assertDispatched(Verb::Start, Queued::WORKFLOW_ID);
    }

    public function test_assert_dispatched_fails_when_nothing_was_dispatched(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("No 'start' was dispatched.");

        $this->bench->dispatcher->assertDispatched(Verb::Start);
    }

    public function test_assert_dispatched_fails_for_another_verb(): void
    {
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("No 'wake' was dispatched.");

        $this->bench->dispatcher->assertDispatched(Verb::Wake);
    }

    public function test_assert_dispatched_fails_for_another_workflow_id(): void
    {
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("No 'start' was dispatched for workflow ID 'order:124'.");

        $this->bench->dispatcher->assertDispatched(Verb::Start, 'order:124');
    }

    public function test_run_all_gives_every_queued_message_to_invoke_and_returns_what_it_returned_in_order(): void
    {
        $this->register('greeting');
        $this->bench->dispatcher->dispatch(Queued::start('greeting', workflowId: 'order:1'));
        $this->bench->dispatcher->dispatch(Queued::start('greeting', workflowId: 'order:2'));

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['order:1 completed', 'order:2 completed'], self::told($results));
        self::assertSame(2, $this->bench->executions->count(CompleteNode::class));
    }

    public function test_run_all_leaves_the_queue_empty(): void
    {
        $this->register('greeting');
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));
        $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame([], $this->bench->dispatcher->runAll($this->bench->cloud));
    }

    public function test_run_all_twice_delivers_every_message_a_second_time_right_after_the_first(): void
    {
        $this->register('greeting');
        $this->bench->dispatcher->dispatch(Queued::start('greeting', workflowId: 'order:1'));
        $this->bench->dispatcher->dispatch(Queued::start('greeting', workflowId: 'order:2'));

        $results = $this->bench->dispatcher->runAll($this->bench->cloud, 2);

        self::assertSame(
            ['order:1 completed', 'order:1 rejected', 'order:2 completed', 'order:2 rejected'],
            self::told($results),
        );
    }

    public function test_the_second_delivery_of_a_start_runs_nothing(): void
    {
        $this->register('greeting');
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $this->bench->dispatcher->runAll($this->bench->cloud, 2);

        self::assertSame(1, $this->bench->executions->count(CompleteNode::class));
        $this->bench->platform->assertReported(Queued::WORKFLOW_ID, Outcome::Rejected, Code::AlreadyClaimed->value);
    }

    public function test_a_message_dispatched_while_the_queue_runs_waits_for_the_next_run(): void
    {
        $this->register('greeting');
        $this->bench->platform->failNext(2);
        $this->bench->dispatcher->dispatch(Queued::start('greeting'));

        $segment = $this->bench->dispatcher->runAll($this->bench->cloud);
        $notices = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['order:123 completed'], self::told($segment));
        self::assertCount(2, $notices, 'The announcement and the observe the job queued ran after it, not with it.');
    }

    public function test_a_message_invoke_throws_for_is_returned_as_its_exception(): void
    {
        $this->bench->platform->failNext(1);
        $this->bench->dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['thrown report_undelivered'], self::told($results));
    }

    public function test_a_message_invoke_throws_for_stays_queued_and_is_delivered_by_a_later_run(): void
    {
        $this->bench->platform->failNext(1);
        $this->bench->dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));
        $this->bench->dispatcher->runAll($this->bench->cloud);

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['order:123 observed'], self::told($results));
        $this->bench->platform->assertAnnounced(Queued::WORKFLOW_ID, Verb::Start);
    }

    public function test_a_start_of_the_application_that_was_aborted_stays_queued_and_runs_once_its_cause_is_gone(): void
    {
        $this->register('greeting', version: 2);
        $this->bench->dispatcher->dispatch(Queued::start('greeting', Origin::APPLICATION, runId: '1-0192f3a8'));
        $aborted = $this->bench->dispatcher->runAll($this->bench->cloud);
        $this->register('greeting', version: 1);

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['thrown unknown_version'], self::told($aborted));
        self::assertSame(['order:123 completed'], self::told($results));
    }

    #[DataProvider('finalRefusals')]
    public function test_a_message_the_platform_refused_for_good_does_not_stay_queued(int $status, Code $reason): void
    {
        $this->bench->platform->failNext(1, $status);
        $this->bench->dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));

        $refused = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(["thrown {$reason->value}"], self::told($refused));
        self::assertSame([], $this->bench->dispatcher->runAll($this->bench->cloud));
    }

    public function test_a_message_that_throws_at_both_of_two_deliveries_is_queued_again_once(): void
    {
        $this->bench->platform->failNext(2);
        $this->bench->dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));
        $this->bench->dispatcher->runAll($this->bench->cloud, 2);

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertCount(1, $results);
    }

    public function test_an_aborted_invocation_of_the_application_past_its_use_by_time_does_not_stay_queued(): void
    {
        $spent = new Invocation('inv_start', Verb::Start, Origin::APPLICATION, 'retired', Queued::WORKFLOW_ID, Queued::RUN_ID, expiresAt: time() - 1);
        $this->bench->dispatcher->dispatch($spent);

        $aborted = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['thrown unknown_definition'], self::told($aborted));
        self::assertSame([], $this->bench->dispatcher->runAll($this->bench->cloud));
    }

    public function test_an_undelivered_notice_stays_queued_whatever_its_use_by_time(): void
    {
        $announce = Queued::announcementOf(Queued::start('greeting'));
        $this->bench->platform->failNext(1);
        $this->bench->dispatcher->dispatch(Invocation::fromArray(['expiresAt' => time() - 1] + $announce->toArray()));
        $undelivered = $this->bench->dispatcher->runAll($this->bench->cloud);

        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        self::assertSame(['thrown report_undelivered'], self::told($undelivered));
        self::assertSame(['order:123 observed'], self::told($results));
    }

    public function test_an_answer_whose_payload_json_alone_could_not_hold_is_queued_and_given_to_invoke(): void
    {
        $this->register('greeting');
        $answer = new Invocation('inv_answer', Verb::Answer, Origin::APPLICATION, 'greeting', Queued::WORKFLOW_ID, Queued::RUN_ID, 1, payload: [
            'scan' => "\xB1\x31",
        ]);

        $this->bench->dispatcher->dispatch($answer);
        $results = $this->bench->dispatcher->runAll($this->bench->cloud);

        // No run holds the workflow ID: Neuron answers the fence before it looks at the payload.
        self::assertSame(['order:123 refused'], self::told($results));
    }

    /**
     * @return array<string, array{int, Code}>
     */
    public static function finalRefusals(): array
    {
        return [
            'a 401' => [401, Code::Unauthorized],
            'a 403' => [403, Code::Unauthorized],
            'a 400 invalid_request' => [400, Code::InvalidRequest],
        ];
    }

    /**
     * @param array<int, Report|CloudException> $results
     * @return string[] what every invoke() told, in order: the workflow ID and the outcome of a report, or the reason it threw
     */
    protected static function told(array $results): array
    {
        return array_map(
            static fn (Report|CloudException $result): string => $result instanceof Report
                ? "{$result->workflowId} {$result->outcome->value}"
                : "thrown {$result->reason->value}",
            $results,
        );
    }

    protected function register(string $definition, int $version = 1): void
    {
        $executions = $this->bench->executions;

        $this->bench->cloud->register($definition, static fn (): Workflow => new CompletingWorkflow($executions), $version);
    }
}
