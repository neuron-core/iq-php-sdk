<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use DateTimeImmutable;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Stub\Questions\SleepingWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Questions\Holder;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * An inspect in every state a workflow ID can be in: what "run" says of the
 * run that holds it, whatever run ID the question names.
 */
class InspectStatesTest extends QuestionsTestCase
{
    #[DataProvider('backends')]
    public function test_a_workflow_id_nothing_holds_is_shown_with_no_run(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver($this->inspect());

        self::assertSame(
            [200, ['run' => null, 'claimed' => null, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_a_run_executing_under_a_live_lease_is_shown_running_with_the_deadline_of_its_lease(string $backend): void
    {
        $this->benchOn($backend);
        $before = time();

        $answer = $this->whileHeldBy(Holder::Executing, fn (): HttpResponse => $this->deliver($this->inspect()));

        $run = self::fieldsOf($answer)['run'];
        self::assertSame(
            ['runId' => self::RUN_ID, 'definition' => self::DEFINITION, 'status' => 'running', 'attempt' => 1, 'leaseExpiresAt' => $run['leaseExpiresAt']],
            $run,
        );
        self::assertGreaterThanOrEqual($before + 600, $run['leaseExpiresAt']);
        self::assertLessThanOrEqual(time() + 600, $run['leaseExpiresAt']);
    }

    #[DataProvider('backends')]
    public function test_a_run_whose_lease_has_passed_is_shown_still_running_with_the_deadline_that_passed(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Dead, fn (): HttpResponse => $this->deliver($this->inspect()));

        $shown = self::fieldsOf($answer);
        self::assertSame(
            ['runId' => self::RUN_ID, 'definition' => self::DEFINITION, 'status' => 'running', 'attempt' => 1, 'leaseExpiresAt' => $shown['run']['leaseExpiresAt']],
            $shown['run'],
        );
        self::assertLessThanOrEqual($shown['at'], $shown['run']['leaseExpiresAt'], 'By the clock of the endpoint itself.');
    }

    #[DataProvider('backends')]
    public function test_a_run_started_outside_the_sdk_without_a_lease_is_shown_running_with_no_deadline(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Unleased, fn (): HttpResponse => $this->deliver($this->inspect()));

        $run = self::fieldsOf($answer)['run'];
        self::assertSame(
            ['runId' => $run['runId'], 'definition' => null, 'status' => 'running', 'attempt' => 1, 'leaseExpiresAt' => null],
            $run,
        );
    }

    #[DataProvider('backends')]
    public function test_a_run_that_waits_for_an_event_is_shown_suspended_with_the_name_of_the_event_and_no_time_to_wake(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertSame(
            [
                'runId' => self::RUN_ID,
                'definition' => self::DEFINITION,
                'status' => 'suspended',
                'attempt' => 1,
                'leaseExpiresAt' => null,
                'wait' => ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => WaitingWorkflow::EVENT, 'wakeAt' => null],
            ],
            self::fieldsOf($answer)['run'],
        );
    }

    #[DataProvider('backends')]
    public function test_a_run_that_waits_for_an_event_until_a_deadline_is_shown_with_that_deadline_as_its_time_to_wake(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $untilTheDeadline = static fn (): Workflow => new WaitingWorkflow($executions, new DateTimeImmutable('@' . self::FAR_AHEAD));

        $answer = $this->afterTheFirstSegmentOf($untilTheDeadline, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertSame(
            ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => WaitingWorkflow::EVENT, 'wakeAt' => self::FAR_AHEAD],
            self::fieldsOf($answer)['run']['wait'],
        );
    }

    #[DataProvider('backends')]
    public function test_a_run_that_sleeps_on_a_timer_is_shown_suspended_with_its_time_to_wake_and_no_event_name(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Sleeping, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertSame(
            [
                'runId' => self::RUN_ID,
                'definition' => self::DEFINITION,
                'status' => 'suspended',
                'attempt' => 1,
                'leaseExpiresAt' => null,
                'wait' => ['interruptId' => 1, 'type' => 'sleep_until', 'eventName' => null, 'wakeAt' => self::FAR_AHEAD],
            ],
            self::fieldsOf($answer)['run'],
        );
    }

    #[DataProvider('backends')]
    public function test_a_run_whose_timer_is_due_is_shown_still_suspended_and_is_not_continued_by_the_question(string $backend): void
    {
        $this->benchOn($backend);
        $dueLongAgo = static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('@0'));

        $answer = $this->afterTheFirstSegmentOf($dueLongAgo, fn (): HttpResponse => $this->deliver($this->inspect()));

        $run = self::fieldsOf($answer)['run'];
        self::assertSame(['suspended', 1, 0], [$run['status'], $run['attempt'], $run['wait']['wakeAt']]);
        self::assertSame($run, $this->storedRun(), 'Only a wake continues the run.');
    }

