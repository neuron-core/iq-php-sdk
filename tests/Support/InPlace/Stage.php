<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\InPlace;

use Closure;
use LogicException;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Testing\FakePlatform;
use NeuronCore\IQ\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\IQ\Tests\Stub\InPlace\WatchedQueue;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\Matrix\MatrixWorkflow;
use NeuronCore\IQ\Tests\Stub\Matrix\MidBody;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Faults\StoreFault;
use NeuronCore\IQ\Tests\Support\Faults\StoreJournal;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use Throwable;

use function array_map;
use function array_slice;
use function count;
use function hash;
use function time;
use function usleep;

/**
 * A bench set for one segment that the application runs in its own process.
 * One definition, "journey", is registered: its first node gives a segment of
 * the chosen kind the run it needs, and its last node ends that segment the
 * chosen way. What a continuation continues was started through the queue and
 * run by a worker before the stage is handed over, so the queue is empty and
 * everything a test then sees is the segment's own.
 */
final class Stage
{
    public const DEFINITION = 'journey';

    public const WORKFLOW_ID = 'order:123';

    /** Seconds. Not the default of register(), so that an announcement shows whose lease it names. */
    public const LEASE = 90;

    public const ANSWER = ['amount' => 49.9];

    public readonly IQ $iq;

    public readonly WatchedQueue $queue;

    public readonly MidBody $midBody;

    public readonly StoreJournal $journal;

    /** What the application's code holds for the workflow ID of the stage. */
    public readonly ManagedWorkflow $thread;

    /** @var array<int, array{int, string, string|null}> the version, the workflow ID and the run ID each factory call was given, in order */
    protected array $builds = [];

    /** @var int[] the attempt of every segment Neuron opened on a workflow of the stage, in order */
    protected array $attemptsOpened = [];

    protected StoreFault $fault;

    /** @var Closure(string $operation, string $partition): void */
    protected Closure $beforeStoreOperation;

