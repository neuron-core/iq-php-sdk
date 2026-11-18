<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Concurrency;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\TestCase;

use function extension_loaded;
use function time;

/**
 * A test whose invocations each run in a worker process of their own, on one
 * SQLite file. Whatever those processes sent the platform must respect the
 * protocol.
 */
abstract class ProcessTestCase extends TestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected ProcessBench $bench;

    protected function setUp(): void
    {
        foreach (['pcntl', 'posix', 'pdo_sqlite'] as $extension) {
            if (!extension_loaded($extension)) {
                self::markTestSkipped("Worker processes on SQLite need the {$extension} extension.");
            }
        }

        $this->bench = new ProcessBench();
    }

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function tearDown(): void
    {
        if (isset($this->bench)) {
            $this->bench->cleanUp();
        }
    }

    /**
     * A start as it waits in the queue: one the endpoint queued for the platform, or one the application dispatched.
     *
     * @param string|null $event the start event the application dispatched it with, PHP-serialized and base64-encoded
     */
    protected function start(
        string $definition,
        string $runId = self::RUN_ID,
        string $workflowId = self::WORKFLOW_ID,
        string $origin = Origin::PLATFORM,
        ?string $event = null,
    ): Invocation {
        return Invocation::fromArray([
            'invocationId' => 'inv_start_' . $runId,
            'verb' => Verb::Start->value,
            'origin' => $origin,
            'definition' => $definition,
            'workflowId' => $workflowId,
            'runId' => $runId,
            'expiresAt' => time() + 900,
            'event' => $event,
        ]);
    }

    /**
     * A wake fenced as the platform does it: with the run ID and the attempt the application showed it.
     */
    protected function wake(string $definition, string $runId, int $attempt): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'inv_wake_' . $attempt,
            'verb' => Verb::Wake->value,
            'origin' => Origin::PLATFORM,
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $runId,
            'attempt' => $attempt,
        ]);
    }
}