    public function test_a_timer_set_before_1970_is_shown_with_a_time_to_wake_the_protocol_can_carry(): void
    {
        $this->benchOn(TestBench::IN_MEMORY);
        $beforeTheEpoch = static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('1969-07-20 20:17:00 UTC'));

        $answer = $this->afterTheFirstSegmentOf($beforeTheEpoch, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertGreaterThanOrEqual(0, self::fieldsOf($answer)['run']['wait']['wakeAt'], 'An instant of protocol 1 is 0 or more.');
    }

    #[DataProvider('backends')]
    public function test_an_agent_that_waits_for_an_approval_is_shown_suspended_on_the_approval_event(string $backend): void
    {
        $this->benchOn($backend);
        $this->threadWaitingForAnApproval();

        $answer = $this->deliver($this->aboutTheThread($this->inspect()));

        $run = self::fieldsOf($answer)['run'];
        self::assertSame(
            [
                'runId' => $run['runId'],
                'definition' => self::AGENT,
                'status' => 'suspended',
                'attempt' => 1,
                'leaseExpiresAt' => null,
                'wait' => ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'approval', 'wakeAt' => null],
            ],
            $run,
        );
    }

    #[DataProvider('backends')]
    public function test_a_failed_run_is_shown_failed_with_no_wait_and_no_lease(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Failed, fn (): HttpResponse => $this->deliver($this->inspect()));

        self::assertSame(
            ['runId' => self::RUN_ID, 'definition' => self::DEFINITION, 'status' => 'failed', 'attempt' => 1, 'leaseExpiresAt' => null],
            self::fieldsOf($answer)['run'],
        );
    }

    #[DataProvider('backends')]
    public function test_a_completion_retained_by_code_outside_the_sdk_is_shown_completed(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::Retained, fn (): HttpResponse => $this->deliver($this->inspect()));

        $run = self::fieldsOf($answer)['run'];
        self::assertSame(
            ['runId' => $run['runId'], 'definition' => null, 'status' => 'completed', 'attempt' => 1, 'leaseExpiresAt' => null],
            $run,
        );
    }

    #[DataProvider('everyHolderOnBackends')]
    public function test_the_run_shown_is_the_one_the_store_holds_whatever_run_id_the_question_names(string $backend, Holder $holder): void
    {
        $this->benchOn($backend);

        [$held, $shown] = $this->whileHeldBy($holder, function (): array {
            $held = $this->storedRun();

            return [$held, [
                'with no run ID' => self::fieldsOf($this->deliver($this->inspect()))['run'],
                'with the run ID of the holder' => self::fieldsOf($this->deliver($this->inspect($held['runId'])))['run'],
                'with the run ID of another run' => self::fieldsOf($this->deliver($this->inspect('1-another-run')))['run'],
            ]];
        });

        self::assertNotNull($held);
        self::assertSame(
            ['with no run ID' => $held, 'with the run ID of the holder' => $held, 'with the run ID of another run' => $held],
            $shown,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function everyHolderOnBackends(): array
    {
        return self::onBackends(...Holder::cases());
    }
}
