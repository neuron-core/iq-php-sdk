<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Ledger;
use NeuronCore\IQ\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Support\Local\LocalTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;

use function base64_decode;
use function is_array;
use function parse_url;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function unserialize;

use const PHP_URL_PATH;

/**
 * A test of ManagedWorkflow::start(): it reads what a start handed to the
 * queue, what it asked of the store and what the first node was given.
 */
abstract class StartTestCase extends LocalTestCase
{
    protected Transcript $transcript;

    /** @var string[] the PersistenceInterface method of every operation the service asked of its store, in order */
    protected array $storeOperations = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->transcript)) {
            unlink($this->transcript->path);
        }
    }

    /**
     * A bench whose store operations are recorded, with "order" registered: the workflow of the plain PHP example.
     */
    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);
        $this->transcript = new Transcript((string) tempnam(sys_get_temp_dir(), 'iq-'));

        $persistence = $bench->persistence;
        $bench->iq->setPersistence(fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            function (string $operation, string $partition): void {
                $this->storeOperations[] = $operation;
            },
        ));

        [$executions, $transcript] = [$bench->executions, $this->transcript];
        $bench->iq->register('order', static fn (): OrderWorkflow => new OrderWorkflow($executions, $transcript));

        return $bench;
    }

    protected function order(): ManagedWorkflow
    {
        return $this->bench->iq->workflow('order', self::WORKFLOW_ID);
    }

    /**
     * The only message the queue was handed.
     */
    protected function onlyQueued(): Invocation
    {
        $queued = $this->queue->dispatched();
        self::assertCount(1, $queued, 'The queue was not handed exactly one message.');

        return $queued[0];
    }

    /**
     * The start event a queued start carries, rebuilt as a worker rebuilds it.
     */
    protected function eventCarriedBy(Invocation $start): Event
    {
        $event = unserialize((string) base64_decode((string) $start->event, true));
        self::assertInstanceOf(Event::class, $event, 'The queued start carries no start event.');

        return $event;
    }

    /**
     * The run holding the workflow ID of these tests, as the store shows it.
     */
    protected function holder(): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::WORKFLOW_ID);
    }

    protected function isClaimed(string $runId): bool
    {
        return (new Ledger($this->bench->persistence))->has(self::WORKFLOW_ID, $runId);
    }

    /**
     * Let the fake worker run what is queued, and expect one report back.
     */
    protected function workOnce(): Report
    {
        $results = $this->queue->runAll($this->bench->iq);
        self::assertCount(1, $results, 'The worker did not run exactly one message.');
        self::assertInstanceOf(Report::class, $results[0], 'The worker was thrown the message back.');

        return $results[0];
    }

    /**
     * @return array<int, array<string, mixed>> the reports the platform was sent, in order
     */
    protected function reportsSent(): array
    {
        $bodies = [];

        foreach ($this->bench->platform->received() as $request) {
            if (parse_url($request->uri, PHP_URL_PATH) === '/v1/reports' && is_array($request->body)) {
                $bodies[] = $request->body;
            }
        }

        return $bodies;
    }
}