    protected function __construct(
        public readonly TestBench $bench,
        public readonly Kind $kind,
        protected Ending $ending,
        protected int $lease,
        bool $queued,
    ) {
        $this->queue = new WatchedQueue($bench->executions);
        $this->midBody = new MidBody();
        $this->journal = new StoreJournal();
        $this->fault = StoreFault::none();
        $this->beforeStoreOperation = static function (): void {
        };

        $this->iq = (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($this->store(...));

        if ($queued) {
            $this->iq->setDispatcher($this->queue);
        }

        $this->register(1, $ending, $lease);
        $this->thread = $this->iq->workflow(self::DEFINITION, self::WORKFLOW_ID);
        $this->open();
    }

    /**
     * @param int $lease seconds
     */
    public static function on(TestBench $bench, Kind $kind, Ending $ending = Ending::Completes, int $lease = self::LEASE): self
    {
        return new self($bench, $kind, $ending, $lease, queued: true);
    }

    /**
     * A stage whose service was given no dispatcher: every message runs in place.
     */
    public static function withoutQueue(TestBench $bench, Kind $kind, Ending $ending = Ending::Completes): self
    {
        return new self($bench, $kind, $ending, self::LEASE, queued: false);
    }

    /**
     * Register one more version of "journey": it opens as the stage does and ends its own way.
     *
     * @param int $lease seconds
     */
    public function register(int $version, Ending $ending, int $lease = self::LEASE): void
    {
        $this->iq->register(
            self::DEFINITION,
            function (string $workflowId, ?string $runId) use ($version, $ending): Workflow {
                $this->builds[] = [$version, $workflowId, $runId];

                return $this->journey($ending)->subscribe(WorkflowEnd::class, function (WorkflowEnd $end): void {
                    $this->attemptsOpened[] = (int) $end->execution?->executionAttempt;
                });
            },
            $version,
            $lease,
        );
    }

    /**
     * The segment of the stage, prepared and not yet used.
     */
    public function prepare(): PendingSegment
    {
        return match ($this->kind) {
            Kind::Start => $this->thread->start(),
            Kind::Answer => $this->thread->submitInputs(self::ANSWER),
            Kind::Signal => $this->thread->signal(MatrixWorkflow::EVENT, self::ANSWER),
            Kind::Recovery => throw new LogicException('recover() prepares nothing: it executes its wake at once.'),
        };
    }

    /**
     * Run the segment of the stage in this process, or make another call
     * instead, and note everything that happened meanwhile.
     *
     * @param (Closure(): (WorkflowState|string|null))|null $call null: the segment of the stage, prepared and run in place
     */
    public function play(?Closure $call = null): Played
    {
        $call ??= $this->kind === Kind::Recovery
            ? $this->thread->recover(...)
            : fn (): WorkflowState => $this->prepare()->run();

        $taken = count($this->queue->taken());
        $bodiesRun = $this->bench->executions->total();
        $requests = count($this->bench->platform->received());
        $opened = count($this->attemptsOpened);
        $returned = null;
        $thrown = null;

        try {
            $returned = $call();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return new Played(
            $returned,
            $thrown,
            array_slice($this->queue->taken(), $taken),
            array_map(static fn (int $ran): int => $ran - $bodiesRun, array_slice($this->queue->bodiesRunBefore(), $taken)),
            $this->bench->executions->total() - $bodiesRun,
            count($this->bench->platform->received()) - $requests,
            array_slice($this->attemptsOpened, $opened),
        );
    }

    /**
     * Run the segment of the stage after another process got to the workflow
     * ID first, so that Neuron refuses it: a start meets the run of an
     * earlier start; an answer or a signal was already given by another
     * request; the wake of recover() is overtaken by a wake of the platform
     * between its announcement and its admission.
     */
    public function playOvertaken(): Played
    {
        if ($this->kind === Kind::Start) {
            $this->elsewhere()->start()->dispatch();

            return $this->play();
        }

        if ($this->kind === Kind::Recovery) {
            $this->queue->beforeTaking($this->overtakeAtTheAnnouncement(...));

            return $this->play();
        }

        $ours = $this->prepare();
        $this->elsewhere()->submitInputs(self::ANSWER)->dispatch();

        return $this->play($ours->run(...));
    }

    /**
     * The workflow ID of the stage as another process of the application
     * holds it: on the same store, with no queue, so that it runs every
     * message in place, and with a platform of its own, so that the platform
     * of the bench receives only what the stage sent.
     */
    public function elsewhere(): ManagedWorkflow
    {
        return $this->cloudElsewhere()->workflow(self::DEFINITION, self::WORKFLOW_ID);
    }

    /**
     * Let the worker of the stage run what is queued.
     *
     * @return array<int, Report|IQException>
     */
    public function drain(): array
    {
        return $this->queue->runAll($this->iq);
    }

    /**
     * From now on the store fails as the fault says, for every workflow the stage builds.
     */
    public function failStore(StoreFault $fault): void
    {
        $this->fault = $fault;
    }

    /**
     * From now on the test steps into the store of the stage just before each operation.
     *
     * @param Closure(string $operation, string $partition): void $before
     */
    public function interceptStore(Closure $before): void
    {
        $this->beforeStoreOperation = $before;
    }

    /**
     * The run that holds the workflow ID, read without the SDK and told in the words of the protocol.
     *
     * @return array<string, mixed>|null
     */
    public function stored(): ?array
    {
        return StoredRun::of(($this->bench->persistence)(), self::WORKFLOW_ID);
    }

    /**
     * The record the ledger keeps for the workflow ID; null when nothing was ever written in it.
     */
    public function ledgerRecord(): ?string
    {
        return ($this->bench->persistence)()->get('__iq:' . hash('sha256', self::WORKFLOW_ID), 'starts');
    }

    /**
     * @return array<int, array{int, string, string|null}> the version, the workflow ID and the run ID each factory
     *                                                      call was given, in order
     */
    public function builds(): array
    {
        return $this->builds;
    }

    /**
     * Wait, in real time, until the lease of the run that is executing has passed.
     */
    public function outliveTheLease(): void
    {
        $leaseExpiresAt = (int) ($this->stored()['leaseExpiresAt'] ?? 0);

        while (time() < $leaseExpiresAt) {
            usleep(20_000);
        }
    }

    /**
     * The store every workflow of the stage is built on: the store of the
     * bench, behind a journal of what it performs, a fault and the test itself.
     */
    protected function store(): PersistenceInterface
    {
        return new InterceptedPersistence(
            new FaultyPersistence(($this->bench->persistence)(), $this->journal, $this->fault),
            function (string $operation, string $partition): void {
                ($this->beforeStoreOperation)($operation, $partition);
            },
        );
    }

    protected function journey(Ending $ending): MatrixWorkflow
    {
        return new MatrixWorkflow($this->bench->executions, $this->kind->opening(), $ending, $this->midBody);
    }

    /**
     * A worker runs what a continuation continues: the run then waits for its
     * event, or has failed in its first node.
     */
    protected function open(): void
    {
        if ($this->kind->continuesARun()) {
            $this->thread->start()->dispatch();
            $this->drain();
        }
    }

    protected function cloudElsewhere(): IQ
    {
        return (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new FakePlatform()))
            ->setPersistence($this->bench->persistence)
            ->register(self::DEFINITION, fn (): Workflow => $this->journey($this->ending), leaseTimeout: $this->lease);
    }

    /**
     * Before the queue takes the announcement of the wake of recover(), a
     * worker elsewhere executes a wake of the platform for the same failed
     * run, fenced as the platform fences it: with what the store showed.
     */
    protected function overtakeAtTheAnnouncement(Invocation $handed): void
    {
        $failed = $this->stored();

        if ($handed->verb === Verb::Announce && $failed !== null && $failed['status'] === 'failed' && $failed['attempt'] === 1) {
            $this->cloudElsewhere()->invoke(new Invocation(
                'inv_overtaking',
                Verb::Wake,
                Origin::PLATFORM,
                self::DEFINITION,
                self::WORKFLOW_ID,
                $failed['runId'],
                $failed['attempt'],
            ));
        }
    }
}
