<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\StreamedChat;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\SupportAgent;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Broadcast;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Confidential;
use NeuronCore\Cloud\Tests\Support\StreamedChat\StreamedChatTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function array_unique;
use function count;
use function end;
use function iterator_to_array;

/**
 * The end of milestone 4 of the design: the chat turn of example (b) of
 * section 5, served by two requests. The first receives the message and
 * streams until the agent asks for a tool that needs an approval; the second
 * receives the decision and streams to the end of the turn. The browser gets
 * what Neuron alone would stream, and the platform hears of both segments
 * through the queue, never from a request.
 */
class TurnWithApprovalTest extends StreamedChatTestCase
{
    protected const QUESTION = 'My order 123 arrived broken. Refund it.';

    protected const ANSWER = 'Order 123 is refunded.';

    #[DataProvider('backends')]
    public function test_the_message_is_answered_200_with_the_headers_of_the_stream_adapter(string $backend): void
    {
        $this->chat($backend);

        $response = $this->message(self::QUESTION);

        self::assertSame(200, $response->status);
        self::assertSame((new AGUIAdapter(self::THREAD_ID))->getHeaders(), $response->headers);
    }

    #[DataProvider('backends')]
    public function test_the_browser_receives_for_the_message_the_frames_neuron_alone_streams(string $backend): void
    {
        $this->chat($backend);
        [$streamedByNeuronAlone] = $this->theTurnStreamedByNeuronAlone();

        $received = $this->message(self::QUESTION)->read();

        self::assertSame($streamedByNeuronAlone, $received);
        self::assertSame('Let me refund that.', self::textOf($received), 'The comparison would hold for two empty streams.');
    }

    #[DataProvider('backends')]
    public function test_the_stream_of_the_message_ends_on_the_approval_of_the_tool_call(string $backend): void
    {
        $bench = $this->chat($backend);

        $received = $this->message(self::QUESTION)->read();

        $last = self::eventOf((string) end($received));
        self::assertSame(['RUN_FINISHED', 'interrupt', self::CALL_ID], [$last['type'], $last['outcome']['type'], $last['outcome']['interrupts'][0]['id']]);
        self::assertSame(WorkflowStatus::Suspended, $this->held()?->status);
        self::assertInstanceOf(ApprovalRequest::class, $this->held()->interrupt);
        self::assertSame(0, $bench->executions->count(RefundOrder::class), 'The tool waits for the decision.');
    }

    #[DataProvider('backends')]
    public function test_the_request_of_the_message_leaves_one_announcement_and_one_observe_in_the_queue(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();

        self::assertSame(['announce of the start', 'observe of the start: suspended'], $this->notices());
    }

    #[DataProvider('backends')]
    public function test_the_two_notices_of_the_message_are_of_the_application_and_name_the_run_that_holds_the_thread(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();

        [$announcement, $observe] = $this->queue->taken();
        self::assertSame([Origin::APPLICATION, Origin::APPLICATION], [$announcement->origin, $observe->origin]);
        self::assertSame([$this->held()?->runId, $this->held()?->runId], [$announcement->runId, $observe->runId]);
    }

    #[DataProvider('backends')]
    public function test_the_observe_of_the_message_speaks_for_the_announced_segment_and_says_it_suspended_at_its_first_attempt(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();

        [$announcement, $observe] = $this->queue->taken();
        self::assertSame([
            'invocationId' => $announcement->body['invocationId'],
            'verb' => 'start',
            'outcome' => 'suspended',
            'code' => null,
            'errorClass' => null,
            'executedAttempt' => 1,
        ], $observe->body);
    }

    #[DataProvider('backends')]
    public function test_the_announcement_is_in_the_queue_before_the_first_frame_and_the_observe_only_after_the_last(string $backend): void
    {
        $this->chat($backend);

        $response = $this->message(self::QUESTION);
        $beforeTheFirstFrame = $this->notices();
        $response->read();

        self::assertSame(['announce of the start'], $beforeTheFirstFrame);
        self::assertSame(['announce of the start', 'observe of the start: suspended'], $this->notices());
    }

