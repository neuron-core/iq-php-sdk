<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support;

use Closure;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakeDispatcher;
use PDO;

use function is_array;
use function parse_url;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_URL_PATH;

/**
 * A NeuronCloud wired to the two fakes over one store, with a log the stub
 * workflows count their node executions in. Call cleanUp() in tearDown().
 */
final class TestBench
{
    public const IN_MEMORY = 'in-memory';

    public const SQLITE = 'sqlite';

    public const API_KEY = 'test-api-key';

    public const PLATFORM_URL = 'https://platform.test';

    public readonly InspectablePlatform $platform;

    public readonly FakeDispatcher $dispatcher;

    public readonly NeuronCloud $cloud;

    public readonly ExecutionLog $executions;

    /** @var string[] */
    protected array $temporaryFiles;

    /**
     * @param Closure(): PersistenceInterface $persistence what setPersistence() was given
     */
    protected function __construct(public readonly Closure $persistence, string ...$temporaryFiles)
    {
        $executions = self::temporaryFile();
        $this->temporaryFiles = [...$temporaryFiles, $executions];
        $this->executions = new ExecutionLog($executions);
        $this->platform = new InspectablePlatform();
        $this->dispatcher = new FakeDispatcher();
        $this->cloud = (new NeuronCloud(self::API_KEY, $this->platform->signingKey, self::PLATFORM_URL, $this->platform))
            ->setPersistence($persistence)
            ->setDispatcher($this->dispatcher);
    }

    /**
     * The rows of a data provider: one per backend the SDK is tested on.
     *
     * @return array<string, array{string}>
     */
    public static function backends(): array
    {
        return [
            'InMemoryPersistence' => [self::IN_MEMORY],
            'DatabasePersistence on SQLite' => [self::SQLITE],
        ];
    }

    public static function on(string $backend): self
    {
        return $backend === self::SQLITE ? self::onSqlite() : self::inMemory();
    }

    /**
     * One store shared by everything the bench builds, as a test of a single process needs.
     */
    public static function inMemory(): self
    {
        $store = new InMemoryPersistence();

        return new self(static fn (): PersistenceInterface => $store);
    }

    /**
     * A temporary SQLite file with Neuron's table. Every build opens its own
     * connection, as the processes of a real application do.
     */
    public static function onSqlite(): self
    {
        $path = self::temporaryFile();
        self::connect($path)->exec(
            'CREATE TABLE workflow_store ('
            . '"partition" VARCHAR(510) NOT NULL, '
            . '"key" VARCHAR(510) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY ("partition", "key"))'
        );

        return new self(static fn (): PersistenceInterface => new DatabasePersistence(self::connect($path)), $path);
    }

    /**
     * Every body the SDK sent must match the JSON Schema of its message.
     */
    public function assertProtocolRespected(): void
    {
        foreach ($this->platform->received() as $request) {
            $message = parse_url($request->uri, PHP_URL_PATH) === '/v1/reports' ? 'report' : 'announcement';

            ProtocolSchema::assertValid($message, is_array($request->body) ? $request->body : []);
        }
    }

    public function cleanUp(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
    }

    protected static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        // Several processes may write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    protected static function temporaryFile(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-');
    }
}
