<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Matrix;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\Matrix\MatrixWorkflow;
use NeuronCore\IQ\Tests\Stub\Matrix\MidBody;
use NeuronCore\IQ\Tests\Stub\Matrix\SeverablePersistence;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Assert;

use function array_keys;
use function base64_encode;
use function hash;
use function json_decode;
use function serialize;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;

/**
 * One cell of the redelivery matrix, played on a bench through invoke(): the
 * same invocation delivered twice, the second time in a chosen state of the
 * first copy. It records what the second copy did, for the test to assert.
 */
final class Redelivery
{
    protected const DEFINITION = 'order';

    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /** Seconds: a lease a test can wait out. */
    protected const SHORT_LEASE = 1;

    protected const LEASE = 600;

    protected MidBody $midBody;

    /** @var array<string, mixed> the invocation as it waits in a queue: both copies are rebuilt from it */
    protected array $message;

    protected Report $first;

    protected Report $second;

    protected int $executedBySecondCopy;

    protected int $executedByLateNotices;

    protected ?WorkflowRunSnapshot $runBeforeSecondCopy = null;

    protected ?WorkflowRunSnapshot $runAfterSecondCopy = null;

    /** @var array<string, int> */
    protected array $ledgerBeforeSecondCopy;

    /** @var array<string, int> */
    protected array $ledgerAfterSecondCopy;

    /** @var array<int, Report|IQException> what the notices left in the queue came to, once the platform answered again */
    protected array $late;

    protected function __construct(protected TestBench $bench, protected Kind $kind, protected FirstCopy $state)
    {
        $this->midBody = new MidBody();
    }

    public static function replay(TestBench $bench, Kind $kind, FirstCopy $state): self
    {
        $redelivery = new self($bench, $kind, $state);
        $redelivery->registerWorkflow();
        $redelivery->message = $redelivery->messageUnderTest();
        $redelivery->deliverTwice();
        $redelivery->drainQueue();
        $redelivery->assertPlayedAsIntended();

        return $redelivery;
    }

    protected function registerWorkflow(): void
    {
        $this->bench->iq->register(
            self::DEFINITION,
            fn (): MatrixWorkflow => new MatrixWorkflow(
                $this->bench->executions,
                $this->kind->opening(),
                $this->state->ending(),
                $this->midBody,
            ),
            leaseTimeout: $this->kind === Kind::WakeOfDeadRun ? self::SHORT_LEASE : self::LEASE,
        );
    }

    /**
     * The invocation under test. A continuation is fenced with the run ID and
     * the attempt the store showed once the run had stopped, and they are
     * never read again: that is what submitInputs() and signal() capture for
     * an answer and a signal (9.7, step 4), and what a report or an inspect
     * tells the platform for a wake (appendix B, rule 2).
     *
     * @return array<string, mixed>
     */
    protected function messageUnderTest(): array
    {
        return match ($this->kind) {
            Kind::PlatformStart => $this->platformStart('inv_start'),
            Kind::DispatchedStart => $this->dispatchedStart(),
            Kind::Answer => $this->answer($this->stoppedRun()),
            Kind::Signal => $this->signal($this->stoppedRun()),
            default => $this->wake($this->stoppedRun()),
        };
    }

    /**
     * A start as the endpoint queues it for the platform.
     *
     * @return array<string, mixed>
     */
    protected function platformStart(string $invocationId): array
    {
        return $this->envelope($invocationId, Verb::Start, 'platform', [
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 900,
        ]);
    }

    /**
     * A start as start()->dispatch() queues it: with its event, and a use-by time one hour ahead.
     *
     * @return array<string, mixed>
     */
    protected function dispatchedStart(): array
    {
        return $this->envelope('inv_start', Verb::Start, 'application', [
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 3600,
            'event' => base64_encode(serialize(new StartEvent())),
        ]);
    }

    /**
     * An answer as submitInputs()->dispatch() queues it.
     *
     * @return array<string, mixed>
     */
    protected function answer(WorkflowRunSnapshot $run): array
    {
        return $this->envelope('inv_answer', Verb::Answer, 'application', $this->fence($run) + [
            'expiresAt' => time() + 86400,
            'payload' => base64_encode(serialize(['amount' => 42])),
        ]);
    }

