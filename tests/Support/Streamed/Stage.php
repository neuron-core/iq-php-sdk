<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Streamed;

use Closure;
use Generator;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\Cloud\Tests\Stub\InPlace\WatchedQueue;
use NeuronCore\Cloud\Tests\Stub\Matrix\MidBody;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Streamed\Ending;
use NeuronCore\Cloud\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Faults\StoredRun;
use NeuronCore\Cloud\Tests\Support\Faults\StoreFault;
use NeuronCore\Cloud\Tests\Support\Faults\StoreJournal;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Assert;
use RuntimeException;

use function array_map;
use function array_slice;
use function count;
use function hash;
use function is_array;
use function parse_url;
use function time;
use function usleep;

use const PHP_URL_PATH;

/**
 * A bench set for one segment that the application streams in its own
 * process. One definition, "tale", is registered: its two nodes stream four
 * words, the first one gives a segment of the chosen kind the run it needs,
 * and the last one ends that segment the chosen way. What a continuation
 * continues was started through the queue and run by a worker before the
 * stage is handed over: everything a test then reads here is the segment's own.
 */
final class Stage
{
    public const DEFINITION = 'tale';

    public const WORKFLOW_ID = 'thread:42';

    /** Seconds. Not the default of register(), so that an announcement shows whose lease it names. */
    public const LEASE = 90;

    public const ANSWER = ['name' => 'Ada'];

    public readonly NeuronCloud $cloud;

    public readonly WatchedQueue $queue;

    /** What a test does while the first node is between its two words. */
    public readonly MidBody $midStream;

    public readonly StoreJournal $journal;

    /** What the application's code holds for the workflow ID of the stage. */
    public readonly ManagedWorkflow $thread;

    /** @var int[] the attempt of every segment Neuron opened on a workflow of the stage, in order */
    protected array $attemptsOpened = [];

    protected StoreFault $fault;

    /** @var Closure(string $operation, string $partition): void */
    protected Closure $beforeStoreOperation;

    /** @var array<int, Generator<int, object, mixed, WorkflowState>> the streams of other requests that are executing a run of the workflow ID */
    protected array $streamedElsewhere = [];

    protected int $takenBefore;

    protected int $bodiesRunBefore;

    protected int $writesBefore;

    protected int $openedBefore;

    protected int $requestsBefore;

    /** How many requests the platform received from the worker of the stage since the stage was handed over. */
    protected int $requestsOfTheWorker = 0;

