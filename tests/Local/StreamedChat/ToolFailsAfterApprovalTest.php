<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\StreamedChat;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Tests\Stub\StreamedChat\PaymentProviderDown;
use NeuronCore\IQ\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\IQ\Tests\Support\StreamedChat\ChatResponse;
use NeuronCore\IQ\Tests\Support\StreamedChat\Confidential;
use NeuronCore\IQ\Tests\Support\StreamedChat\StreamedChatTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function implode;

/**
 * The bullet "After recover()" of example (b) of section 5 of the design. An
 * approved tool throws while the decision is streamed: the stream ends on the
 * adapter's error frame and the run is failed with the decision it had
 * accepted. The recover() of the next request replays that decision, and may
 * end the turn where the agent's own hooks say. A decision the same request
 * carries then finds no wait and is answered 400, although one was applied.
 */
class ToolFailsAfterApprovalTest extends StreamedChatTestCase
{
    protected const QUESTION = 'My order 123 arrived broken. Refund it.';

    protected const ANSWER = 'Order 123 is refunded.';

    protected const NEXT_QUESTION = 'And my invoice?';

    protected const NEXT_ANSWER = 'It is attached.';

    // ----------------------------------------------------------------
    // The request that streams the decision
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_an_approved_tool_that_throws_ends_the_stream_on_the_error_frame_of_the_adapter(string $backend): void
    {
        $this->chatWhoseRefundFails($backend, times: 1);

        $response = $this->approval();
        $received = $response->read();

        self::assertSame(200, $response->status);
        self::assertSame(['RUN_STARTED', 'RUN_ERROR'], self::typesOf($received));
        self::assertStringNotContainsString(Confidential::EXCEPTION, implode('', $received));
    }

    #[DataProvider('backends')]
    public function test_the_run_is_failed_and_keeps_the_decision_it_had_accepted(string $backend): void
    {
        $this->chatWhoseRefundFails($backend, times: 1);

        $this->approval()->read();

        $run = $this->held();
        self::assertSame([WorkflowStatus::Failed, 2, null], [$run?->status, $run?->executionAttempt, $run?->leaseExpiresAt]);
        self::assertInstanceOf(ApprovalRequest::class, $run->interrupt);
    }

    #[DataProvider('backends')]
    public function test_the_observe_of_the_decision_says_failed_with_the_class_of_what_the_tool_threw(string $backend): void
    {
        $this->chatWhoseRefundFails($backend, times: 1);

        $this->approval()->read();

        self::assertSame(['announce of the answer', 'observe of the answer: failed'], $this->notices(after: 2));
        $observe = $this->queue->taken()[3]->body;
        self::assertSame([PaymentProviderDown::class, 2], [$observe['errorClass'], $observe['executedAttempt']]);
    }

    #[DataProvider('backends')]
    public function test_what_the_tool_threw_reaches_the_error_reporting_of_the_application(string $backend): void
    {
        $this->chatWhoseRefundFails($backend, times: 1);

        $this->approval()->read();

        self::assertCount(1, $this->reported);
        self::assertInstanceOf(PaymentProviderDown::class, $this->reported[0]);
    }

    /**
     * The observe does not wait for the browser: it is in the queue when the first frame of the failure is given.
     */
    #[DataProvider('backends')]
    public function test_a_browser_that_leaves_on_the_error_frame_is_reported_as_failed_with_the_class_of_what_the_tool_threw(string $backend): void
    {
        $this->chatWhoseRefundFails($backend, times: 1);
        $this->approval()->readUntil('RUN_ERROR');

        $this->work();

        self::assertSame(['announce of the answer', 'observe of the answer: failed'], $this->notices(after: 2));
        [, $report] = $this->posted(self::REPORTS);
        self::assertSame(
            ['answer', 'failed', PaymentProviderDown::class, 2, 'failed', 2],
            [$report['verb'], $report['outcome'], $report['errorClass'], $report['executedAttempt'], $report['run']['status'], $report['run']['attempt']],
        );
    }

    // ----------------------------------------------------------------
    // The next request carries the decision again
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_the_decision_sent_again_is_answered_400_and_no_frame(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $response = $this->approval('run_3');

        self::assertSame(400, $response->status);
        self::assertInstanceOf(InputTranslationException::class, $response->refusal);
        self::assertSame([], $response->read());
    }

