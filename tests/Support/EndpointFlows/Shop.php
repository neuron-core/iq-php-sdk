<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\EndpointFlows\OrderWorkflow;
use NeuronCore\Cloud\Tests\Support\Concurrency\Gate;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\Flows\FileQueue;
use NeuronCore\Cloud\Tests\Support\Flows\Transcript;
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
 * The shop of the plain PHP example, deployed: a SQLite file as its workflow
 * store, a file as its queue, the endpoint and the payment webhook in the
 * process of the test, and a worker process for every message of the queue.
 * The one fake platform answers them all. Call cleanUp() when the test ends.
 */
final class Shop
{
    public const DEFINITION = 'order-fulfilment';

    public const WORKFLOW_ID = 'order:123';

    /**
     * Seconds: short enough for a test to see the lease of a killed worker pass. Neuron counts in whole
     * seconds, so a lease of 2 lasts more than one second and a test can also see it run.
     */
    public const LEASE = 2;

    public readonly ReadablePlatform $platform;

    /** The NeuronCloud of the web tier: the endpoint and the payment webhook. */
    public readonly NeuronCloud $cloud;

    public readonly ExecutionLog $executions;

    public readonly Transcript $transcript;

    public readonly Gate $gate;

    protected FileQueue $queue;

    /** @var array<string, mixed> what every process builds its NeuronCloud from */
    protected array $settings;

    /** @var Invocation[] every message that was taken out of the queue, in order */
    protected array $taken = [];

    /** @var Worker[] */
    protected array $workers = [];

    /**
     * @param int $shipsAfter seconds between the payment of an order and the moment its timer is due
     * @param int $shippingFailures how many times the node after the timer throws before the order ships
     * @param int[] $versions the versions of the definition the code of the shop holds
     */
    public function __construct(int $shipsAfter, int $shippingFailures = 0, array $versions = [1])
    {
        $directory = sys_get_temp_dir() . '/neuron-cloud-' . bin2hex(random_bytes(8));
        mkdir($directory);

        $this->settings = [
            'directory' => $directory,
            'shipsAfter' => $shipsAfter,
            'shippingFailures' => $shippingFailures,
            'versions' => $versions,
        ];

        foreach (['executions', 'transcript', 'queue'] as $shared) {
            touch($directory . '/' . $shared);
        }

        self::connect($directory)->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        $this->executions = new ExecutionLog($directory . '/executions');
        $this->transcript = new Transcript($directory . '/transcript');
        $this->gate = new Gate($directory . '/gate');
        $this->queue = new FileQueue($directory . '/queue');
        $this->platform = new ReadablePlatform();
        $this->cloud = self::bootstrap($this->settings, $this->platform, $this->platform->signingKey);
    }

    /**
     * bootstrap.php of the example: what the endpoint, the payment webhook and every worker require.
     *
     * @param array<string, mixed> $settings the directory the processes share, and how an order behaves
     * @param string|null $signingKey only the endpoint needs it
     */
    public static function bootstrap(array $settings, HttpClientInterface $platform, ?string $signingKey = null): NeuronCloud
    {
        $directory = $settings['directory'];
        $executions = new ExecutionLog($directory . '/executions');
        $transcript = new Transcript($directory . '/transcript');
        $gate = new Gate($directory . '/gate');

        $cloud = (new NeuronCloud(TestBench::API_KEY, $signingKey, TestBench::PLATFORM_URL, $platform))
            ->setPersistence(static fn (): PersistenceInterface => new DatabasePersistence(self::connect($directory)))
            ->setDispatcher(new FileQueue($directory . '/queue'));

        foreach ($settings['versions'] as $version) {
            $cloud->register(
                self::DEFINITION,
                static fn (): OrderWorkflow => new OrderWorkflow($executions, $transcript, $gate, $settings['shipsAfter'], $settings['shippingFailures']),
                $version,
                self::LEASE,
            );
        }

        return $cloud;
    }

    /**
     * A deploy reaches the workers after the web tier: the workers started from now on run the code that
     * holds these versions.
     */
    public function workersHold(int ...$versions): void
    {
        $this->settings['versions'] = $versions;
    }

    /**
     * The payment webhook of the example: the application delivers the event itself, on its queue.
     */
    public function paymentWebhook(): void
    {
        $this->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID)
            ->signal(OrderWorkflow::EVENT, ['amount' => 49.9, 'reference' => Secrets::SIGNAL])
            ->dispatch();
    }

    /**
     * Take every message that waits in the queue out of it.
     *
     * @return Invocation[] in the order the messages arrived
     */
    public function take(): array
    {
        $waiting = $this->queue->take();
        $this->taken = [...$this->taken, ...$waiting];

        return $waiting;
    }

    /**
     * A worker process that executes one message. The test must let it end.
     */
    public function worker(Invocation $message): Worker
    {
        return $this->workers[] = new Worker($this->settings, $message, $this->platform);
    }

    /**
     * Every message that waits in the queue is executed, one worker process after the other.
     */
    public function work(): void
    {
        foreach ($this->take() as $message) {
            $this->worker($message)->finish();
        }
    }

    /**
     * @return Invocation[] every message that was taken out of the queue, in order
     */
    public function taken(): array
    {
        return $this->taken;
    }

    public function cleanUp(): void
    {
        foreach ($this->workers as $worker) {
            $worker->kill();
        }

        array_map(unlink(...), glob($this->settings['directory'] . '/*') ?: []);
        rmdir($this->settings['directory']);
    }

    protected static function connect(string $directory): PDO
    {
        $pdo = new PDO('sqlite:' . $directory . '/store.sqlite');
        // Several processes write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }
}
