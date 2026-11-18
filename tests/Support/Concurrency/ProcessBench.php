<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Concurrency;

use Closure;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Ledger;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
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
use function time;
use function touch;
use function unlink;
use function usleep;

/**
 * What the processes of one test share: a SQLite file as their workflow
 * store, the log their nodes count executions in, the file that stands for
 * the platform and a gate. Each invocation it delivers runs in a worker
 * process of its own. Call cleanUp() in tearDown().
 */
final class ProcessBench
{
    /** Definition: completes in a single segment. */
    public const COMPLETING = 'completing';

    /** Definition: waits for an event that has no deadline. */
    public const WAITING = 'waiting';

    /** Definition: commits a step, then stays inside its second node until the gate opens. */
    public const STALLING = 'stalling';

    /** Definition: the same, after its first node has waited for a timer that is due. */
    public const STALLING_AFTER_TIMER = 'stalling-after-timer';

    /** Seconds between the order and the instant racing workers act at: enough for the order to reach them all. */
    protected const HEAD_START = 0.05;

    /** Seconds a test waits for what another process must do. */
    protected const PATIENCE = 10.0;

    public readonly ExecutionLog $executions;

    public readonly SharedPlatform $platform;

    public readonly Gate $gate;

    protected string $directory;

    /** @var WorkerProcess[] */
    protected array $workers = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/iq-' . bin2hex(random_bytes(8));
        mkdir($this->directory);

        touch($this->directory . '/executions');
        touch($this->directory . '/platform');
        $this->executions = new ExecutionLog($this->directory . '/executions');
        $this->platform = new SharedPlatform($this->directory . '/platform');
        $this->gate = new Gate($this->directory . '/gate');

        self::connect($this->storePath())->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );
    }

    /**
     * The store of a bench, through a connection of its own.
     *
     * @param string $path the SQLite file
     */
    public static function open(string $path): PersistenceInterface
    {
        return new DatabasePersistence(self::connect($path));
    }

    /**
     * Give one invocation to invoke() in a worker process of its own.
     *
     * @param int $lease the lease of every definition in that worker, in seconds
     * @param bool $diesAfterClaim the worker kills itself between the claim of a start and Neuron's first write
     */
    public function deliver(Invocation $invocation, int $lease = 600, bool $diesAfterClaim = false): WorkerProcess
    {
        $worker = $this->spawn($invocation, $lease, $diesAfterClaim);
        $worker->awaitReady();
        $worker->invokeAt(microtime(true));

        return $worker;
    }

    /**
     * Give each invocation to invoke() in a worker process of its own, all at the same instant, and wait for them.
     *
     * @return array<int, array<string, mixed>> the report invoke() returned in each process, in the order of the invocations
     */
    public function deliverAtOnce(Invocation ...$invocations): array
    {
        $workers = array_map(fn (Invocation $invocation): WorkerProcess => $this->spawn($invocation), $invocations);

        foreach ($workers as $worker) {
            $worker->awaitReady();
        }

        $instant = microtime(true) + self::HEAD_START;
        foreach ($workers as $worker) {
            $worker->invokeAt($instant);
        }

        return array_map(static fn (WorkerProcess $worker): array => $worker->report(), $workers);
    }

    /**
     * Wait until a node body has been entered a number of times, in whatever process.
     *
     * @param class-string $node
     */
    public function awaitExecutions(string $node, int $times): void
    {
        $this->await(
            fn (): bool => $this->executions->count($node) >= $times,
            "{$node} was not entered {$times} time(s).",
        );
    }

    /**
     * Wait until the lease of the run a dead worker left running has passed.
     */
    public function awaitExpiredLease(string $workflowId): WorkflowRunSnapshot
    {
        $this->await(
            fn (): bool => $this->run($workflowId)?->leaseExpiresAt <= time(),
            "The lease of the run of workflow ID '{$workflowId}' did not pass.",
        );

        return $this->run($workflowId) ?? Assert::fail("No run holds workflow ID '{$workflowId}'.");
    }

    /**
     * The run holding a workflow ID, as the store shows it to any process.
     */
    public function run(string $workflowId): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(self::open($this->storePath())))->inspect($workflowId);
    }

    /**
     * Whether the run ID of a start is in the ledger.
     */
    public function claimed(string $workflowId, string $runId): bool
    {
        return (new Ledger(fn (): PersistenceInterface => self::open($this->storePath())))->has($workflowId, $runId);
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
        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    protected function spawn(Invocation $invocation, int $lease = 600, bool $diesAfterClaim = false): WorkerProcess
    {
        return $this->workers[] = new WorkerProcess([
            'store' => $this->storePath(),
            'executions' => $this->executions->path,
            'platform' => $this->platform->path,
            'gate' => $this->gate->path,
            'lease' => $lease,
            'diesAfterClaim' => $diesAfterClaim,
            'invocation' => $invocation->toArray(),
        ]);
    }

    /**
     * @param Closure(): bool $happened
     */
    protected function await(Closure $happened, string $otherwise): void
    {
        $deadline = microtime(true) + self::PATIENCE;

        while (!$happened()) {
            if (microtime(true) > $deadline) {
                Assert::fail($otherwise);
            }

            usleep(10_000);
        }
    }

    protected function storePath(): string
    {
        return $this->directory . '/store.sqlite';
    }

    protected static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        // Several processes write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
