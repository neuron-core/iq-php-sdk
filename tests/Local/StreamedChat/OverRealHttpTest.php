<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\StreamedChat;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Support\InspectablePlatform;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Broadcast;
use NeuronCore\Cloud\Tests\Support\StreamedChat\ChatEndpoint;
use NeuronCore\Cloud\Tests\Support\StreamedChat\ChatHome;
use NeuronCore\Cloud\Tests\Support\StreamedChat\ChatServer;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Notices;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function intdiv;
use function is_array;
use function json_decode;
use function parse_url;
use function strlen;
use function substr;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * The streamed turn of example (b) of section 5 of the design over real HTTP:
 * the endpoint on PHP's built-in server, backed by SQLite and a file queue,
 * and curl as the browser. It pins what the paragraph "With events()" of 9.6
 * and row 15 of section 7 say of a connection that is cut, as PHP really
 * behaves:
 *
 * - a client that hangs up makes PHP end the request without running any
 *   finally block, so the SDK queues no observe. PHP still destroys Neuron's
 *   segment, whose destructor commits "failed": the run is failed, with no
 *   lease, and the announcement is alone in the queue;
 * - only a request that dies of a fatal error leaves what 9.6 describes: a
 *   run that is "running" under its lease, found when that lease has passed.
 *
 * In both cases a wake finishes the turn in a worker, through the agent's own
 * hooks.
 */
class OverRealHttpTest extends TestCase
{
    protected const THREAD_ID = 'thread-over-http';

    protected const QUESTION = 'Where is my order?';

    /** Microseconds the model waits between two chunks when a test needs time to act: the answer then takes four seconds. */
    protected const SLOW = 200_000;

    protected ChatHome $home;

    protected ChatServer $server;

    /** The platform as the worker of the test process reaches it. */
    protected InspectablePlatform $platform;

    /** Where the agent's own channel delivers in the worker of the test process. */
    protected Broadcast $broadcast;

    /** The NeuronCloud of the worker: the test process, on the store and the queue of the server. */
    protected NeuronCloud $worker;

    protected function setUp(): void
    {
        $missing = ChatServer::unavailable();
        if ($missing !== null) {
            self::markTestSkipped("The chat server cannot be run here: {$missing}.");
        }

        $this->home = ChatHome::temporary();
        $this->server = new ChatServer($this->home);
        $this->platform = new InspectablePlatform();
        $this->broadcast = new Broadcast();
        $this->worker = $this->home->application(
            new FakeAIProvider(new AssistantMessage(ChatServer::ANSWER)),
            $this->platform,
            $this->broadcast,
        );

        // The first request of a server loads its classes: no test measures that one.
        $this->awaitTheServer();
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }

