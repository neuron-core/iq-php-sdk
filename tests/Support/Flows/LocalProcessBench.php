<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Flows;

use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Support\Concurrency\Gate;
use NeuronCore\Cloud\Tests\Support\Concurrency\SharedPlatform;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PDO;
use PHPUnit\Framework\Assert;

use function array_map;
use function bin2hex;
use function glob;
use function microtime;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function unlink;
use function usleep;

/**
 * An application whose requests and workers are separate processes on one
 * SQLite file: what they share, the NeuronCloud each of them builds, and the
 * processes a test spawns. Call cleanUp() in tearDown().
 */
final class LocalProcessBench
{
    /** Definition: the workflow of the plain PHP example. */
    public const ORDER = 'order';

    /** Definition: the same, whose first node stays inside its body until the gate opens. */
    public const GATED_ORDER = 'gated-order';

    /** Seconds between the order and the instant racing processes act at: enough for the order to reach them all. */
    protected const HEAD_START = 0.05;

    /** Seconds a test waits for what another process must do. */
    protected const PATIENCE = 10.0;

    public readonly ExecutionLog $executions;

    public readonly Transcript $transcript;

    public readonly SharedPlatform $platform;

    public readonly FileQueue $queue;

    public readonly Gate $gate;

    /** The NeuronCloud of the test's own process, on the files every process shares. */
    public readonly NeuronCloud $cloud;

    protected string $directory;

    /** @var ApplicationProcess[] */
    protected array $processes = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/neuron-cloud-' . bin2hex(random_bytes(8));
        mkdir($this->directory);

        $files = $this->files();
        foreach (['executions', 'transcript', 'platform', 'queue'] as $shared) {
            touch($files[$shared]);
        }

        self::connect($files['store'])->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        $this->executions = new ExecutionLog($files['executions']);
        $this->transcript = new Transcript($files['transcript']);
        $this->platform = new SharedPlatform($files['platform']);
        $this->queue = new FileQueue($files['queue']);
        $this->gate = new Gate($files['gate']);
        $this->cloud = self::application($files);
    }

    /**
     * The NeuronCloud every process of the application builds: its bootstrap.
     *
     * @param array<string, string> $files the store, the queue, the platform, the gate and the two logs
     */
    public static function application(array $files): NeuronCloud
    {
        $executions = new ExecutionLog($files['executions']);
        $transcript = new Transcript($files['transcript']);
        $gate = new Gate($files['gate']);
        $store = $files['store'];

        return (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new SharedPlatform($files['platform'])))
            ->setPersistence(static fn (): PersistenceInterface => new DatabasePersistence(self::connect($store)))
            ->setDispatcher(new FileQueue($files['queue']))
            ->register(self::ORDER, static fn (): OrderWorkflow => new OrderWorkflow($executions, $transcript))
            ->register(self::GATED_ORDER, static fn (): OrderWorkflow => new OrderWorkflow($executions, $transcript, gate: $gate));
    }

    /**
     * A process that will make one call on its own NeuronCloud. It comes up and waits for its order.
     *
     * @param array<string, mixed> $call built by ApplicationCall
     */
    public function process(array $call): ApplicationProcess
    {
        return $this->processes[] = new ApplicationProcess($this->files(), $call);
    }

    /**
     * Have each call made in a process of its own, all at the same instant, and wait for them.
     *
     * @param array<string, mixed> ...$calls built by ApplicationCall
     * @return array<int, array<string, mixed>> what each call returned or threw, in the order of the calls
     */
    public function atOnce(array ...$calls): array
    {
        $processes = array_map($this->process(...), $calls);

        foreach ($processes as $process) {
            $process->awaitReady();
        }

        $instant = microtime(true) + self::HEAD_START;
        foreach ($processes as $process) {
            $process->actAt($instant);
        }

        return array_map(static fn (ApplicationProcess $process): array => $process->result(), $processes);
    }

    /**
     * A queue worker deals with everything that waits in the queue now: the notices of the requests reach the platform.
     */
    public function work(): void
    {
        foreach ($this->queue->take() as $message) {
            $this->cloud->invoke($message);
        }
    }

    /**
     * Wait until a node body has been entered a number of times, in whatever process.
     *
     * @param class-string $node
     */
    public function awaitExecutions(string $node, int $times): void
    {
        $deadline = microtime(true) + self::PATIENCE;

        while ($this->executions->count($node) < $times) {
            if (microtime(true) > $deadline) {
                Assert::fail("{$node} was not entered {$times} time(s).");
            }

            usleep(10_000);
        }
    }

    /**
     * The run holding a workflow ID, as the store shows it to any process.
     */
    public function run(string $workflowId): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine($this->store()))->inspect($workflowId);
    }

    /**
     * Every body the processes sent must match the JSON Schema of its message.
     */
    public function assertProtocolRespected(): void
    {
        foreach ($this->platform->announcements() as $announcement) {
            ProtocolSchema::assertValid('announcement', $announcement);
        }

        foreach ($this->platform->reports() as $report) {
            ProtocolSchema::assertValid('report', $report);
        }
    }

    public function cleanUp(): void
    {
        foreach ($this->processes as $process) {
            $process->kill();
        }

        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /**
     * @return array<string, string>
     */
    protected function files(): array
    {
        return [
            'store' => $this->directory . '/store.sqlite',
            'executions' => $this->directory . '/executions',
            'transcript' => $this->directory . '/transcript',
            'platform' => $this->directory . '/platform',
            'queue' => $this->directory . '/queue',
            'gate' => $this->directory . '/gate',
        ];
    }

    protected function store(): PersistenceInterface
    {
        return new DatabasePersistence(self::connect($this->files()['store']));
    }

    protected static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        // Several processes write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
