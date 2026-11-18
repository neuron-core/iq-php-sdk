<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\StreamedChat;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\IQ\Tests\Support\StreamedChat\ChatEndpoint;
use NeuronCore\IQ\Tests\Support\StreamedChat\StreamedChatTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;
use function end;
use function time;

/**
 * The refusals of example (b) of section 5 of the design. Each is decided by
 * the first valid() of the endpoint or before it, so it is an HTTP status and
 * no frame: 409 for a thread that is busy or a continuation that lost a race,
 * 400 for a decision nothing waits for, 503 when the queue is down or when
 * this process lacks the version of the run.
 */
class RefusedBeforeTheFirstFrameTest extends StreamedChatTestCase
{
    protected const QUESTION = 'My order 123 arrived broken. Refund it.';

    protected const ANSWER = 'Order 123 is refunded.';

    // ----------------------------------------------------------------
    // A second message while a turn is suspended on its approval
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_message_sent_while_an_approval_is_pending_is_answered_409_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);

        $response = $this->message('And order 124?', 'run_2');

        self::assertSame(409, $response->status);
        self::assertInstanceOf(RunInFlightException::class, $response->refusal);
        self::assertSame(WorkflowStatus::Suspended, $response->refusal->status);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_refused_message_leaves_its_announcement_and_an_observe_that_says_refused_in_the_queue(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);

        $this->message('And order 124?', 'run_2');

        self::assertSame(['announce of the start', 'observe of the start: refused (run_in_flight)'], $this->notices(after: 2));
    }

    #[DataProvider('backends')]
    public function test_the_refused_message_leaves_the_pending_approval_and_the_conversation_as_they_were(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        $pending = $this->held();
        $conversation = $this->transcripts->of(self::THREAD_ID);

        $this->message('And order 124?', 'run_2');

        self::assertEquals($pending, $this->held());
        self::assertSame($conversation, $this->transcripts->of(self::THREAD_ID));
        $this->model->assertCallCount(1);
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_the_refused_message_with_the_run_that_holds_the_thread(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        $this->message('And order 124?', 'run_2');

        $this->work();

        $reports = $this->posted(self::REPORTS);
        $report = end($reports);
        self::assertSame(['start', 'refused', 'run_in_flight', null], [$report['verb'], $report['outcome'], $report['code'], $report['executedAttempt']]);
        self::assertSame([$this->held()?->runId, 'suspended'], [$report['run']['runId'], $report['run']['status']]);
        self::assertNotSame($report['run']['runId'], $report['runId'], 'The refused start had a run ID of its own.');
    }

    // ----------------------------------------------------------------
    // A second message while another response still streams the turn
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_message_sent_while_another_response_streams_the_turn_is_answered_409_and_no_frame(string $backend): void
    {
        $this->chat($backend);
        $streaming = $this->message(self::QUESTION);
        self::assertSame(200, $streaming->status);

        $response = $this->message('And order 124?', 'run_2');

        self::assertSame(409, $response->status);
        self::assertInstanceOf(RunInFlightException::class, $response->refusal);
        self::assertSame(WorkflowStatus::Running, $response->refusal->status);
        self::assertGreaterThan(time(), $response->refusal->leaseExpiresAt, 'The lease tells the browser when to try again.');
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_response_that_streams_the_turn_goes_on_whatever_the_second_message_was_answered(string $backend): void
    {
        $this->chat($backend);
        $streaming = $this->message(self::QUESTION);
        $this->message('And order 124?', 'run_2');

        $received = $streaming->read();

        self::assertSame('Let me refund that.', self::textOf($received));
        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);
        $this->model->assertCallCount(1);
    }

    // ----------------------------------------------------------------
    // A continuation with no pending approval
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_decision_sent_to_a_thread_that_no_run_holds_is_answered_400_and_no_frame(string $backend): void
    {
        $this->chat($backend);

        $response = $this->approval();

        self::assertSame(400, $response->status);
        self::assertInstanceOf(InputTranslationException::class, $response->refusal);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_a_decision_sent_to_a_thread_that_no_run_holds_leaves_the_queue_and_the_store_empty(string $backend): void
    {
        $this->chat($backend);

        $this->approval();

        self::assertSame([], $this->queue->taken());
        self::assertNull($this->held());
        $this->model->assertNothingSent();
    }

    #[DataProvider('backends')]
    public function test_a_decision_on_a_call_that_is_not_pending_is_answered_400_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);

        $response = $this->decisions(['call_of_another_turn' => 'approve']);

        self::assertSame(400, $response->status);
        self::assertInstanceOf(InputTranslationException::class, $response->refusal);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_a_decision_on_a_call_that_is_not_pending_queues_nothing_and_leaves_the_approval_pending(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $pending = $this->held();

        $this->decisions(['call_of_another_turn' => 'approve']);

        self::assertSame([], $this->notices(after: 2));
        self::assertEquals($pending, $this->held());
        self::assertSame(0, $bench->executions->count(RefundOrder::class));
    }

    // ----------------------------------------------------------------
    // An approval sent twice
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_an_approval_sent_again_after_the_turn_ended_is_answered_400_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        $this->approval()->read();

        $response = $this->approval('run_3');

        self::assertSame(400, $response->status);
        self::assertInstanceOf(InputTranslationException::class, $response->refusal);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_an_approval_sent_again_after_the_turn_ended_queues_nothing_and_does_not_run_the_tool_again(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $this->approval()->read();
        $queued = count($this->queue->taken());

        $this->approval('run_3');

        self::assertCount($queued, $this->queue->taken());
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
        self::assertNull($this->held());
    }

    #[DataProvider('backends')]
    public function test_an_approval_sent_again_while_the_first_one_streams_is_answered_400_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        $streaming = $this->approval();
        self::assertSame(200, $streaming->status);

        $response = $this->approval('run_3');

        self::assertSame(400, $response->status);
        self::assertInstanceOf(InputTranslationException::class, $response->refusal);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_approval_that_streams_ends_the_turn_whatever_its_copy_was_answered(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $streaming = $this->approval();
        $this->approval('run_3');

        $received = $streaming->read();

        self::assertSame(self::ANSWER, self::textOf($received));
        self::assertSame(['announce of the answer', 'observe of the answer: completed'], $this->notices(after: 2));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    #[DataProvider('backends')]
    public function test_an_approval_overtaken_by_its_copy_which_ended_the_turn_is_answered_409_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        // Both requests read the pending approval; the copy is served to its end before this one is admitted.
        $this->queue->beforeTheNextMessage(function (): void {
            $this->approval('run_3')->read();
        });

        $response = $this->approval();

        self::assertSame(409, $response->status);
        self::assertInstanceOf(WorkflowRefusedException::class, $response->refusal);
        self::assertSame(RefusalReason::StaleRun, $response->refusal->reason);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_an_approval_overtaken_by_its_copy_which_still_streams_is_answered_409_and_no_frame(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        // Both requests read the pending approval; the copy is admitted first and has not finished.
        $streaming = null;
        $this->queue->beforeTheNextMessage(function () use (&$streaming): void {
            $streaming = $this->approval('run_3');
        });

        $response = $this->approval();

        self::assertSame(409, $response->status);
        self::assertInstanceOf(WorkflowRefusedException::class, $response->refusal);
        self::assertSame(RefusalReason::StaleAttempt, $response->refusal->reason);
        self::assertSame([], $response->read());
        self::assertSame(200, $streaming?->status, 'The copy was admitted and is still held.');
    }

    #[DataProvider('backends')]
    public function test_the_overtaken_approval_is_observed_as_refused_and_the_tool_ran_once(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $this->queue->beforeTheNextMessage(function (): void {
            $this->approval('run_3')->read();
        });

        $this->approval();

        self::assertSame(
            ['announce of the answer', 'observe of the answer: completed', 'announce of the answer', 'observe of the answer: refused (stale_run)'],
            $this->notices(after: 2),
        );
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    // ----------------------------------------------------------------
    // A queue that is down
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_message_is_answered_503_and_no_frame_when_the_queue_is_down(string $backend): void
    {
        $this->chat($backend);
        $this->queue->goDown();

        $response = $this->message(self::QUESTION);

        self::assertSame(503, $response->status);
        self::assertInstanceOf(IQException::class, $response->refusal);
        self::assertSame(Code::DispatchFailed, $response->refusal->reason);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_a_message_refused_for_the_queue_creates_no_run_and_calls_no_model(string $backend): void
    {
        $this->chat($backend);
        $this->queue->goDown();

        $this->message(self::QUESTION);

        self::assertNull($this->held());
        self::assertSame([], $this->queue->taken());
        self::assertSame([], $this->transcripts->of(self::THREAD_ID));
        $this->model->assertNothingSent();
    }

    #[DataProvider('backends')]
    public function test_a_decision_is_answered_503_and_no_frame_when_the_queue_is_down(string $backend): void
    {
        $this->chatSuspendedOnTheApproval($backend);
        $this->queue->goDown();

        $response = $this->approval();

        self::assertSame(503, $response->status);
        self::assertInstanceOf(IQException::class, $response->refusal);
        self::assertSame(Code::DispatchFailed, $response->refusal->reason);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_a_decision_refused_for_the_queue_leaves_the_approval_pending_and_runs_no_tool(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $pending = $this->held();
        $this->queue->goDown();

        $this->approval();

        self::assertEquals($pending, $this->held());
        self::assertSame([], $this->notices(after: 2));
        self::assertSame(0, $bench->executions->count(RefundOrder::class));
    }

    #[DataProvider('backends')]
    public function test_the_decision_refused_for_the_queue_is_taken_once_the_queue_is_back(string $backend): void
    {
        $bench = $this->chatSuspendedOnTheApproval($backend);
        $this->queue->goDown();
        $this->approval();
        $this->queue->comeBack();

        $response = $this->approval('run_3');

        self::assertSame(200, $response->status);
        self::assertSame(self::ANSWER, self::textOf($response->read()));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    // ----------------------------------------------------------------
    // A process that lacks the version of the run
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_decision_is_answered_503_and_no_frame_in_a_process_that_lacks_the_version_of_the_run(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION, process: $this->processOfTheNextRelease())->read();

        $response = $this->approval();

        self::assertSame(503, $response->status);
        self::assertInstanceOf(IQException::class, $response->refusal);
        self::assertSame(Code::UnknownVersion, $response->refusal->reason);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_process_that_lacks_the_version_queues_nothing_and_leaves_the_approval_pending(string $backend): void
    {
        $bench = $this->chat($backend);
        $this->message(self::QUESTION, process: $this->processOfTheNextRelease())->read();
        $pending = $this->held();

        $this->approval();

        self::assertSame([], $this->notices(after: 2));
        self::assertEquals($pending, $this->held());
        self::assertSame(0, $bench->executions->count(RefundOrder::class));
    }

    #[DataProvider('backends')]
    public function test_a_process_that_has_the_version_takes_the_decision_the_other_one_refused(string $backend): void
    {
        $bench = $this->chat($backend);
        $nextRelease = $this->processOfTheNextRelease();
        $this->message(self::QUESTION, process: $nextRelease)->read();
        $this->approval();

        $response = $this->approval('run_3', $nextRelease);

        self::assertSame(200, $response->status);
        self::assertSame(self::ANSWER, self::textOf($response->read()));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    #[DataProvider('backends')]
    public function test_a_message_is_answered_503_and_no_frame_in_a_process_that_lacks_the_version_of_a_failed_turn(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION, process: $this->processOfTheNextRelease())->readUntil('TEXT_MESSAGE_CONTENT');
        $failed = $this->held();

        $response = $this->message('Are you there?', 'run_2');

        self::assertSame(503, $response->status);
        self::assertInstanceOf(IQException::class, $response->refusal);
        self::assertSame(Code::UnknownVersion, $response->refusal->reason);
        self::assertSame([], $response->read());
        self::assertSame(WorkflowStatus::Failed, $failed?->status);
        self::assertEquals($failed, $this->held(), 'recover() refused the run before it touched it.');
    }

    /**
     * The model asks for the refund, then answers once it has the outcome of that call.
     */
    protected function chat(string $backend): TestBench
    {
        return $this->application($backend, [self::asksForTheRefund(), self::says(self::ANSWER, 'msg_answer')]);
    }

    /**
     * A first request streamed the message to its end: the turn waits for the decision of a person.
     */
    protected function chatSuspendedOnTheApproval(string $backend): TestBench
    {
        $bench = $this->chat($backend);
        $this->message(self::QUESTION)->read();

        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);

        return $bench;
    }

    /**
     * A process of the same application after a deploy: it has version 2 of
     * the agent, which the process of the bench does not have yet.
     */
    protected function processOfTheNextRelease(): IQ
    {
        return (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $this->bench->platform))
            ->setPersistence($this->bench->persistence)
            ->setDispatcher($this->queue)
            ->register(ChatEndpoint::DEFINITION, $this->agentFactory())
            ->register(ChatEndpoint::DEFINITION, $this->agentFactory(), version: 2);
    }
}
