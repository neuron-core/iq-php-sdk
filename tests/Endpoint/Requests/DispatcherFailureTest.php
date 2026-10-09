<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use Error;
use ErrorException;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\Requests\FailingQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Step 5 of the endpoint: when the dispatcher throws, whatever it throws, the
 * platform is answered 503 dispatch_failed with a Retry-After, and sends the
 * same message again.
 */
class DispatcherFailureTest extends RequestTestCase
{
    protected const DISPATCH_FAILED = [503, '{"code":"dispatch_failed"}'];

    protected const QUEUE_FAILURE = 'Connection refused [tcp://queue.internal:6379] for user shop-worker';

    #[DataProvider('whatAQueueThrows')]
    public function test_a_start_the_queue_does_not_take_is_answered_503_dispatch_failed(string $backend, Throwable $failure): void
    {
        $this->shop($backend);
        $this->cloud->setDispatcher(new FailingQueue($failure));

        $answer = $this->deliver($this->start());

        self::assertSame(self::DISPATCH_FAILED, self::statusAndBody($answer));
    }

    #[DataProvider('whatAQueueThrows')]
    public function test_a_wake_the_queue_does_not_take_is_answered_503_dispatch_failed(string $backend, Throwable $failure): void
    {
        $this->shop($backend);
        $this->cloud->setDispatcher(new FailingQueue($failure));

        $answer = $this->deliver($this->wake(attempt: 1));

        self::assertSame(self::DISPATCH_FAILED, self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_503_says_in_how_many_seconds_the_platform_may_send_again(string $backend): void
    {
        $this->shop($backend);
        $this->cloud->setDispatcher(new FailingQueue(new RuntimeException(self::QUEUE_FAILURE)));

        $answer = $this->deliver($this->start());

        self::assertMatchesRegularExpression('/^[1-9][0-9]{0,3}$/D', (string) $answer->header('Retry-After'));
    }

    #[DataProvider('backends')]
    public function test_a_start_the_queue_does_not_take_opens_no_store_builds_no_workflow_and_runs_nothing(string $backend): void
    {
        $this->shop($backend);
        $this->cloud->setDispatcher(new FailingQueue(new RuntimeException(self::QUEUE_FAILURE)));

        $this->deliver($this->start());

        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_the_same_start_sent_again_once_the_queue_is_back_is_queued_and_runs_once(string $backend): void
    {
        $bench = $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, 'inv_start');
        $this->cloud->setDispatcher(new FailingQueue(new RuntimeException(self::QUEUE_FAILURE)));
        $this->post($body, $headers);
        $this->cloud->setDispatcher($this->queue);

        $answer = $this->post($body, $headers);
        [$report] = $this->work();

        self::assertSame(202, $answer->statusCode);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, 1], [$report->outcome, $bench->executions->count(AwaitPaymentNode::class)]);
    }

    #[DataProvider('messagesAnsweredInTheRequest')]
    public function test_a_message_answered_in_the_request_is_answered_while_the_queue_is_down(string $backend, string $verb): void
    {
        $this->shop($backend);
        $this->cloud->setDispatcher(new FailingQueue(new RuntimeException(self::QUEUE_FAILURE)));

        $answer = $this->deliver(['verb' => $verb] + $this->abandon());

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_with_no_dispatcher_set_a_start_runs_its_segment_in_the_request_and_is_answered_202(string $backend): void
    {
        $bench = $this->shop($backend);
        $executions = $bench->executions;
        $this->cloud = (new NeuronCloud(TestBench::API_KEY, $bench->platform->signingKey, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($bench->persistence)
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        $answer = $this->deliver($this->start());

        self::assertSame([202, 1], [$answer->statusCode, $executions->count(AwaitPaymentNode::class)]);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Suspended);
    }

    public function test_with_no_dispatcher_and_no_persistence_set_a_start_is_answered_503_dispatch_failed(): void
    {
        $bench = $this->shop(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $this->cloud = (new NeuronCloud(TestBench::API_KEY, $bench->platform->signingKey, TestBench::PLATFORM_URL, $bench->platform))
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        $answer = $this->deliver($this->start());

        self::assertSame(self::DISPATCH_FAILED, self::statusAndBody($answer));
        self::assertSame(0, $executions->total());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function whatAQueueThrows(): array
    {
        return self::onEveryBackend([
            'an exception' => [new RuntimeException(self::QUEUE_FAILURE)],
            'an exception of the SDK' => [new CloudException(self::QUEUE_FAILURE, Code::NotConfigured)],
            'a warning a framework turned into an exception' => [new ErrorException(self::QUEUE_FAILURE)],
            'an Error' => [new Error(self::QUEUE_FAILURE)],
            'a TypeError' => [new TypeError(self::QUEUE_FAILURE)],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function messagesAnsweredInTheRequest(): array
    {
        return self::onEveryBackend(['an inspect' => ['inspect'], 'an abandon' => ['abandon'], 'a ping' => ['ping']]);
    }
}
