<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\Cloud\Tests\Support\Concurrency\SharedPlatform;
use NeuronCore\Cloud\Tests\Support\Concurrency\WorkerProcess;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointSchema;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\InspectablePlatform;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PDO;

use function array_map;
use function bin2hex;
use function glob;
use function max;
use function microtime;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use function usleep;

/**
 * An endpoint and a queue worker that are two processes on one SQLite file:
 * the test's own process answers the platform, and each start runs in a
 * worker process of its own. Call cleanUp() in tearDown().
 */
final class RaceBench
{
    /** Seconds between the order and the instant the worker acts at: enough for the order to reach it. */
    protected const HEAD_START = 0.02;

    public readonly ExecutionLog $executions;

    /** What the workers tell the platform. */
    public readonly SharedPlatform $platform;

    /** The platform's side of the endpoint: it signs the messages and keeps every exchange. */
    public readonly InspectablePlatform $operator;

    /** The endpoint of the test's own process, with the definitions of the workers on the store they use. */
    public readonly NeuronCloud $endpoint;

    protected string $directory;

    /** @var WorkerProcess[] */
    protected array $workers = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/neuron-cloud-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        touch($this->directory . '/executions');
        touch($this->directory . '/platform');

        (new PDO('sqlite:' . $this->storePath()))->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        $this->executions = $executions = new ExecutionLog($this->directory . '/executions');
        $this->platform = new SharedPlatform($this->directory . '/platform');
        $this->operator = new InspectablePlatform();
        $this->endpoint = (new NeuronCloud(TestBench::API_KEY, $this->operator->signingKey, TestBench::PLATFORM_URL, $this->platform))
            ->setPersistence($this->store(...))
            ->register(ProcessBench::COMPLETING, static fn (): CompletingWorkflow => new CompletingWorkflow($executions))
            ->register(ProcessBench::WAITING, static fn (): WaitingWorkflow => new WaitingWorkflow($executions));
    }

    /**
     * A worker process takes a queued start at an instant, and the platform sends the endpoint a message at
     * that instant or a little later.
     *
     * @param array<string, mixed> $message
     * @param float $after seconds from the instant the worker acts at to the one the endpoint is called at
     * @return array{array<string, mixed>, HttpResponse} the report invoke() returned in the worker, and the answer of the endpoint
     */
    public function race(Invocation $start, array $message, float $after): array
    {
        $worker = $this->workers[] = new WorkerProcess([
            'store' => $this->storePath(),
            'executions' => $this->executions->path,
            'platform' => $this->platform->path,
            'gate' => $this->directory . '/gate',
            'lease' => 600,
            'diesAfterClaim' => false,
            'invocation' => $start->toArray(),
        ]);
        $worker->awaitReady();

        $instant = microtime(true) + self::HEAD_START;
        $worker->invokeAt($instant);
        usleep((int) max(0, ($instant + $after - microtime(true)) * 1_000_000));
        $answer = $this->operator->deliver($this->endpoint, $message);

        return [$worker->report(), $answer];
    }

    /**
     * The run holding a workflow ID, as the store shows it to any process.
     */
    public function run(string $workflowId): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine($this->store()))->inspect($workflowId);
    }

    /**
     * Every body the workers sent, every message the endpoint took and every answer it gave must match the
     * JSON Schema of its message.
     */
    public function assertProtocolRespected(): void
    {
        foreach ($this->platform->announcements() as $announcement) {
            ProtocolSchema::assertValid('announcement', $announcement);
        }

        foreach ($this->platform->reports() as $report) {
            ProtocolSchema::assertValid('report', $report);
        }

        foreach ($this->operator->delivered() as ['message' => $message, 'answer' => $answer]) {
            EndpointSchema::assertRespected($message, $answer);
        }
    }

    public function cleanUp(): void
    {
        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    protected function store(): PersistenceInterface
    {
        return ProcessBench::open($this->storePath());
    }

    protected function storePath(): string
    {
        return $this->directory . '/store.sqlite';
    }
}
