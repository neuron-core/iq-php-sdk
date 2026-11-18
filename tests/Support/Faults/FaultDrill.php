<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use DateTimeImmutable;
use Fiber;
use LogicException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;

use function array_count_values;
use function array_reverse;
use function array_slice;
use function base64_encode;
use function count;
use function file;
use function is_array;
use function serialize;
use function str_ends_with;
use function time;
use function usleep;

use const FILE_IGNORE_NEW_LINES;

/**
 * One run of a stub workflow, with this class in the roles around the SDK:
 * the platform, which sends orders and derives its wakes from the snapshots
 * it is shown, as appendix B has it; the worker, which gives invoke() each
 * order; and the store, which it can make fail during one order. The platform
 * records every report, so no job leaves an observe in the queue.
 *
 * Four definitions are registered. Wakes alone bring three of them to
 * their end: "greeting" completes in its first segment; "flaky" fails in its
 * first and completes when retried; "order" waits for an event until a
 * deadline that has passed, then for a timer that is due. The fourth,
 * "payment", waits for the same event with no deadline: no wake continues
 * it, only the signal of the application.
 */
final class FaultDrill
{
    public const WORKFLOW_ID = 'order:123';

    public const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /** Microseconds between two looks at the clock while a lease runs out. */
    public const TICK = 20_000;

    /** Seconds. The shortest lease: a run a fault left running is dead within a second. */
    protected const LEASE = 1;

    /** A clean run takes two wakes and a fault adds a retry or two: more than this goes round in circles. */
    protected const WAKES_AT_MOST = 10;

    public readonly StoreJournal $journal;

    /** The start of the run. It keeps one use-by time for the life of its run ID. */
    public readonly Invocation $start;

    protected TestBench $bench;

    protected StoreFault $fault;

    /** @var Sighting[] */
    protected array $sightings = [];

    protected int $requestsSighted = 0;

    protected int $wakes = 0;

    /**
     * @param string $definition "greeting", "flaky", "order" or "payment"
     * @param string $origin who issues the start: the wakes always come from the platform
     */
    public function __construct(string $backend, protected string $definition, string $origin = Origin::PLATFORM)
    {
        $this->bench = TestBench::on($backend);
        $this->journal = new StoreJournal();
        $this->fault = StoreFault::none();
        $this->start = Invocation::fromArray([
            'invocationId' => 'inv_start',
            'verb' => Verb::Start->value,
            'origin' => $origin,
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 900,
        ]);

        $executions = $this->bench->executions;
        $this->bench->iq
            ->setPersistence(fn (): PersistenceInterface => new FaultyPersistence(($this->bench->persistence)(), $this->journal, $this->fault))
            ->register('greeting', static fn (): CompletingWorkflow => new CompletingWorkflow($executions), leaseTimeout: self::LEASE)
            ->register('flaky', static fn (): FlakyWorkflow => new FlakyWorkflow($executions, failures: 1), leaseTimeout: self::LEASE)
            ->register(
                'order',
                static fn (): WaitingWorkflow => new WaitingWorkflow($executions, new DateTimeImmutable('-1 minute')),
                leaseTimeout: self::LEASE,
            )
            ->register('payment', static fn (): WaitingWorkflow => new WaitingWorkflow($executions), leaseTimeout: self::LEASE);
    }

    /**
     * Play the run over a healthy store until that many orders were
     * delivered: the start, then the wake each snapshot calls for, or the
     * signal of the application when the run waits for an event.
     *
     * @return Invocation the order that comes next
     */
    public function orderAfter(int $delivered): Invocation
    {
        $order = $this->start;

        while ($delivered-- > 0) {
            $this->deliver($order);
            $order = $this->wakeCalledFor() ?? $this->signalAwaited()
                ?? throw new LogicException('The run ended before the order under test.');
        }

        return $order;
    }

    /**
     * Give an order to invoke(), over a store the fault may fail meanwhile.
     * The store is healthy again as soon as invoke() ends.
     */
    public function deliver(Invocation $order, ?StoreFault $fault = null): void
    {
        $this->fault = $fault ?? StoreFault::none();

        try {
            $this->bench->iq->invoke($order);
            $thrown = null;
        } catch (IQException $notDealtWith) {
            $thrown = $notDealtWith->reason;
        } finally {
            $this->fault = StoreFault::none();
        }

        $this->sight($thrown);
    }