    #[DataProvider('backends')]
    public function test_the_decision_answered_400_was_applied_by_recover_which_ran_the_tool_and_ended_the_turn(string $backend): void
    {
        $bench = $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $this->approval('run_3');

        self::assertSame(2, $bench->executions->count(RefundOrder::class), 'The execution that threw, and the one recover() made.');
        self::assertNull($this->held());
        self::assertSame(
            ['user: ' . self::QUESTION, 'assistant: Let me refund that.', 'tool: Order 123 was refunded. ' . Confidential::TOOL_RESULT, 'assistant: ' . self::ANSWER],
            $this->transcripts->of(self::THREAD_ID),
        );
    }

    #[DataProvider('backends')]
    public function test_the_end_of_the_recovered_turn_goes_through_the_channel_of_the_agent(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $this->approval('run_3');

        $delivered = $this->broadcast->framesOf(self::THREAD_ID);
        self::assertSame(self::ANSWER, self::textOf($delivered));
        self::assertContains('TOOL_CALL_RESULT', self::typesOf($delivered));
        self::assertSame(['completed'], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    #[DataProvider('backends')]
    public function test_the_request_answered_400_queued_the_notices_of_the_recovery_and_none_for_its_own_decision(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $this->approval('run_3');

        self::assertSame(['announce of the wake', 'observe of the wake: completed'], $this->notices(after: 4));
    }

    #[DataProvider('backends')]
    public function test_a_rejection_sent_after_the_failed_approval_does_not_replace_the_approval_the_run_had_accepted(string $backend): void
    {
        $bench = $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $response = $this->decisions([self::CALL_ID => 'reject'], 'run_3');

        self::assertSame(400, $response->status);
        self::assertSame(2, $bench->executions->count(RefundOrder::class), 'recover() replayed the approval: the order was refunded.');
        self::assertNull($this->held());
    }

    // ----------------------------------------------------------------
    // The next request carries a new message
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_a_message_sent_after_the_failed_approval_is_answered_once_recover_has_ended_the_turn(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 1);

        $response = $this->message(self::NEXT_QUESTION, 'run_3');
        $received = $response->read();

        self::assertSame(200, $response->status);
        self::assertSame(self::NEXT_ANSWER, self::textOf($received));
        self::assertSame(self::ANSWER, self::textOf($this->broadcast->framesOf(self::THREAD_ID)));
    }

    // ----------------------------------------------------------------
    // The tool keeps failing
    // ----------------------------------------------------------------

    #[DataProvider('backends')]
    public function test_the_next_request_throws_what_the_tool_throws_while_it_keeps_failing(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 2);

        $thrown = $this->thrownBy(fn (): ChatResponse => $this->message(self::NEXT_QUESTION, 'run_3'));

        self::assertInstanceOf(PaymentProviderDown::class, $thrown, 'recover() throws again: the mapping of the guide has no status for it.');
    }

    #[DataProvider('backends')]
    public function test_the_thread_stays_held_by_the_failed_run_while_the_tool_keeps_failing(string $backend): void
    {
        $this->chatWhoseApprovedRefundFailed($backend, times: 2);

        $this->thrownBy(fn (): ChatResponse => $this->message(self::NEXT_QUESTION, 'run_3'));

        $run = $this->held();
        self::assertSame([WorkflowStatus::Failed, 3], [$run?->status, $run?->executionAttempt]);
        self::assertSame(['announce of the wake', 'observe of the wake: failed'], $this->notices(after: 4));
        self::assertSame(['failed'], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    /**
     * The model asks for the refund and answers once it has its outcome, then
     * answers a next question. The turn waits for the decision of a person.
     *
     * @param int $times how many executions of the refund throw before one refunds the order
     */
    protected function chatWhoseRefundFails(string $backend, int $times): TestBench
    {
        $bench = $this->application(
            $backend,
            [self::asksForTheRefund(), self::says(self::ANSWER, 'msg_answer'), self::says(self::NEXT_ANSWER, 'msg_next')],
            $times,
        );
        $this->message(self::QUESTION)->read();

        return $bench;
    }

    /**
     * The same, after the request that streamed the approval: the tool threw and the run is failed.
     */
    protected function chatWhoseApprovedRefundFailed(string $backend, int $times): TestBench
    {
        $bench = $this->chatWhoseRefundFails($backend, $times);
        $this->approval()->read();

        self::assertSame(WorkflowStatus::Failed, $this->held()?->status);

        return $bench;
    }

    /**
     * @param callable(): mixed $request
     */
    protected function thrownBy(callable $request): ?Throwable
    {
        try {
            $request();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