    /**
     * A signal as signal()->dispatch() queues it.
     *
     * @return array<string, mixed>
     */
    protected function signal(WorkflowRunSnapshot $run): array
    {
        return $this->envelope('inv_signal', Verb::Signal, 'application', $this->fence($run) + [
            'expiresAt' => time() + 86400,
            'signal' => MatrixWorkflow::EVENT,
            'payload' => base64_encode(serialize(['amount' => 42])),
        ]);
    }

    /**
     * A wake as the endpoint queues it for the platform.
     *
     * @return array<string, mixed>
     */
    protected function wake(WorkflowRunSnapshot $run): array
    {
        return $this->envelope('inv_wake', Verb::Wake, 'platform', $this->fence($run));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fence(WorkflowRunSnapshot $run): array
    {
        return ['runId' => $run->runId, 'attempt' => $run->executionAttempt];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function envelope(string $invocationId, Verb $verb, string $origin, array $fields): array
    {
        return [
            'invocationId' => $invocationId,
            'verb' => $verb->value,
            'origin' => $origin,
            'definition' => self::DEFINITION,
            'workflowId' => self::WORKFLOW_ID,
        ] + $fields;
    }

    /**
     * The run a continuation is issued for: the platform started it, and it
     * stopped where its first node stops it.
     */
    protected function stoppedRun(): WorkflowRunSnapshot
    {
        $start = Invocation::fromArray($this->platformStart('inv_setup'));

        if ($this->kind === Kind::WakeOfDeadRun) {
            $this->startOnWorkerCutOffFromItsStore($start);
        } else {
            $this->bench->iq->invoke($start);
        }

        $run = $this->run();
        Assert::assertInstanceOf(WorkflowRunSnapshot::class, $run, 'The fixture is wrong: the start left no run.');
        Assert::assertSame($this->kind->continues(), $run->status, 'The fixture is wrong: the run did not stop as its kind needs.');

        return $run;
    }

    /**
     * The worker is cut off from its store while the node is in flight, so
     * nothing it does afterwards is written, the failure of the node included:
     * the run stays "running" under a lease that nobody renews, and is dead
     * once that lease has passed. A killed worker leaves the same records.
     */
    protected function startOnWorkerCutOffFromItsStore(Invocation $start): void
    {
        $store = new SeverablePersistence(($this->bench->persistence)());
        $this->bench->iq->setPersistence(static fn (): PersistenceInterface => $store);
        $this->midBody->whenReached($store->sever(...));

        $this->bench->iq->invoke($start);

        $this->bench->iq->setPersistence($this->bench->persistence);
        $this->waitForLeaseToPass();
    }

    protected function waitForLeaseToPass(): void
    {
        $leaseExpiresAt = $this->run()?->leaseExpiresAt;
        Assert::assertNotNull($leaseExpiresAt, 'The fixture is wrong: the run the worker left holds no lease.');

        while (time() < $leaseExpiresAt) {
            usleep(10_000);
        }
    }

    protected function deliverTwice(): void
    {
        if ($this->state === FirstCopy::Executing) {
            $this->midBody->whenReached($this->deliverSecondCopy(...));
        }

        if ($this->state === FirstCopy::CompletedReportLost) {
            // The first copy has posted its announcement by then: the post the platform fails is its report.
            $this->midBody->whenReached(function (): void {
                $this->bench->platform->failNext();
            });
        }

        if ($this->state === FirstCopy::CompletedPlatformUnreachable) {
            // The announcement and the report of each copy.
            $this->bench->platform->failNext(4);
        }

        $this->first = $this->bench->iq->invoke(Invocation::fromArray($this->message));

        if ($this->state !== FirstCopy::Executing) {
            $this->deliverSecondCopy();
        }
    }

    protected function deliverSecondCopy(): void
    {
        $executed = $this->bench->executions->total();
        $this->runBeforeSecondCopy = $this->run();
        $this->ledgerBeforeSecondCopy = $this->ledger();

        $this->second = $this->bench->iq->invoke(Invocation::fromArray($this->message));

        $this->executedBySecondCopy = $this->bench->executions->total() - $executed;
        $this->runAfterSecondCopy = $this->run();
        $this->ledgerAfterSecondCopy = $this->ledger();
    }

    /**
     * The platform answers again: what the two copies could not tell it is delivered from the queue.
     */
    protected function drainQueue(): void
    {
        $executed = $this->bench->executions->total();

        $this->late = $this->bench->dispatcher->runAll($this->bench->iq);

        $this->executedByLateNotices = $this->bench->executions->total() - $executed;
    }

    /**
     * The run holding the workflow ID, read from the store by Neuron itself.
     */
    protected function run(): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::WORKFLOW_ID);
    }