    #[DataProvider('backends')]
    public function test_the_decision_is_answered_200_and_the_browser_receives_the_frames_neuron_alone_streams(string $backend): void
    {
        $this->chat($backend);
        [, $streamedByNeuronAlone] = $this->theTurnStreamedByNeuronAlone();
        $this->message(self::QUESTION)->read();

        $response = $this->approval();
        $received = $response->read();

        self::assertSame(200, $response->status);
        self::assertSame($streamedByNeuronAlone, $received);
        self::assertSame(self::ANSWER, self::textOf($received), 'The comparison would hold for two empty streams.');
    }

    #[DataProvider('backends')]
    public function test_the_decision_runs_the_tool_once_and_ends_the_turn(string $backend): void
    {
        $bench = $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $received = $this->approval()->read();

        self::assertSame('RUN_FINISHED', self::typesOf($received)[count($received) - 1]);
        self::assertSame(1, $bench->executions->count(RefundOrder::class));
        self::assertNull($this->held(), 'The turn completed: nothing holds the thread.');
    }

    #[DataProvider('backends')]
    public function test_the_turn_is_kept_in_the_chat_history_of_the_application(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        self::assertSame(
            ['user: ' . self::QUESTION, 'assistant: Let me refund that.', 'tool: Order 123 was refunded. ' . Confidential::TOOL_RESULT, 'assistant: ' . self::ANSWER],
            $this->transcripts->of(self::THREAD_ID),
        );
    }

    #[DataProvider('backends')]
    public function test_the_request_of_the_decision_leaves_one_announcement_and_one_observe_more(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $this->approval()->read();

        self::assertSame(['announce of the answer', 'observe of the answer: completed'], $this->notices(after: 2));
    }

    #[DataProvider('backends')]
    public function test_the_two_notices_of_the_decision_are_of_the_application_and_carry_the_fence_the_request_read(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();
        $suspended = $this->held();

        $this->approval()->read();

        [, , $announcement, $observe] = $this->queue->taken();
        self::assertSame([Origin::APPLICATION, Origin::APPLICATION], [$announcement->origin, $observe->origin]);
        self::assertSame([$suspended?->runId, $suspended?->executionAttempt], [$announcement->body['runId'], $announcement->body['attempt']]);
        self::assertSame([$announcement->body['invocationId'], 2], [$observe->body['invocationId'], $observe->body['executedAttempt']]);
    }

    #[DataProvider('backends')]
    public function test_every_notice_of_the_turn_is_a_queue_message_of_its_own(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        $ids = array_map(static fn (Invocation $notice): string => $notice->invocationId, $this->queue->taken());
        self::assertCount(4, array_unique($ids));
    }

    #[DataProvider('backends')]
    public function test_neither_request_of_the_turn_calls_the_platform(string $backend): void
    {
        $bench = $this->chat($backend);

        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        self::assertSame([], $bench->platform->received());
    }

