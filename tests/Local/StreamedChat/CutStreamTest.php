<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\StreamedChat;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\IQ\Tests\Support\StreamedChat\ChatEndpoint;
use NeuronCore\IQ\Tests\Support\StreamedChat\StreamedChatTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function end;
use function implode;

/**
 * Row 15 of section 7 of the design and the paragraph "With events()" of 9.6:
 * a browser that stops reading. The endpoint's consumer lets go of the
 * stream, Neuron fails a run it was still executing and the SDK hands over
 * one observe that carries no outcome: the worker reports what the store
 * shows. A turn that had already settled, and was only sending its last
 * frames, is observed as suspended or completed, as Neuron reported it. The
 * next request finishes a failed turn with recover(), through the agent's
 * own hooks and not through its response.
 */
class CutStreamTest extends StreamedChatTestCase
{
    protected const FIRST_QUESTION = 'Where is my order?';

    protected const FIRST_ANSWER = 'It ships tomorrow.';

    protected const NEXT_QUESTION = 'And my invoice?';

    protected const NEXT_ANSWER = 'It is attached.';

    // ----------------------------------------------------------------
    // The browser leaves after the first token
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_browser_that_leaves_after_the_first_token_received_the_frames_up_to_it(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);

        $received = $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        self::assertSame(['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT'], self::typesOf($received));
    }

    #[DataProvider('backends')]
    public function test_a_browser_that_leaves_after_the_first_token_fails_the_run(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);

        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $run = $this->held();
        self::assertSame([WorkflowStatus::Failed, 1, null], [$run?->status, $run?->executionAttempt, $run?->leaseExpiresAt]);
    }

    #[DataProvider('backends')]
    public function test_one_observe_is_handed_over_for_the_turn_the_browser_left_and_carries_no_outcome(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);

        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        self::assertSame(['announce of the start', 'observe of the start: no outcome'], $this->notices());
        [$announce, $observe] = $this->queue->taken();
        self::assertSame(['invocationId' => $announce->body['invocationId'], 'verb' => 'start'], $observe->body);
    }

    #[DataProvider('backends')]
    public function test_the_store_already_shows_the_failure_when_the_observe_of_the_cut_turn_reaches_the_queue(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $response = $this->message(self::FIRST_QUESTION);
        $shownToAWorkerThatTakesTheObserveAtOnce = null;
        $this->queue->beforeTheNextMessage(function () use (&$shownToAWorkerThatTakesTheObserveAtOnce): void {
            $shownToAWorkerThatTakesTheObserveAtOnce = $this->held()?->status;
        });

        $response->readUntil('TEXT_MESSAGE_CONTENT');

        self::assertSame(WorkflowStatus::Failed, $shownToAWorkerThatTakesTheObserveAtOnce);
    }

    #[DataProvider('backends')]
    public function test_the_request_the_browser_left_calls_no_platform(string $backend): void
    {
        $bench = $this->chatWhoseFirstReplyIsAskedTwice($backend);

        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        self::assertSame([], $bench->platform->received());
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_the_turn_the_browser_left_as_observed_with_the_failed_run_to_wake(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $this->work();

        [$report] = $this->posted(self::REPORTS);
        self::assertSame(['observed', null, null], [$report['outcome'], $report['errorClass'], $report['executedAttempt']]);
        self::assertSame(
            ['runId' => $this->held()?->runId, 'definition' => ChatEndpoint::DEFINITION, 'status' => 'failed', 'attempt' => 1, 'leaseExpiresAt' => null],
            $report['run'],
        );
    }

    #[DataProvider('backends')]
    public function test_the_next_request_finishes_the_cut_turn_through_the_channel_of_the_agent(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $this->message(self::NEXT_QUESTION, 'run_2')->read();

        self::assertSame(self::FIRST_ANSWER, self::textOf($this->broadcast->framesOf(self::THREAD_ID)));
        self::assertSame(['completed'], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    #[DataProvider('backends')]
    public function test_the_response_of_the_next_request_carries_its_own_turn_and_nothing_of_the_recovered_one(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $received = $this->message(self::NEXT_QUESTION, 'run_2')->read();

        self::assertSame(self::NEXT_ANSWER, self::textOf($received));
        self::assertStringNotContainsString('msg_first', implode('', $received));
        self::assertSame('run_2', self::eventOf($received[0])['runId']);
    }

    #[DataProvider('backends')]
    public function test_the_next_message_is_accepted_and_answered_on_the_conversation_the_recovered_turn_left(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $response = $this->message(self::NEXT_QUESTION, 'run_2');
        $response->read();

        self::assertSame(200, $response->status);
        self::assertSame(
            ['user: ' . self::FIRST_QUESTION, 'assistant: ' . self::FIRST_ANSWER, 'user: ' . self::NEXT_QUESTION, 'assistant: ' . self::NEXT_ANSWER],
            $this->transcripts->of(self::THREAD_ID),
        );
        self::assertSame([self::FIRST_QUESTION, self::FIRST_ANSWER, self::NEXT_QUESTION], $this->lastConversationGivenToTheModel());
        self::assertNull($this->held());
    }

    #[DataProvider('backends')]
    public function test_the_provider_is_called_once_more_for_the_turn_the_browser_left(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $this->message(self::NEXT_QUESTION, 'run_2')->read();

        $this->model->assertCallCount(3);
    }

    #[DataProvider('backends')]
    public function test_the_next_request_queues_the_notices_of_the_recovery_then_those_of_its_own_turn(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');

        $this->message(self::NEXT_QUESTION, 'run_2')->read();

        self::assertSame(
            ['announce of the wake', 'observe of the wake: completed', 'announce of the start', 'observe of the start: completed'],
            $this->notices(after: 2),
        );
    }

    #[DataProvider('backends')]
    public function test_the_recovery_is_announced_with_the_fence_of_the_failed_run_and_executes_its_second_attempt(string $backend): void
    {
        $this->chatWhoseFirstReplyIsAskedTwice($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('TEXT_MESSAGE_CONTENT');
        $failed = $this->held();

        $this->message(self::NEXT_QUESTION, 'run_2')->read();

        [, , $announcement, $observe] = $this->queue->taken();
        self::assertSame([$failed?->runId, 1], [$announcement->body['runId'], $announcement->body['attempt']]);
        self::assertSame(2, $observe->body['executedAttempt']);
    }

    // ----------------------------------------------------------------
    // The browser leaves on the first frame, before the first token
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_browser_that_leaves_on_the_first_frame_fails_the_run_before_the_model_is_called(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::FIRST_QUESTION)->readUntil('RUN_STARTED');

        self::assertSame(WorkflowStatus::Failed, $this->held()?->status);
        self::assertSame(['announce of the start', 'observe of the start: no outcome'], $this->notices());
        $this->model->assertNothingSent();
    }

    #[DataProvider('backends')]
    public function test_the_next_request_answers_the_turn_left_on_its_first_frame_then_its_own(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::FIRST_QUESTION)->readUntil('RUN_STARTED');

        $received = $this->message(self::NEXT_QUESTION, 'run_2')->read();

        self::assertSame(self::NEXT_ANSWER, self::textOf($received));
        self::assertSame(self::FIRST_ANSWER, self::textOf($this->broadcast->framesOf(self::THREAD_ID)));
        $this->model->assertCallCount(2);
    }

    // ----------------------------------------------------------------
    // The turn the browser left asks for an approval when it is finished
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_message_is_answered_409_when_recover_has_brought_the_cut_turn_to_its_approval(string $backend): void
    {
        $this->chatWhoseRefundIsAskedTwice($backend);
        $this->message('Refund my order 123.')->readUntil('TEXT_MESSAGE_CONTENT');

        $response = $this->message(self::NEXT_QUESTION, 'run_2');

        self::assertSame(409, $response->status);
        self::assertInstanceOf(RunInFlightException::class, $response->refusal);
        self::assertSame(WorkflowStatus::Suspended, $response->refusal->status);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_approval_of_the_recovered_turn_is_asked_on_the_channel_of_the_agent_and_in_no_response(string $backend): void
    {
        $this->chatWhoseRefundIsAskedTwice($backend);
        $this->message('Refund my order 123.')->readUntil('TEXT_MESSAGE_CONTENT');

        $this->message(self::NEXT_QUESTION, 'run_2');

        $delivered = $this->broadcast->framesOf(self::THREAD_ID);
        $last = self::eventOf((string) end($delivered));
        self::assertSame(['RUN_FINISHED', 'interrupt', self::CALL_ID], [$last['type'], $last['outcome']['type'], $last['outcome']['interrupts'][0]['id']]);
        self::assertSame(['suspended'], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    #[DataProvider('backends')]
    public function test_the_approval_the_channel_asked_for_is_taken_by_the_next_request(string $backend): void
    {
        $bench = $this->chatWhoseRefundIsAskedTwice($backend);
        $this->message('Refund my order 123.')->readUntil('TEXT_MESSAGE_CONTENT');
        $this->message(self::NEXT_QUESTION, 'run_2');

        $response = $this->approval('run_3');

        self::assertSame(200, $response->status);
        self::assertSame('Order 123 is refunded.', self::textOf($response->read()));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    // ----------------------------------------------------------------
    // The browser leaves while the decision is streamed, after the tool ran
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_decision_stream_cut_after_the_tool_ran_leaves_the_run_failed_at_its_second_attempt(string $backend): void
    {
        $bench = $this->chatWhoseAnswerAfterTheRefundIsAskedTwice($backend);
        $this->message('Refund my order 123.')->read();

        $this->approval()->readUntil('TEXT_MESSAGE_CONTENT');

        self::assertSame([WorkflowStatus::Failed, 2], [$this->held()?->status, $this->held()?->executionAttempt]);
        self::assertSame(['announce of the answer', 'observe of the answer: no outcome'], $this->notices(after: 2));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
    }

    #[DataProvider('backends')]
    public function test_the_next_request_finishes_the_cut_decision_without_running_the_tool_again(string $backend): void
    {
        $bench = $this->chatWhoseAnswerAfterTheRefundIsAskedTwice($backend);
        $this->message('Refund my order 123.')->read();
        $this->approval()->readUntil('TEXT_MESSAGE_CONTENT');

        $response = $this->message(self::NEXT_QUESTION, 'run_3');
        $received = $response->read();

        self::assertSame([200, self::NEXT_ANSWER], [$response->status, self::textOf($received)]);
        self::assertSame('Order 123 is refunded.', self::textOf($this->broadcast->framesOf(self::THREAD_ID)));
        self::assertSame(1, $bench->executions->count(RefundOrder::class), 'The step of the tool was committed: it is replayed, not run.');
    }

    // ----------------------------------------------------------------
    // The browser leaves on a frame of the approval
    // ----------------------------------------------------------------

    #[DataProvider('framesOfTheApproval')]
    public function test_a_browser_that_leaves_on_a_frame_of_the_approval_leaves_the_run_suspended(string $backend, string $frame): void
    {
        $this->chatWithARefund($backend);

        $this->message('Refund my order 123.')->readUntil($frame);

        $run = $this->held();
        self::assertSame([WorkflowStatus::Suspended, 1], [$run?->status, $run?->executionAttempt]);
        self::assertInstanceOf(ApprovalRequest::class, $run->interrupt);
    }

    #[DataProvider('framesOfTheApproval')]
    public function test_one_observe_says_that_the_turn_cut_on_its_approval_suspended(string $backend, string $frame): void
    {
        $this->chatWithARefund($backend);

        $this->message('Refund my order 123.')->readUntil($frame);

        self::assertSame(['announce of the start', 'observe of the start: suspended'], $this->notices());
        $observe = $this->queue->taken()[1]->body;
        self::assertSame([null, 1], [$observe['errorClass'], $observe['executedAttempt']]);
    }

    #[DataProvider('framesOfTheApproval')]
    public function test_the_approval_of_a_turn_cut_on_its_approval_can_still_be_submitted(string $backend, string $frame): void
    {
        $bench = $this->chatWithARefund($backend);
        $this->message('Refund my order 123.')->readUntil($frame);

        $response = $this->approval();
        $received = $response->read();

        self::assertSame(200, $response->status);
        self::assertSame('Order 123 is refunded.', self::textOf($received));
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
        self::assertNull($this->held());
    }

    #[DataProvider('framesOfTheApproval')]
    public function test_nothing_is_recovered_before_the_approval_of_a_turn_cut_on_its_approval(string $backend, string $frame): void
    {
        $this->chatWithARefund($backend);
        $this->message('Refund my order 123.')->readUntil($frame);

        $this->approval()->read();

        self::assertSame(['announce of the answer', 'observe of the answer: completed'], $this->notices(after: 2));
        self::assertSame([], $this->broadcast->endingsOf(self::THREAD_ID));
        $this->model->assertCallCount(2);
    }

    // ----------------------------------------------------------------
    // The browser leaves on a closing frame of a turn that had ended
    // ----------------------------------------------------------------

    #[DataProvider('closingFrames')]
    public function test_a_browser_that_leaves_on_a_closing_frame_of_an_ended_turn_is_observed_as_completed(string $backend, string $frame): void
    {
        $this->chat($backend);

        $this->message(self::FIRST_QUESTION)->readUntil($frame);

        self::assertSame(['announce of the start', 'observe of the start: completed'], $this->notices());
        self::assertNull($this->held());
        self::assertSame(['user: ' . self::FIRST_QUESTION, 'assistant: ' . self::FIRST_ANSWER], $this->transcripts->of(self::THREAD_ID));
    }

    #[DataProvider('closingFrames')]
    public function test_the_next_message_is_answered_with_nothing_to_recover_after_a_cut_on_a_closing_frame(string $backend, string $frame): void
    {
        $this->chat($backend);
        $this->message(self::FIRST_QUESTION)->readUntil($frame);

        $received = $this->message(self::NEXT_QUESTION, 'run_2')->read();

        self::assertSame(self::NEXT_ANSWER, self::textOf($received));
        self::assertSame(['announce of the start', 'observe of the start: completed'], $this->notices(after: 2));
        $this->model->assertCallCount(2);
    }

    /**
     * The frames AG-UI closes a suspended segment with. Neuron yields them
     * after it has committed the suspension: the last one carries the approval.
     *
     * @return array<string, array{string, string}>
     */
    public static function framesOfTheApproval(): array
    {
        return self::onBothBackends(['TEXT_MESSAGE_END', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED']);
    }

    /**
     * The frames AG-UI closes a completed segment with. Neuron yields them after it has freed the thread.
     *
     * @return array<string, array{string, string}>
     */
    public static function closingFrames(): array
    {
        return self::onBothBackends(['TEXT_MESSAGE_END', 'RUN_FINISHED']);
    }

    /**
     * The model has a plain answer to each question.
     */
    protected function chat(string $backend): TestBench
    {
        return $this->application($backend, [self::says(self::FIRST_ANSWER, 'msg_first'), self::says(self::NEXT_ANSWER, 'msg_next')]);
    }

    /**
     * The same, for a first turn that will be cut: the model gives its replies
     * in order, and a turn that is finished later asks for its reply again.
     */
    protected function chatWhoseFirstReplyIsAskedTwice(string $backend): TestBench
    {
        return $this->application($backend, [
            self::says(self::FIRST_ANSWER, 'msg_first'),
            self::says(self::FIRST_ANSWER, 'msg_first'),
            self::says(self::NEXT_ANSWER, 'msg_next'),
        ]);
    }

    protected function chatWithARefund(string $backend): TestBench
    {
        return $this->application($backend, [self::asksForTheRefund(), self::says('Order 123 is refunded.', 'msg_answer')]);
    }

    /**
     * The same, for a turn that is cut on its first token: the model is asked for the refund twice.
     */
    protected function chatWhoseRefundIsAskedTwice(string $backend): TestBench
    {
        return $this->application($backend, [
            self::asksForTheRefund(),
            self::asksForTheRefund(),
            self::says('Order 123 is refunded.', 'msg_answer'),
        ]);
    }

    /**
     * The same, for a decision whose stream is cut on the answer that follows the refund, then a next question.
     */
    protected function chatWhoseAnswerAfterTheRefundIsAskedTwice(string $backend): TestBench
    {
        return $this->application($backend, [
            self::asksForTheRefund(),
            self::says('Order 123 is refunded.', 'msg_answer'),
            self::says('Order 123 is refunded.', 'msg_answer'),
            self::says(self::NEXT_ANSWER, 'msg_next'),
        ]);
    }

    /**
     * @return array<int, string|null> the text of every message of the last request the model received
     */
    protected function lastConversationGivenToTheModel(): array
    {
        $requests = $this->model->getRecorded();
        $last = end($requests);

        return $last instanceof RequestRecord
            ? array_map(static fn (Message $message): ?string => $message->getContent(), $last->messages)
            : [];
    }

    /**
     * @param string[] $frames the types of AG-UI events
     * @return array<string, array{string, string}>
     */
    protected static function onBothBackends(array $frames): array
    {
        $rows = [];

        foreach (TestBench::backends() as $label => [$backend]) {
            foreach ($frames as $frame) {
                $rows["{$frame} on {$label}"] = [$backend, $frame];
            }
        }

        return $rows;
    }
}
