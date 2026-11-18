<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\StreamedChat;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tools\ToolCall;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Stub\StreamedChat\PaymentProviderDown;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use NeuronCore\IQ\Tests\Support\StreamedChat\ChatResponse;
use NeuronCore\IQ\Tests\Support\StreamedChat\Confidential;
use NeuronCore\IQ\Tests\Support\StreamedChat\StreamedChatTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_column;
use function array_filter;
use function array_map;
use function array_push;
use function count;
use function implode;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The table "What crosses the wire" of section 1 of the design, for streamed
 * chat turns. One conversation carries a string planted in each thing that
 * must stay in the application: the messages of the user, the tokens of the
 * model, the inputs and the result of a tool, the decision of the approver
 * and the message of an exception. It goes through every ending a streamed
 * segment has: suspended, failed, recovered and completed. Only identifiers,
 * statuses and a class name may reach the platform, in bodies that match
 * their schema.
 */
class NothingConfidentialLeavesTest extends StreamedChatTestCase
{
    protected const APPROVED = 'call_refund_123';

    protected const REJECTED = 'call_refund_124';

    /** @var string[] every frame a browser received in a response */
    protected array $received = [];

    #[DataProvider('backends')]
    public function test_the_conversation_carried_every_planted_string_through_the_application(string $backend): void
    {
        $this->playTheConversation($backend);

        $keptOrShownByTheApplication = implode("\n", [
            ...$this->received,
            ...$this->broadcast->framesOf(self::THREAD_ID),
            ...$this->transcripts->of(self::THREAD_ID),
            ...array_map(static fn (Throwable $failure): string => $failure->getMessage(), $this->reported),
        ]);

        foreach (Confidential::all() as $where => $planted) {
            self::assertStringContainsString($planted, $keptOrShownByTheApplication, "Nothing was planted in {$where}.");
        }
    }

    #[DataProvider('backends')]
    public function test_the_conversation_went_through_every_ending_of_a_streamed_segment(string $backend): void
    {
        $this->playTheConversation($backend);

        self::assertSame([
            'announce of the start',
            'observe of the start: suspended',
            'announce of the answer',
            'observe of the answer: failed',
            'announce of the wake',
            'observe of the wake: completed',
            'announce of the start',
            'observe of the start: completed',
        ], $this->notices());
    }

    #[DataProvider('backends')]
    public function test_nothing_planted_in_the_conversation_is_in_a_request_to_the_platform(string $backend): void
    {
        $bench = $this->playTheConversation($backend);

        $this->work();

        $requests = $bench->platform->received();
        self::assertCount(8, $requests);
        self::assertSame([], self::plantedIn(array_map(
            static fn (HttpRequest $request): string => json_encode([$request->uri, $request->headers, $request->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $requests,
        )));
    }

    #[DataProvider('backends')]
    public function test_nothing_planted_in_the_conversation_is_in_a_message_of_the_queue(string $backend): void
    {
        $this->playTheConversation($backend);

        $messages = array_map(static fn (Invocation $message): array => $message->toArray(), $this->queue->taken());

        self::assertCount(8, $messages);
        self::assertSame([], self::plantedIn(array_map(
            static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $messages,
        )));
    }

    #[DataProvider('backends')]
    public function test_no_message_of_the_queue_carries_an_input_in_an_encoded_form(string $backend): void
    {
        $this->playTheConversation($backend);

        $carriers = array_filter(
            $this->queue->taken(),
            static fn (Invocation $message): bool => $message->event !== null || $message->payload !== null || $message->signal !== null,
        );

        self::assertSame([], $carriers, 'A segment that runs in the request travels in no queue: only its two notices do.');
    }

    #[DataProvider('backends')]
    public function test_the_platform_hears_the_class_of_what_the_tool_threw_and_nothing_else_of_it(string $backend): void
    {
        $this->playTheConversation($backend);

        $this->work();

        self::assertSame([null, PaymentProviderDown::class, null, null], array_column($this->posted(self::REPORTS), 'errorClass'));
    }

    #[DataProvider('backends')]
    public function test_every_body_the_conversation_sent_the_platform_matches_the_schema_of_its_message(string $backend): void
    {
        $this->playTheConversation($backend);

        $this->work();

        $announcements = $this->posted(self::ANNOUNCEMENTS);
        $reports = $this->posted(self::REPORTS);
        self::assertSame([4, 4], [count($announcements), count($reports)]);
        self::assertSame([], array_filter(array_map(
            static fn (array $announcement): array => ProtocolSchema::violations('announcement', $announcement),
            $announcements,
        )));
        self::assertSame([], array_filter(array_map(
            static fn (array $report): array => ProtocolSchema::violations('report', $report),
            $reports,
        )));
    }

    /**
     * Three requests of one thread. The first streams until the approval of
     * two refunds. The second streams the decisions: one refund is approved
     * and throws, the other is rejected with a reason. The third brings a new
     * message: its recover() ends the failed turn where the agent's own hooks
     * say, then the message is streamed to its end.
     */
    protected function playTheConversation(string $backend): TestBench
    {
        $bench = $this->application($backend, [
            self::asksForTwoRefunds(),
            self::says('Order 123 is refunded, order 124 is not. ' . Confidential::TOKENS, 'msg_answer'),
            self::says('You are welcome. ' . Confidential::TOKENS, 'msg_next'),
        ], refundFailures: 1);

        $this->browse($this->message('Refund my orders 123 and 124. ' . Confidential::MESSAGE));
        $this->browse($this->decisions([
            self::APPROVED => 'approve',
            self::REJECTED => ['reject', 'Not that one. ' . Confidential::APPROVAL],
        ]));
        $this->browse($this->message('Thank you. ' . Confidential::MESSAGE, 'run_3'));

        return $bench;
    }

    protected static function asksForTwoRefunds(): ToolCallMessage
    {
        return (new ToolCallMessage('I will refund both. ' . Confidential::TOKENS, [
            new ToolCall('refund_order', self::APPROVED, ['order' => '123', 'note' => Confidential::TOOL_INPUT]),
            new ToolCall('refund_order', self::REJECTED, ['order' => '124', 'note' => Confidential::TOOL_INPUT]),
        ]))->setId('msg_refunds');
    }

    /**
     * A browser reads a response to its end.
     */
    protected function browse(ChatResponse $response): void
    {
        array_push($this->received, ...$response->read());
    }

    /**
     * @param string[] $texts
     * @return string[] one line for every planted string found in a text; empty when none is
     */
    protected static function plantedIn(array $texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            foreach (Confidential::all() as $where => $planted) {
                if (str_contains($text, $planted)) {
                    $found[] = "What was planted in {$where} is in {$text}";
                }
            }
        }

        return $found;
    }
}