    #[DataProvider('backends')]
    public function test_neither_request_of_the_turn_reaches_the_channel_of_the_agent(string $backend): void
    {
        $this->chat($backend);

        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        self::assertSame([], $this->broadcast->framesOf(self::THREAD_ID));
        self::assertSame([], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    #[DataProvider('backends')]
    public function test_the_worker_posts_the_two_notices_of_the_message_as_one_announcement_and_one_report(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $this->work();

        self::assertCount(1, $this->posted(self::ANNOUNCEMENTS));
        self::assertCount(1, $this->posted(self::REPORTS));
    }

    #[DataProvider('backends')]
    public function test_the_announcement_the_platform_records_names_the_start_of_the_application_and_its_lease(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $this->work();

        [$announcement] = $this->posted(self::ANNOUNCEMENTS);
        self::assertSame(
            ['start', Origin::APPLICATION, self::THREAD_ID, $this->held()?->runId, null, 600],
            [$announcement['verb'], $announcement['origin'], $announcement['workflowId'], $announcement['runId'], $announcement['attempt'], $announcement['leaseSeconds']],
        );
    }

    #[DataProvider('backends')]
    public function test_the_report_of_the_message_shows_the_run_as_the_store_holds_it(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $this->work();

        $stored = $this->held();
        [$report] = $this->posted(self::REPORTS);
        self::assertSame([
            'runId' => $stored?->runId,
            'status' => $stored?->status->value,
            'attempt' => $stored?->executionAttempt,
            'leaseExpiresAt' => $stored?->leaseExpiresAt,
            'wait' => ['interruptId' => $stored?->interrupt?->getId(), 'type' => 'wait_for_event', 'eventName' => 'approval', 'wakeAt' => null],
        ], $report['run']);
        self::assertSame(['suspended', 1, true], [$report['outcome'], $report['executedAttempt'], $report['snapshotRead']]);
    }

    #[DataProvider('backends')]
    public function test_the_report_of_the_message_says_its_start_was_never_claimed_in_the_ledger(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();

        $this->work();

        self::assertFalse($this->posted(self::REPORTS)[0]['claimed'], 'A start run in the calling process exists in one copy and is not entered.');
    }

    #[DataProvider('backends')]
    public function test_the_report_of_the_decision_says_completed_and_shows_a_thread_that_no_run_holds(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        $this->work();

        [, $report] = $this->posted(self::REPORTS);
        self::assertSame(
            ['answer', Origin::APPLICATION, 'completed', 2, null, true],
            [$report['verb'], $report['origin'], $report['outcome'], $report['executedAttempt'], $report['run'], $report['snapshotRead']],
        );
        self::assertNull($this->held());
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_the_turn_as_a_start_that_suspended_and_an_answer_that_completed(string $backend): void
    {
        $this->chat($backend);
        $this->message(self::QUESTION)->read();
        $this->approval()->read();

        $this->work();

        self::assertSame(
            ['start', 'answer'],
            array_map(static fn (array $announcement): string => $announcement['verb'], $this->posted(self::ANNOUNCEMENTS)),
        );
        self::assertSame(
            ['start: suspended', 'answer: completed'],
            array_map(static fn (array $report): string => "{$report['verb']}: {$report['outcome']}", $this->posted(self::REPORTS)),
        );
    }

    /**
     * The model asks for the refund, then answers once it has the outcome of that call.
     */
    protected function chat(string $backend): TestBench
    {
        return $this->application($backend, [self::asksForTheRefund(), self::says(self::ANSWER, 'msg_answer')]);
    }

    /**
     * The same turn without the SDK: the agent of the application on stores of
     * its own, streamed as the endpoint of the guide streams it.
     *
     * @return array{string[], string[]} the frames of the message, and those of the decision
     */
    protected function theTurnStreamedByNeuronAlone(): array
    {
        $agent = (new SupportAgent(
            new FakeAIProvider(self::asksForTheRefund(), self::says(self::ANSWER, 'msg_answer')),
            new InMemoryMessageStore(),
            new RefundOrder(new ExecutionLog($this->temporaryFile())),
            new Broadcast(),
        ))
            ->for(self::THREAD_ID)
            ->setPersistence(new InMemoryPersistence())
            ->setChannel(static fn (): ?StreamingChannelInterface => null);

        $message = $agent
            ->setStreamAdapter(static fn (): AGUIAdapter => new AGUIAdapter(self::THREAD_ID, 'run_1'))
            ->stream(new UserMessage(self::QUESTION));
        $ofTheMessage = iterator_to_array(SSEEncoder::encode($message), false);

        $decision = $agent
            ->setStreamAdapter(static fn (): AGUIAdapter => new AGUIAdapter(self::THREAD_ID, 'run_2'))
            ->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())
            ->events();

        return [$ofTheMessage, iterator_to_array(SSEEncoder::encode($decision), false)];
    }
}
