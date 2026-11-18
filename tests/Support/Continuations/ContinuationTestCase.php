<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Continuations;

use Closure;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\IQ\Tests\Support\Local\LocalTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use Throwable;

use function array_slice;
use function count;
use function time;
use function usleep;

/**
 * A test of what the application's own code does to a run that exists: it
 * answers its wait, delivers its event or finishes it after a failure.
 */
abstract class ContinuationTestCase extends LocalTestCase
{
    protected const ANSWER = 'submitInputs';

    protected const SIGNAL = 'signal';

    protected const BUYER = ['signer' => 'buyer@example.com'];

    protected const SELLER = ['signer' => 'seller@example.com'];

    /**
     * The rows of a data provider: an answer and a signal, each on every backend.
     *
     * @return array<string, array{string, string}> the call that prepares the continuation, and the backend
     */
    public static function continuationsOnBackends(): array
    {
        $rows = [];
        foreach (TestBench::backends() as $backendName => [$backend]) {
            $rows["an answer on {$backendName}"] = [self::ANSWER, $backend];
            $rows["a signal on {$backendName}"] = [self::SIGNAL, $backend];
        }

        return $rows;
    }

    /**
     * Register a definition on the bench and bind it to the workflow ID of these tests.
     *
     * @param Closure(string, ?string): Workflow $factory
     */
    protected function managed(string $name, Closure $factory, int $version = 1, int $leaseTimeout = 600): ManagedWorkflow
    {
        return $this->bench->iq
            ->register($name, $factory, $version, $leaseTimeout)
            ->workflow($name, self::WORKFLOW_ID);
    }

    /**
     * "contract": it waits for the event "contract.signed" from the buyer, then for the same event from the seller.
     */
    protected function contract(): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions));
    }

    /**
     * A contract was started on the queue and a worker ran it: it waits for the buyer, and the queue is empty.
     */
    protected function contractWaitingForTheBuyer(): ManagedWorkflow
    {
        $contract = $this->contract();
        $contract->start()->dispatch();
        $this->work();

        return $contract;
    }

    /**
     * Another process of the application, on the queue and the platform of the bench, with no definition yet:
     * an older deploy, a worker that dies, a request that loses a race.
     *
     * @param PersistenceInterface|null $store the one store this process builds; null: the stores of the bench
     */
    protected function anotherProcess(?PersistenceInterface $store = null): IQ
    {
        return (new IQ(TestBench::API_KEY, httpClient: $this->bench->platform))
            ->setPersistence($store instanceof PersistenceInterface ? static fn (): PersistenceInterface => $store : $this->bench->persistence)
            ->setDispatcher($this->queue);
    }

    /**
     * The same definition as an application drives it without the SDK: Neuron's own calls, on the same store.
     */
    protected function withoutTheSdk(Workflow $definition): Workflow
    {
        return $definition->for(self::WORKFLOW_ID)->setPersistence(($this->bench->persistence)());
    }

    /**
     * The call under test, for a test that holds for an answer as for a signal.
     *
     * @param array<string, mixed> $payload
     */
    protected function prepare(string $continuation, ManagedWorkflow $thread, string $event, array $payload): PendingSegment
    {
        return $continuation === self::SIGNAL ? $thread->signal($event, $payload) : $thread->submitInputs($payload);
    }

    /**
     * A worker takes everything that is queued, each message $deliveries times in a row.
     *
     * @return array<int, Report|IQException> what every invoke() returned or threw, in order
     */
    protected function work(int $deliveries = 1): array
    {
        return $this->queue->runAll($this->bench->iq, $deliveries);
    }

    /**
     * A worker executes the wake the platform sends for the run the store shows now, fenced with its run ID
     * and its attempt.
     */
    protected function thePlatformWakesTheRun(string $definition): Report
    {
        $run = $this->storedRun();
        self::assertInstanceOf(WorkflowRunSnapshot::class, $run);

        return $this->bench->iq->invoke(
            new Invocation('inv_platform_wake', Verb::Wake, Origin::PLATFORM, $definition, self::WORKFLOW_ID, $run->runId, $run->executionAttempt),
        );
    }

    /**
     * The run that holds the workflow ID as the store shows it now, read through Neuron's own engine.
     *
     * @phpstan-impure every call reads the store again
     */
    protected function storedRun(): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::WORKFLOW_ID);
    }

    /**
     * @return Invocation[] what was handed to the queue after its first $alreadyQueued messages
     */
    protected function queuedAfter(int $alreadyQueued): array
    {
        return array_slice($this->queue->dispatched(), $alreadyQueued);
    }

    protected function countQueued(): int
    {
        return count($this->queue->dispatched());
    }

    /**
     * @return array{mixed, mixed, mixed} the kind of segment a queued announcement names, and the fence it
     *                                    carries: the run ID and the attempt
     */
    protected static function announced(Invocation $announcement): array
    {
        return [$announcement->body['verb'] ?? null, $announcement->body['runId'] ?? null, $announcement->body['attempt'] ?? null];
    }

    /**
     * @return array{mixed, mixed, mixed} how a queued observe says its segment ended: the outcome, the code and
     *                                    the attempt it executed under
     */
    protected static function settled(Invocation $observe): array
    {
        return [$observe->body['outcome'] ?? null, $observe->body['code'] ?? null, $observe->body['executedAttempt'] ?? null];
    }

    /**
     * @param Closure(): mixed $call
     */
    protected static function thrownFrom(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        self::fail('The call threw nothing.');
    }

    /**
     * What Neuron refused a call with.
     *
     * @param Closure(): mixed $call
     */
    protected static function refusalFrom(Closure $call): WorkflowRefusedException
    {
        $thrown = self::thrownFrom($call);
        self::assertInstanceOf(WorkflowRefusedException::class, $thrown, self::describe($thrown));

        return $thrown;
    }

    /**
     * What the SDK itself refused a call with.
     *
     * @param Closure(): mixed $call
     */
    protected static function cloudExceptionFrom(Closure $call): IQException
    {
        $thrown = self::thrownFrom($call);
        self::assertInstanceOf(IQException::class, $thrown, self::describe($thrown));

        return $thrown;
    }

    protected static function describe(Throwable $thrown): string
    {
        return 'The call threw ' . $thrown::class . ': ' . $thrown->getMessage();
    }

    /**
     * Neuron reads time(), so only the real clock can make a lease pass.
     */
    protected function holdUntil(int $instant): void
    {
        while (time() < $instant) {
            usleep(50_000);
        }
    }
}
