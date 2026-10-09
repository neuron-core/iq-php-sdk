<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use Closure;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\ChatQueue;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\SupportAgent;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use Throwable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function implode;
use function is_array;
use function json_decode;
use function parse_url;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * A chat application that streams its turns in the requests that receive
 * them, as example (b) of section 5 of the design writes it: Neuron's own
 * agent with its fake provider, a tool that waits for a person, a durable chat
 * history and the endpoint of the example, on each backend. Whatever a test
 * leaves in the queue is posted when it ends, and every body the SDK sent must
 * match its schema.
 */
abstract class StreamedChatTestCase extends CloudTestCase
{
    protected const THREAD_ID = 'thread-42';

    protected const CALL_ID = 'call_refund_123';

    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    protected ChatQueue $queue;

    protected FakeAIProvider $model;

    protected Transcripts $transcripts;

    /** What the agent's own channel delivered: the segments that no response streamed. */
    protected Broadcast $broadcast;

    /** @var Throwable[] what failed a stream that had begun, as the endpoint reported it to the application */
    protected array $reported = [];

    /** @var string[] */
    protected array $temporaryFiles = [];

    protected function assertPostConditions(): void
    {
        if (!isset($this->bench)) {
            return;
        }

        self::assertContainsOnlyInstancesOf(Report::class, $this->work(), 'The worker could not post a notice of the flow.');
        $this->bench->assertProtocolRespected();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map(unlink(...), $this->temporaryFiles);
    }

    /**
     * The application on a backend: "support-agent" is registered as a bridge
     * would register it, with a factory that needs nothing of a request.
     *
     * @param Message[] $replies what the model answers, in order; a turn fails once the model has none left
     * @param int $refundFailures how many executions of the refund throw before one refunds the order
     */
    protected function application(string $backend, array $replies, int $refundFailures = 0): TestBench
    {
        $bench = $this->benchOn($backend);

        $this->queue = new ChatQueue();
        $this->model = new FakeAIProvider(...$replies);
        $this->transcripts = new Transcripts($this->temporaryFile());
        $this->broadcast = new Broadcast();

        $bench->cloud
            ->setDispatcher($this->queue)
            ->register(ChatEndpoint::DEFINITION, $this->agentFactory($refundFailures));

        return $bench;
    }

    /**
     * @return Closure(): SupportAgent
     */
    protected function agentFactory(int $refundFailures = 0): Closure
    {
        $model = $this->model;
        $transcripts = $this->transcripts;
        $executions = $this->bench->executions;
        $broadcast = $this->broadcast;

        return static fn (): SupportAgent => new SupportAgent(
            $model,
            $transcripts->store(),
            new RefundOrder($executions, $refundFailures),
            $broadcast,
        );
    }

    /**
     * The model asks for a refund, which waits for a person. A chunk carries
     * the ID of its message, so the replies are given one: two models then
     * stream the same chunks.
     */
    protected static function asksForTheRefund(): ToolCallMessage
    {
        $refund = new ToolCall('refund_order', self::CALL_ID, ['order' => '123', 'note' => 'It arrived broken.']);

        return (new ToolCallMessage('Let me refund that.', [$refund]))->setId('msg_refund');
    }

    protected static function says(string $text, string $messageId): AssistantMessage
    {
        return (new AssistantMessage($text))->setId($messageId);
    }

    /**
     * The request that carries a message of the user.
     *
     * @param NeuronCloud|null $process the process that serves it; null: the one of the bench
     */
    protected function message(string $text, string $runId = 'run_1', ?NeuronCloud $process = null): ChatResponse
    {
        return $this->endpoint($process)(['threadId' => self::THREAD_ID, 'runId' => $runId, 'message' => $text]);
    }

    /**
     * The request that carries the decisions of a person on the pending approvals.
     *
     * @param array<string, mixed> $decisions
     * @param NeuronCloud|null $process the process that serves it; null: the one of the bench
     */
    protected function decisions(array $decisions, string $runId = 'run_2', ?NeuronCloud $process = null): ChatResponse
    {
        return $this->endpoint($process)(['threadId' => self::THREAD_ID, 'runId' => $runId, 'decisions' => $decisions]);
    }

    protected function approval(string $runId = 'run_2', ?NeuronCloud $process = null): ChatResponse
    {
        return $this->decisions([self::CALL_ID => 'approve'], $runId, $process);
    }

    protected function endpoint(?NeuronCloud $process): ChatEndpoint
    {
        return new ChatEndpoint($process ?? $this->bench->cloud, function (Throwable $failure): void {
            $this->reported[] = $failure;
        });
    }

    /**
     * The fake worker deals with everything that waits in the queue.
     *
     * @return array<int, Report|CloudException>
     */
    protected function work(): array
    {
        return $this->queue->runAll($this->bench->cloud);
    }

    /**
     * What the queue was handed: each notice with the segment it speaks for and, for an observe, how that
     * segment ended.
     *
     * @param int $after how many of the first messages to leave out
     * @return string[]
     */
    protected function notices(int $after = 0): array
    {
        return Notices::describe(array_slice($this->queue->taken(), $after));
    }

    /**
     * The run holding the thread, as the store shows it.
     */
    protected function held(): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::THREAD_ID);
    }

    /**
     * @param string $path self::ANNOUNCEMENTS or self::REPORTS
     * @return array<int, array<string, mixed>> the bodies the platform received at a path, in order
     */
    protected function posted(string $path): array
    {
        $bodies = array_map(
            static fn (HttpRequest $request): ?array => parse_url($request->uri, PHP_URL_PATH) === $path && is_array($request->body) ? $request->body : null,
            $this->bench->platform->received(),
        );

        return array_values(array_filter($bodies));
    }

    /**
     * @param string[] $frames
     * @return string[] the type of the AG-UI event of every frame
     */
    protected static function typesOf(array $frames): array
    {
        return array_map(static fn (string $frame): string => self::eventOf($frame)['type'], $frames);
    }

    /**
     * @param string[] $frames
     * @return string the text of the model that the frames carry
     */
    protected static function textOf(array $frames): string
    {
        $deltas = array_map(
            static fn (array $event): string => $event['type'] === 'TEXT_MESSAGE_CONTENT' ? $event['delta'] : '',
            array_map(self::eventOf(...), $frames),
        );

        return implode('', $deltas);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function eventOf(string $frame): array
    {
        return json_decode(substr($frame, strlen('data: ')), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function temporaryFile(): string
    {
        return $this->temporaryFiles[] = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-');
    }
}