    /**
     * @return array<string, int> the ledger record of the workflow ID (9.2): run ID => use-by time
     */
    protected function ledger(): array
    {
        $stored = ($this->bench->persistence)()->get('__iq:' . hash('sha256', self::WORKFLOW_ID), 'starts');

        return $stored === null ? [] : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    /**
     * A cell means nothing unless the first copy really was in the state the cell names.
     */
    protected function assertPlayedAsIntended(): void
    {
        Assert::assertSame(
            $this->state->outcome(),
            $this->first->outcome,
            "The fixture is wrong: the first copy ended '{$this->first->outcome->value}' "
            . "(code '{$this->first->code}', error '{$this->first->errorClass}').",
        );
        Assert::assertTrue(
            isset($this->second),
            'The fixture is wrong: no node body ran while the first copy executed, so the second copy was never delivered.',
        );
        Assert::assertCount(
            $this->state->noticesLeftInQueue(),
            $this->late,
            'The fixture is wrong: the platform did not record, or lose, what the state of the first copy says.',
        );
        Assert::assertContainsOnlyInstancesOf(Report::class, $this->late, 'A notice left in the queue was not delivered.');
        $this->bench->platform->assertReported(self::WORKFLOW_ID, $this->state->outcome());
    }

    public function assertSecondCopyRanNothing(): void
    {
        Assert::assertSame(0, $this->executedBySecondCopy, 'The second copy executed a node body.');
        Assert::assertNull($this->second->executedAttempt, 'The second copy opened a segment.');
        Assert::assertSame(0, $this->executedByLateNotices, 'A notice left in the queue executed a node body.');
    }

    /**
     * @param string $code a RefusalReason value or an SDK code
     */
    public function assertSecondCopyReported(Outcome $outcome, string $code): void
    {
        Assert::assertSame(
            [$outcome, $code],
            [$this->second->outcome, $this->second->code],
            'The second copy did not report what it should.',
        );
        Assert::assertNull($this->second->errorClass);
        Assert::assertTrue($this->second->snapshotRead);
        Assert::assertSame(
            $this->kind->startsTheRun() ? true : null,
            $this->second->claimed,
            'Only the report of a start says whether its run ID is in the ledger.',
        );
        $this->bench->platform->assertReported(self::WORKFLOW_ID, $outcome, $code);
    }

    public function assertStoreHolds(WorkflowStatus $status, int $attempt): void
    {
        $this->assertStoreLeftAsItWas();

        $run = $this->runAfterSecondCopy;
        Assert::assertSame(
            [self::RUN_ID, $status, $attempt],
            [$run?->runId, $run?->status, $run?->executionAttempt],
            'The store does not hold the run it should.',
        );

        $reported = $this->second->run;
        Assert::assertSame(
            [self::RUN_ID, $status->value, $attempt],
            [$reported['runId'] ?? null, $reported['status'] ?? null, $reported['attempt'] ?? null],
            'The report of the second copy does not show the run the store holds.',
        );
    }

    public function assertStoreHoldsNoRun(): void
    {
        $this->assertStoreLeftAsItWas();

        Assert::assertNull($this->runAfterSecondCopy, 'A run holds the workflow ID.');
        Assert::assertNull($this->second->run, 'The report of the second copy shows a run the store does not hold.');
    }

    protected function assertStoreLeftAsItWas(): void
    {
        Assert::assertEquals($this->runBeforeSecondCopy, $this->runAfterSecondCopy, 'The second copy changed the run.');
        Assert::assertSame($this->ledgerBeforeSecondCopy, $this->ledgerAfterSecondCopy, 'The second copy changed the ledger.');
        Assert::assertSame([self::RUN_ID], array_keys($this->ledgerAfterSecondCopy), 'The ledger must hold the run ID, once.');
    }
}