    /**
     * Send wake after wake, each fenced from the last snapshot, until the run
     * ends or no wake can continue it.
     */
    public function followSnapshots(): void
    {
        while ($this->wakes < self::WAKES_AT_MOST && ($wake = $this->wakeCalledFor()) instanceof Invocation) {
            $this->deliver($wake);
        }
    }

    public function conclude(string $label, StoreFault $fault): FaultCase
    {
        $struck = $fault->struck();
        $executed = file($this->bench->executions->path, FILE_IGNORE_NEW_LINES) ?: [];

        return new FaultCase(
            $struck instanceof StoreOperation ? "{$label}, {$struck->describe()}" : $label,
            $struck,
            $fault->committed(),
            $this->sightings,
            array_count_values($executed),
            $executed === [] ? null : $executed[count($executed) - 1],
            $this->journal->ignitions(self::WORKFLOW_ID),
            $this->journal->removals(self::WORKFLOW_ID) > 0,
            $this->stored(),
            $this->bench->platform->received(),
        );
    }

    public function close(): void
    {
        $this->bench->cleanUp();
    }

    /**
     * Record the reports the platform received since the last look, beside what is true now.
     *
     * @param Code|null $thrown the reason invoke() threw with after its job posted them
     */
    protected function sight(?Code $thrown): void
    {
        $received = $this->bench->platform->received();

        foreach (array_slice($received, $this->requestsSighted) as $request) {
            if (str_ends_with($request->uri, '/v1/reports') && is_array($request->body)) {
                $this->sightings[] = new Sighting(
                    $request->body,
                    $this->stored(),
                    $this->journal->removals(self::WORKFLOW_ID) > 0,
                    $thrown,
                );
            }
        }

        $this->requestsSighted = count($received);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function stored(): ?array
    {
        return StoredRun::of(($this->bench->persistence)(), self::WORKFLOW_ID);
    }

    /**
     * The wake the platform sends for the last snapshot it was shown: at the
     * time of a wait (rule 8 of appendix B), after a failure (9) and when the
     * lease of a running run has passed (11). Null when the run is gone, or
     * when nothing but an event or a person can continue it.
     */
    protected function wakeCalledFor(): ?Invocation
    {
        $run = $this->lastSnapshot();

        if ($run === null || $run['runId'] !== self::RUN_ID) {
            return null;
        }

        $due = match ($run['status']) {
            'failed' => true,
            'suspended' => $run['wait']['wakeAt'] !== null && $run['wait']['wakeAt'] <= time(),
            'running' => $run['leaseExpiresAt'] !== null && $this->outlives($run['leaseExpiresAt']),
            default => false,
        };

        return $due ? Invocation::fromArray([
            'invocationId' => 'inv_wake_' . ++$this->wakes,
            'verb' => Verb::Wake->value,
            'origin' => Origin::PLATFORM,
            'definition' => $this->definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $run['runId'],
            'attempt' => $run['attempt'],
        ]) : null;
    }

    /**
     * The signal the application sends for the event the last snapshot shows
     * the run waiting for, fenced as an answer is: with what that snapshot showed.
     */
    protected function signalAwaited(): ?Invocation
    {
        $run = $this->lastSnapshot();

        return isset($run['wait']['eventName']) ? Invocation::fromArray([
            'invocationId' => 'inv_signal',
            'verb' => Verb::Signal->value,
            'origin' => Origin::APPLICATION,
            'definition' => $this->definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $run['runId'],
            'attempt' => $run['attempt'],
            'signal' => $run['wait']['eventName'],
            'payload' => base64_encode(serialize(['amount' => 10])),
        ]) : null;
    }

    /**
     * @return array<string, mixed>|null the run of the latest report whose snapshot was read
     */
    protected function lastSnapshot(): ?array
    {
        foreach (array_reverse($this->sightings) as $sighting) {
            if ($sighting->report['snapshotRead']) {
                return $sighting->report['run'];
            }
        }

        return null;
    }

    /**
     * Wait for a lease to pass, in real time. A lease that ends later than
     * the one this drill registers is not waited for. A drill played in a
     * fiber hands the wait to whoever plays it, so that several can wait together.
     */
    protected function outlives(int $leaseExpiresAt): bool
    {
        if ($leaseExpiresAt > time() + self::LEASE) {
            return false;
        }

        while (time() < $leaseExpiresAt) {
            Fiber::getCurrent() instanceof Fiber ? Fiber::suspend() : usleep(self::TICK);
        }

        return true;
    }
}