        if (isset($this->home)) {
            $this->home->remove();
        }
    }

    protected function assertPostConditions(): void
    {
        foreach ($this->platform->received() as $request) {
            ProtocolSchema::assertValid(
                parse_url($request->uri, PHP_URL_PATH) === '/v1/reports' ? 'report' : 'announcement',
                is_array($request->body) ? $request->body : [],
            );
        }

        self::assertSame('', file_get_contents($this->home->sentByTheServer->path), 'A request of the server called the platform.');
    }

    // ----------------------------------------------------------------
    // A client that reads the whole stream
    // ----------------------------------------------------------------

    public function test_a_turn_streamed_over_http_reaches_the_client_as_an_event_stream_to_its_last_frame(): void
    {
        $answer = $this->server->post($this->question());

        $events = self::eventsOf($answer['body']);
        self::assertSame(200, $answer['status']);
        self::assertSame(['RUN_STARTED', 'RUN_FINISHED'], [$events[0]['type'], $events[count($events) - 1]['type']]);
        self::assertSame(ChatServer::ANSWER, self::textOf($events));
    }

    public function test_the_request_served_over_http_leaves_its_two_notices_in_the_file_queue(): void
    {
        $this->server->post($this->question());

        self::assertSame(['announce of the start', 'observe of the start: completed'], Notices::describe($this->home->queue->take()));
    }

    public function test_the_worker_posts_the_notices_of_the_request_and_the_platform_records_the_turn_as_completed(): void
    {
        $this->server->post($this->question());

        $this->work();

        $this->platform->assertAnnounced(self::THREAD_ID, Verb::Start);
        $this->platform->assertReported(self::THREAD_ID, Outcome::Completed);
        self::assertNull($this->home->run(self::THREAD_ID));
    }

    public function test_a_refusal_over_http_is_a_status_and_an_empty_body(): void
    {
        $answer = $this->server->post(['threadId' => self::THREAD_ID, 'runId' => 'run_1', 'decisions' => ['call_1' => 'approve']]);

        self::assertSame(['status' => 400, 'body' => ''], $answer);
    }

    // ----------------------------------------------------------------
    // A client that closes the connection after the first bytes
    // ----------------------------------------------------------------

    public function test_a_client_that_hangs_up_has_received_the_beginning_of_the_stream_and_not_its_end(): void
    {
        $answer = $this->server->post($this->question(), ['pause' => self::SLOW], hangUpAfter: 1);

        $types = array_map(static fn (array $event): string => $event['type'], self::eventsOf($answer['body']));
        self::assertSame(200, $answer['status']);
        self::assertContains('TEXT_MESSAGE_CONTENT', $types);
        self::assertNotContains('RUN_FINISHED', $types);
    }

    public function test_a_real_connection_abort_leaves_the_run_failed_at_its_first_attempt_with_no_lease(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        $run = $this->home->run(self::THREAD_ID);

        self::assertInstanceOf(WorkflowRunSnapshot::class, $run);
        self::assertSame([WorkflowStatus::Failed, 1, null], [$run->status, $run->executionAttempt, $run->leaseExpiresAt]);
    }

    public function test_a_real_connection_abort_leaves_the_announcement_in_the_queue_and_no_observe(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        self::assertSame(['announce of the start'], Notices::describe($this->home->queue->take()));
    }

    public function test_a_real_connection_abort_leaves_nothing_of_the_turn_in_the_chat_history(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        self::assertSame([], $this->home->transcripts->of(self::THREAD_ID));
    }

    public function test_the_platform_learns_of_the_aborted_turn_only_that_a_segment_started_under_a_lease(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        $this->work();

        self::assertSame(
            [['/v1/announcements', 'start', $this->home->run(self::THREAD_ID)?->runId, 600]],
            array_map(
                static fn (HttpRequest $request): array => [
                    parse_url($request->uri, PHP_URL_PATH),
                    $request->body['verb'],
                    $request->body['runId'],
                    $request->body['leaseSeconds'],
                ],
                $this->platform->received(),
            ),
        );
    }

    public function test_a_wake_delivered_after_the_abort_finishes_the_turn_at_its_second_attempt(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        $report = $this->wake();

        self::assertSame([Outcome::Completed, 2, null, true], [$report->outcome, $report->executedAttempt, $report->run, $report->snapshotRead]);
        self::assertNull($this->home->run(self::THREAD_ID));
        self::assertSame(['user: ' . self::QUESTION, 'assistant: ' . ChatServer::ANSWER], $this->home->transcripts->of(self::THREAD_ID));
    }

    public function test_the_wake_after_the_abort_delivers_the_answer_through_the_channel_of_the_agent(): void
    {
        $this->aClientHangsUpInTheMiddleOfTheAnswer();

        $this->wake();

        self::assertSame(ChatServer::ANSWER, self::textOf(self::eventsOf(implode('', $this->broadcast->framesOf(self::THREAD_ID)))));
        self::assertSame(['completed'], $this->broadcast->endingsOf(self::THREAD_ID));
    }

    public function test_a_server_that_ignores_the_abort_finishes_the_turn_and_queues_its_observe(): void
    {
        $this->server->post($this->question(), ['pause' => intdiv(self::SLOW, 2), 'ignore_abort' => 1], hangUpAfter: 1);
        $this->awaitTheServer();

        self::assertSame(['announce of the start', 'observe of the start: completed'], Notices::describe($this->home->queue->take()));
        self::assertNull($this->home->run(self::THREAD_ID));
        self::assertSame(['user: ' . self::QUESTION, 'assistant: ' . ChatServer::ANSWER], $this->home->transcripts->of(self::THREAD_ID));
    }

    // ----------------------------------------------------------------
    // A request that dies of a fatal error
    // ----------------------------------------------------------------

    public function test_a_request_that_dies_of_a_fatal_error_leaves_the_run_running_under_its_lease(): void
    {
        $this->server->post($this->question(), ['lease' => 2, 'dies_after' => 3]);
        $this->awaitTheServer();

        $run = $this->home->run(self::THREAD_ID);

        self::assertInstanceOf(WorkflowRunSnapshot::class, $run);
        self::assertSame([WorkflowStatus::Running, 1], [$run->status, $run->executionAttempt]);
        self::assertEqualsWithDelta(time() + 2, $run->leaseExpiresAt, 2.0);
    }

    public function test_a_request_that_dies_of_a_fatal_error_leaves_the_announcement_in_the_queue_and_no_observe(): void
    {
        $this->server->post($this->question(), ['lease' => 2, 'dies_after' => 3]);
        $this->awaitTheServer();

        self::assertSame(['announce of the start'], Notices::describe($this->home->queue->take()));
    }

    public function test_a_wake_finishes_the_run_of_a_request_that_died_once_its_lease_has_passed(): void
    {
        $this->server->post($this->question(), ['lease' => 1, 'dies_after' => 3]);
        $this->awaitTheServer();
        $this->awaitTheEndOfTheLease();

        $report = $this->wake();

        self::assertSame([Outcome::Completed, 2, null], [$report->outcome, $report->executedAttempt, $report->run]);
        self::assertSame(['user: ' . self::QUESTION, 'assistant: ' . ChatServer::ANSWER], $this->home->transcripts->of(self::THREAD_ID));
    }

    /**
     * @return array{threadId: string, runId: string, message: string}
     */
    protected function question(): array
    {
        return ['threadId' => self::THREAD_ID, 'runId' => 'run_1', 'message' => self::QUESTION];
    }

    /**
     * curl closes the connection one second into an answer that takes four,
     * and the server has ended the request by the time this returns.
     */
    protected function aClientHangsUpInTheMiddleOfTheAnswer(): void
    {
        $answer = $this->server->post($this->question(), ['pause' => self::SLOW], hangUpAfter: 1);
        $this->awaitTheServer();

        self::assertStringContainsString('TEXT_MESSAGE_CONTENT', $answer['body'], 'The client hung up before the answer began.');
        self::assertStringNotContainsString('RUN_FINISHED', $answer['body'], 'The client received the whole answer.');
    }

    /**
     * The built-in server serves one request at a time. An answer to this
     * one, which the endpoint refuses before it writes anything, means the
     * script of the request before has ended, its shutdown included.
     */
    protected function awaitTheServer(): void
    {
        $answer = $this->server->post(['threadId' => 'thread-nobody-started', 'runId' => 'run_0', 'decisions' => ['call_0' => 'approve']]);

        self::assertSame(400, $answer['status']);
    }

    protected function awaitTheEndOfTheLease(): void
    {
        $deadline = (int) $this->home->run(self::THREAD_ID)?->leaseExpiresAt;

        while (time() <= $deadline) {
            usleep(100_000);
        }
    }

    /**
     * The queue worker deals with everything that waits in the file queue.
     */
    protected function work(): void
    {
        foreach ($this->home->queue->take() as $message) {
            $this->worker->invoke($message);
        }
    }

    /**
     * What the platform does about a run it finds failed, or running past its
     * lease: a wake fenced with the run ID and the attempt the store shows,
     * here given straight to the worker.
     */
    protected function wake(): Report
    {
        $run = $this->home->run(self::THREAD_ID);
        self::assertInstanceOf(WorkflowRunSnapshot::class, $run);

        return $this->worker->invoke(new Invocation(
            'inv_wake_of_the_platform',
            Verb::Wake,
            Origin::PLATFORM,
            ChatEndpoint::DEFINITION,
            self::THREAD_ID,
            $run->runId,
            $run->executionAttempt,
        ));
    }

    /**
     * @return array<int, array<string, mixed>> the AG-UI events of the whole frames a body holds, in order
     */
    protected static function eventsOf(string $body): array
    {
        // What follows the last blank line is empty, or the piece of a frame a client that hung up was left with.
        return array_map(
            static fn (string $frame): array => json_decode(substr($frame, strlen('data: ')), true, flags: JSON_THROW_ON_ERROR),
            array_slice(explode("\n\n", $body), 0, -1),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected static function textOf(array $events): string
    {
        return implode('', array_map(
            static fn (array $event): string => $event['type'] === 'TEXT_MESSAGE_CONTENT' ? $event['delta'] : '',
            $events,
        ));
    }
}