    /**
     * @param int $lease seconds
     * @param bool $hasAQueue false: the cloud of the stage is given no dispatcher, and runs every message in place
     */
    protected function __construct(
        public readonly TestBench $bench,
        public readonly Kind $kind,
        public readonly Ending $ending,
        protected int $lease,
        public readonly bool $hasAQueue,
    ) {
        $this->queue = new WatchedQueue($bench->executions);
        $this->midStream = new MidBody();
        $this->journal = new StoreJournal();
        $this->fault = StoreFault::none();
        $this->beforeStoreOperation = static function (): void {
        };

        $this->cloud = (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($this->store(...))
            ->register(
                self::DEFINITION,
                fn (): Workflow => $this->tale($ending)->subscribe(WorkflowEnd::class, function (WorkflowEnd $end): void {
                    $this->attemptsOpened[] = (int) $end->execution?->executionAttempt;
                }),
                leaseTimeout: $lease,
            );

        if ($hasAQueue) {
            $this->cloud->setDispatcher($this->queue);
        }

        $this->thread = $this->cloud->workflow(self::DEFINITION, self::WORKFLOW_ID);
        $this->open();
        $this->handOver();
    }

    /**
     * @param int $lease seconds
     */
    public static function on(TestBench $bench, Kind $kind, Ending $ending = Ending::Completes, int $lease = self::LEASE): self
    {
        return new self($bench, $kind, $ending, $lease, hasAQueue: true);
    }

    /**
     * A stage whose cloud was given no dispatcher: every message runs in place.
     */
    public static function withoutQueue(TestBench $bench, Kind $kind, Ending $ending = Ending::Completes): self
    {
        return new self($bench, $kind, $ending, self::LEASE, hasAQueue: false);
    }

    /**
     * The segment of the stage, prepared and not yet used.
     */
    public function prepare(): PendingSegment
    {
        return match ($this->kind) {
            Kind::Start => $this->thread->start(),
            Kind::Answer => $this->thread->submitInputs(self::ANSWER),
            Kind::Signal => $this->thread->signal(TaleWorkflow::EVENT, self::ANSWER),
        };
    }

    /**
     * The segment of the stage as a stream nobody has taken a step of yet.
     *
     * @return Generator<int, object, mixed, WorkflowState>
     */
    public function stream(): Generator
    {
        return $this->prepare()->events();
    }

    /**
     * Take a stream to its end, and note at each frame what the queue had taken by then.
     *
     * @param Generator<int, object, mixed, WorkflowState> $events
     */
    public function take(Generator $events): Taken
    {
        return Taken::from($events, $this->verbsQueued(...));
    }

    /**
     * What Neuron itself gives for the segment of the stage, with no SDK
     * around it: the same workflow, bound to the same workflow ID and run ID
     * on the store of another bench, taken through Neuron's own events().
     *
     * @param (Closure(Workflow): mixed)|null $configure what the callback of the application does to the workflow
     * @return Generator<int, object, mixed, WorkflowState>
     */
    public function withoutTheSdk(TestBench $apart, string $runId, ?Closure $configure = null): Generator
    {
        $workflow = (new TaleWorkflow($apart->executions, $this->kind->continuesARun(), $this->ending, new MidBody()))
            ->for(self::WORKFLOW_ID)
            ->setPersistence(($apart->persistence)())
            ->setLeaseTimeout($this->lease);

        if ($this->kind->continuesARun()) {
            $workflow->run(ExecutionRequest::start(new StartEvent(), $runId));
        }

        if ($configure instanceof Closure) {
            $configure($workflow);
        }

        return $workflow->events($this->kind->request($runId, self::ANSWER));
    }

    /**
     * The workflow ID of the stage as another process of the application
     * holds it: on the same store, with no queue, so that it runs every
     * message in place, and with a platform of its own, so that the platform
     * of the bench receives only what the stage sent.
     *
     * @param Ending|null $ending how the last node ends a segment run there; null: as on the stage
     */
    public function elsewhere(?Ending $ending = null): ManagedWorkflow
    {
        return (new NeuronCloud(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new FakePlatform()))
            ->setPersistence($this->bench->persistence)
            ->register(self::DEFINITION, fn (): Workflow => $this->tale($ending ?? $this->ending), leaseTimeout: $this->lease)
            ->workflow(self::DEFINITION, self::WORKFLOW_ID);
    }

    /**
     * Give the workflow ID to a run that another process started: it ended
     * there, or another request is still streaming it.
     */
    public function hold(Holder $holder): void
    {
        match ($holder) {
            Holder::Suspended => $this->elsewhere(Ending::Waits)->start()->dispatch(),
            Holder::Failed => $this->elsewhere(Ending::Fails)->start()->dispatch(),
            Holder::Running, Holder::Dead => $this->streamElsewhere(),
            Holder::Completed => $this->tale(Ending::Completes)
                ->for(self::WORKFLOW_ID)
                ->setPersistence(($this->bench->persistence)())
                ->retainCompletionUntilAcknowledged()
                ->run(),
        };

        if ($holder === Holder::Dead) {
            $this->outliveTheLease();
        }
    }

    /**
     * Another request gets to the workflow ID before the segment of the
     * stage is admitted, so that Neuron refuses it: a start then finds a
     * suspended run there; an answer or a signal was already given.
     */
    public function letAnotherRequestGetThereFirst(): void
    {
        if ($this->kind->continuesARun()) {
            $this->elsewhere()->submitInputs(self::ANSWER)->dispatch();

            return;
        }

        $this->hold(Holder::Suspended);
    }

    /**
     * The other requests stop streaming: Neuron fails the runs they were executing.
     */
    public function release(): void
    {
        $this->streamedElsewhere = [];
    }

    /**
     * Let the worker of the stage run what is queued.
     *
     * @return array<int, Report|CloudException>
     */
    public function drain(): array
    {
        $received = count($this->bench->platform->received());
        $results = $this->queue->runAll($this->cloud);
        $this->requestsOfTheWorker += count($this->bench->platform->received()) - $received;

        return $results;
    }

    /**
     * Let the worker of the stage run one message that the platform had queued.
     */
    public function work(Invocation $message): Report
    {
        $received = count($this->bench->platform->received());

        try {
            return $this->cloud->invoke($message);
        } finally {
            $this->requestsOfTheWorker += count($this->bench->platform->received()) - $received;
        }
    }

    /**
     * From now on the queue of the stage throws for one kind of message and takes every other.
     *
     * @return RuntimeException what the queue throws
     */
    public function closeQueueTo(Verb $verb): RuntimeException
    {
        $down = new RuntimeException("The queue is down for '{$verb->value}'.");
        $this->queue->beforeTaking(static function (Invocation $handed) use ($verb, $down): void {
            if ($handed->verb === $verb) {
                throw $down;
            }
        });

        return $down;
    }

    /**
     * From now on the queue of the stage takes every message again.
     */
    public function openQueue(): void
    {
        $this->queue->beforeTaking(static function (): void {
        });
    }

    /**
     * @return Invocation[] the messages the queue took since the stage was handed over, in order
     */
    public function queued(): array
    {
        return array_slice($this->queue->taken(), $this->takenBefore);
    }

    /**
     * @return Verb[] the kind of each of those messages, in order
     */
    public function verbsQueued(): array
    {
        return array_map(static fn (Invocation $message): Verb => $message->verb, $this->queued());
    }

    /**
     * The only announcement the queue took since the stage was handed over.
     */
    public function announce(): Invocation
    {
        return $this->only(Verb::Announce);
    }

    /**
     * The only observe the queue took since the stage was handed over.
     */
    public function observe(): Invocation
    {
        return $this->only(Verb::Observe);
    }

    /**
     * How many node bodies ran since the stage was handed over.
     */
    public function bodiesRun(): int
    {
        return $this->bench->executions->total() - $this->bodiesRunBefore;
    }

    /**
     * How many writes the store of the stage was asked for since the stage was handed over, granted or not.
     */
    public function writes(): int
    {
        return $this->journal->exposedTo(FaultMode::AfterWrite) - $this->writesBefore;
    }

    /**
     * @return int[] the attempt of every segment Neuron opened since the stage was handed over, as Neuron stamped it
     */
    public function attemptsOpened(): array
    {
        return array_slice($this->attemptsOpened, $this->openedBefore);
    }

    /**
     * @return array<int, array{string, array<string, mixed>}> the path and the body of every request the platform
     *         received since the stage was handed over, in order
     */
    public function requests(): array
    {
        $requests = [];
        foreach (array_slice($this->bench->platform->received(), $this->requestsBefore) as $request) {
            $requests[] = [(string) parse_url($request->uri, PHP_URL_PATH), is_array($request->body) ? $request->body : []];
        }

        return $requests;
    }

    /**
     * How many of those requests were made by the process that streams, and not by the worker of the stage.
     */
    public function requestsOfTheCaller(): int
    {
        return count($this->requests()) - $this->requestsOfTheWorker;
    }

    /**
     * From now on the store fails as the fault says, for every workflow the stage builds.
     */
    public function failStore(StoreFault $fault): void
    {
        $this->fault = $fault;
    }

    /**
     * From now on the test steps into the store of the stage just before
     * each operation, also for a workflow that was built before.
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
        return ($this->bench->persistence)()->get('__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID), 'starts');
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

    protected function tale(Ending $ending): TaleWorkflow
    {
        return new TaleWorkflow($this->bench->executions, $this->kind->continuesARun(), $ending, $this->midStream);
    }

    /**
     * A worker runs what a continuation continues: the run then waits for its reader.
     */
    protected function open(): void
    {
        if ($this->kind->continuesARun()) {
            $this->thread->start()->dispatch();
            $this->queue->runAll($this->cloud);
        }
    }

    /**
     * What happened so far set the stage: a test sees only what comes after.
     */
    protected function handOver(): void
    {
        $this->takenBefore = count($this->queue->taken());
        $this->bodiesRunBefore = $this->bench->executions->total();
        $this->writesBefore = $this->journal->exposedTo(FaultMode::AfterWrite);
        $this->openedBefore = count($this->attemptsOpened);
        $this->requestsBefore = count($this->bench->platform->received());
    }

    /**
     * Another request starts a run and holds its first word: the run is executing under its lease.
     */
    protected function streamElsewhere(): void
    {
        $stream = $this->elsewhere()->start()->events();
        $stream->current();

        $this->streamedElsewhere[] = $stream;
    }

    protected function only(Verb $verb): Invocation
    {
        $found = null;

        foreach ($this->queued() as $message) {
            if ($message->verb === $verb) {
                Assert::assertNull($found, "The queue took more than one '{$verb->value}'.");
                $found = $message;
            }
        }

        Assert::assertInstanceOf(Invocation::class, $found, "The queue took no '{$verb->value}'.");

        return $found;
    }
}
