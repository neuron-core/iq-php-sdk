<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\RefundOrder;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\SupportAgent;
use NeuronCore\Cloud\Tests\Support\Concurrency\SharedPlatform;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\Flows\FileQueue;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PDO;

use function array_map;
use function bin2hex;
use function glob;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/**
 * What the web server and the queue worker of a chat application share when
 * they are separate processes: a directory with the SQLite store of the runs,
 * the chat history and a file queue. Each process builds its own NeuronCloud
 * on it.
 */
final class ChatHome
{
    public readonly FileQueue $queue;

    public readonly Transcripts $transcripts;

    /** What the web server sent the platform, which must be nothing. */
    public readonly SharedPlatform $sentByTheServer;

    protected ExecutionLog $executions;

    /**
     * @param string $directory an existing directory; what it lacks is created
     */
    public function __construct(public readonly string $directory)
    {
        foreach (['queue', 'platform', 'executions'] as $shared) {
            touch("{$directory}/{$shared}");
        }

        $this->connect()->exec(
            'CREATE TABLE IF NOT EXISTS workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        $this->queue = new FileQueue("{$directory}/queue");
        $this->transcripts = new Transcripts("{$directory}/conversations.sqlite");
        $this->sentByTheServer = new SharedPlatform("{$directory}/platform");
        $this->executions = new ExecutionLog("{$directory}/executions");
    }

    public static function temporary(): self
    {
        $directory = sys_get_temp_dir() . '/neuron-cloud-chat-' . bin2hex(random_bytes(8));
        mkdir($directory);

        return new self($directory);
    }

    /**
     * The bootstrap of the application, as each of its processes runs it.
     *
     * @param AIProviderInterface $model the provider of the agent in this process
     * @param HttpClientInterface $platform how this process reaches the platform
     * @param Broadcast $broadcast where the agent's own channel delivers in this process
     * @param int $leaseTimeout seconds
     */
    public function application(
        AIProviderInterface $model,
        HttpClientInterface $platform,
        Broadcast $broadcast,
        int $leaseTimeout = 600,
    ): NeuronCloud {
        $transcripts = $this->transcripts;
        $executions = $this->executions;

        return (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $platform))
            ->setPersistence(fn (): PersistenceInterface => $this->store())
            ->setDispatcher($this->queue)
            ->register(
                ChatEndpoint::DEFINITION,
                static fn (): SupportAgent => new SupportAgent($model, $transcripts->store(), new RefundOrder($executions), $broadcast),
                leaseTimeout: $leaseTimeout,
            );
    }

    /**
     * The run holding a thread, as the store shows it to any process.
     */
    public function run(string $threadId): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine($this->store()))->inspect($threadId);
    }

    public function remove(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    protected function store(): PersistenceInterface
    {
        return new DatabasePersistence($this->connect());
    }

    protected function connect(): PDO
    {
        $pdo = new PDO("sqlite:{$this->directory}/store.sqlite");
        // Several processes write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
