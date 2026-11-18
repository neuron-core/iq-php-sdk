<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function base64_encode;
use function serialize;
use function time;

/**
 * Steps 3 and 5 of the endpoint: a start and a wake become one invocation of
 * origin "platform", built field by field from the fields of the verb. What
 * exists only in the application's queue is never read from the wire. Nothing
 * else happens in the request.
 */
class QueuedInvocationTest extends RequestTestCase
{
    /** What a holder of the signing key could add to make a worker do more than the protocol allows. */
    protected const SMUGGLED = [
        'origin' => 'application',
        'signal' => 'payment.received',
        'payload' => ['amount' => 120, 'approved' => true],
        'body' => ['invocationId' => 'inv_start', 'verb' => 'start', 'outcome' => 'completed'],
        'leaseSeconds' => 1,
        'version' => 9,
        'input' => ['orderId' => 123],
    ];

    #[DataProvider('backends')]
    public function test_a_start_queues_one_invocation_of_the_platform_that_carries_the_fields_of_a_start(string $backend): void
    {
        $this->shop($backend);
        $expiresAt = time() + 900;

        $this->deliver($this->start(['expiresAt' => $expiresAt]));

        self::assertSame([self::invocation('inv_start', 'start', null, $expiresAt)], $this->queued());
    }

    #[DataProvider('backends')]
    public function test_a_wake_queues_one_invocation_of_the_platform_that_carries_the_fields_of_a_wake(string $backend): void
    {
        $this->shop($backend);

        $this->deliver($this->wake(attempt: 3));

        self::assertSame([self::invocation('inv_wake_3', 'wake', 3, null)], $this->queued());
    }

    #[DataProvider('backends')]
    public function test_nothing_a_start_carries_beyond_the_fields_of_a_start_reaches_the_queue(string $backend): void
    {
        $this->shop($backend);
        $expiresAt = time() + 900;
        $event = base64_encode(serialize(['amount' => 120]));

        $answer = $this->deliver($this->start(['expiresAt' => $expiresAt]) + ['attempt' => 7, 'event' => $event] + self::SMUGGLED);

        self::assertSame(202, $answer->statusCode);
        self::assertSame([self::invocation('inv_start', 'start', null, $expiresAt)], $this->queued());
    }

    #[DataProvider('backends')]
    public function test_nothing_a_wake_carries_beyond_the_fields_of_a_wake_reaches_the_queue(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->wake(attempt: 3) + ['expiresAt' => time() + 900, 'event' => 'never-an-event'] + self::SMUGGLED);

        self::assertSame(202, $answer->statusCode);
        self::assertSame([self::invocation('inv_wake_3', 'wake', 3, null)], $this->queued());
    }

    #[DataProvider('backends')]
    public function test_a_start_opens_no_store_builds_no_workflow_and_runs_nothing_in_the_request(string $backend): void
    {
        $this->shop($backend);

        $this->deliver($this->start());

        self::assertSame(
            ['messages queued' => 1, 'stores opened' => 0, 'store operations' => 0, 'workflows built' => 0, 'nodes executed' => 0],
            $this->done(),
        );
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_that_waits_opens_no_store_builds_no_workflow_and_runs_nothing_in_the_request(string $backend): void
    {
        $this->shop($backend);
        $this->deliver($this->start());
        $this->work();
        $before = $this->done();

        $this->deliver($this->wake(attempt: 1));

        self::assertSame(['messages queued' => $before['messages queued'] + 1] + $before, $this->done());
    }

    #[DataProvider('questionsAndAbandon')]
    public function test_a_message_answered_in_the_request_queues_nothing(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['verb' => $verb] + $this->abandon());

        self::assertSame([200, 0], [$answer->statusCode, $this->done()['messages queued']]);
    }

    #[DataProvider('queuedVerbs')]
    public function test_a_start_and_a_wake_are_queued_by_an_endpoint_that_has_no_persistence_yet(string $verb): void
    {
        $this->shop(TestBench::IN_MEMORY);
        $executions = $this->bench->executions;
        $this->iq = (new IQ(TestBench::API_KEY, $this->bench->platform->signingKey))
            ->setDispatcher($this->queue)
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        $answer = $this->deliver($verb === 'start' ? $this->start() : $this->wake(attempt: 1));

        self::assertSame([202, 1], [$answer->statusCode, $this->done()['messages queued']]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function questionsAndAbandon(): array
    {
        return self::onEveryBackend(['an inspect' => ['inspect'], 'an abandon' => ['abandon'], 'a ping' => ['ping']]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function queuedVerbs(): array
    {
        return ['a start' => ['start'], 'a wake' => ['wake']];
    }

    /**
     * @return array<int, array<string, mixed>> every message in the queue, as the queue holds it
     */
    protected function queued(): array
    {
        return array_map(static fn (Invocation $queued): array => $queued->toArray(), $this->queue->dispatched());
    }

    /**
     * @return array<string, mixed> the invocation of a message of the platform about the order, as a queue holds it
     */
    protected static function invocation(string $invocationId, string $verb, ?int $attempt, ?int $expiresAt): array
    {
        return [
            'invocationId' => $invocationId,
            'verb' => $verb,
            'origin' => 'platform',
            'definition' => self::DEFINITION,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'attempt' => $attempt,
            'expiresAt' => $expiresAt,
            'signal' => null,
            'payload' => null,
            'event' => null,
            'body' => null,
        ];
    }
}
