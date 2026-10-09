<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Questions;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Flows\SupportAgent;
use NeuronCore\Cloud\Tests\Stub\Questions\SleepingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\Cloud\Tests\Support\Faults\StoredRun;
use NeuronCore\Cloud\Tests\Support\TestBench;

use function hash;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;

/**
 * A test of what the endpoint answers in the request: an inspect, an abandon
 * and a ping. It brings the workflow ID under a holder and asks while it is
 * held, and reads the store and the ledger without the SDK to say what is true.
 */
abstract class QuestionsTestCase extends EndpointTestCase
{
    protected const AGENT = 'support-agent';

    protected const THREAD_ID = 'thread:42';

    protected const CALL_ID = 'call_refund_1';

    /** The input of the tool call that waits for a person: no answer of the endpoint may repeat it. */
    protected const IBAN = 'IT60X0542811101000000123456';

    /** A time no test lives to see: the first second of the year 2100. */
    protected const FAR_AHEAD = 4102444800;

    protected const LEDGER_PARTITION_PREFIX = '__neuron_cloud:';

    /** Microseconds between two looks at the clock while a lease runs out. */
    protected const TICK = 20_000;

    /** What an abandon is answered when it leaves no run and the run ID it names can never start. */
    protected const DISCARDED = [200, ['run' => null, 'claimed' => true, 'snapshotRead' => true]];

    /** What an abandon is answered when it finds its run ID in the ledger and no run: it cannot tell why. */
    protected const ENTRY_WAS_THERE = [409, ['code' => 'stale_run', 'run' => null, 'claimed' => true, 'snapshotRead' => true]];

    /** @var array<int, Report|CloudException> what invoke() returned or threw for the start that brought the holder */
    protected array $settled = [];

    /**
     * The rows of a data provider, each on each backend.
     *
     * @param array<string, array<int, mixed>> $rows what a test is given after the backend, by the name of the case
     * @return array<string, array<int, mixed>>
     */
    protected static function onEachBackend(array $rows): array
    {
        $onBackends = [];
        foreach (TestBench::backends() as $backendName => [$backend]) {
            foreach ($rows as $name => $row) {
                $onBackends["{$name} on {$backendName}"] = [$backend, ...$row];
            }
        }

        return $onBackends;
    }

    /**
     * The rows of a data provider: each holder on each backend.
     *
     * @return array<string, array<int, mixed>>
     */
    protected static function onBackends(Holder ...$holders): array
    {
        $rows = [];
        foreach ($holders as $holder) {
            $rows[$holder->value] = [$holder];
        }

        return self::onEachBackend($rows);
    }

    /**
     * The bootstrap of an application that has started nothing yet: "order-fulfilment" waits for a payment.
     */
    protected function ordersOn(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->cloud->register(static::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        return $bench;
    }

    /**
     * The platform sends a start whose worker claims it and then loses the store at Neuron's first write: the
     * run ID is in the ledger and no run was created.
     *
     * @return Report|CloudException what invoke() returned or threw for the start
     */
    protected function startThatWasClaimedAndNeverRan(): Report|CloudException
    {
        $this->deliver($this->start());
        $this->failTheStoreAt(
            static fn (string $operation, string $partition): bool => $operation === 'initializeIfAbsent' && $partition === static::WORKFLOW_ID,
        );
        [$settled] = $this->work();
        $this->bench->cloud->setPersistence($this->bench->persistence);

        return $settled;
    }

    /**
     * Bring the workflow ID of these tests under a holder and ask while it is held: from inside the node of a
     * run that executes, after the segment of one that rests.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $ask
     * @return TAnswer
     */
    protected function whileHeldBy(Holder $holder, Closure $ask): mixed
    {
        $executions = $this->bench->executions;

        return match ($holder) {
            Holder::WaitingForAnEvent => $this->afterTheFirstSegmentOf(
                static fn (): Workflow => new WaitingWorkflow($executions),
                $ask,
            ),
            Holder::Sleeping => $this->afterTheFirstSegmentOf(
                static fn (): Workflow => new SleepingWorkflow(new DateTimeImmutable('@' . self::FAR_AHEAD)),
                $ask,
            ),
            Holder::Failed => $this->afterTheFirstSegmentOf(static fn (): Workflow => new FlakyWorkflow($executions), $ask),
            Holder::Executing => $this->insideTheNodeOfAStart($ask, lease: 600),
            Holder::Dead => $this->insideTheNodeOfAStart(fn (): mixed => $this->onceTheLeaseHasPassed($ask), lease: 1),
            Holder::Unleased => $this->insideARunStartedOutsideTheSdk($ask),
            Holder::Retained => $this->afterACompletionRetainedOutsideTheSdk($ask),
        };
    }

    /**
     * The platform starts the order and a worker runs its first segment, which leaves the run suspended or
     * failed, or completes it.
     *
     * @template TAnswer
     * @param Closure(): Workflow $factory
     * @param (Closure(): TAnswer)|null $ask null: the test asks afterwards
     * @return TAnswer|null
     */
    protected function afterTheFirstSegmentOf(Closure $factory, ?Closure $ask = null): mixed
    {
        $this->bench->cloud->register(static::DEFINITION, $factory);
        $this->deliver($this->start());
        $this->settled = $this->work();

        return $ask instanceof Closure ? $ask() : null;
    }

    /**
     * The platform starts the order and a worker executes it: the test asks from inside the only node, in the
     * same process, while the run is running under its lease.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $ask
     * @param int $lease seconds
     * @return TAnswer
     */
    protected function insideTheNodeOfAStart(Closure $ask, int $lease): mixed
    {
        $executions = $this->bench->executions;
        $answer = null;
        $whileExecuting = static function () use ($ask, &$answer): void {
            $answer = $ask();
        };

        $this->bench->cloud->register(
            static::DEFINITION,
            static fn (): Workflow => new BusyWorkflow($executions, $whileExecuting),
            leaseTimeout: $lease,
        );
        $this->deliver($this->start());
        $this->settled = $this->work();

        return $answer;
    }

    /**
     * Code that does not use the SDK runs the same workflow on the same store, with no lease: the test asks
     * from inside its node.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $ask
     * @return TAnswer
     */
    protected function insideARunStartedOutsideTheSdk(Closure $ask): mixed
    {
        $executions = $this->bench->executions;
        $answer = null;
        $whileExecuting = static function () use ($ask, &$answer): void {
            $answer = $ask();
        };
        $this->bench->cloud->register(static::DEFINITION, static fn (): Workflow => new BusyWorkflow($executions, $whileExecuting));

        try {
            (new BusyWorkflow($executions, $whileExecuting))
                ->for(static::WORKFLOW_ID)
                ->setPersistence(($this->bench->persistence)())
                ->run();
        } catch (WorkflowRefusedException) {
            // The test had the run abandoned under the process that executes it: its last write is refused.
        }

        return $answer;
    }

    /**
     * Code that does not use the SDK ran the same workflow on the same store and keeps its completion.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $ask
     * @return TAnswer
     */
    protected function afterACompletionRetainedOutsideTheSdk(Closure $ask): mixed
    {
        $executions = $this->bench->executions;
        $this->bench->cloud->register(static::DEFINITION, static fn (): Workflow => new CompletingWorkflow($executions));

        (new CompletingWorkflow($executions))
            ->for(static::WORKFLOW_ID)
            ->setPersistence(($this->bench->persistence)())
            ->retainCompletionUntilAcknowledged()
            ->run();

        return $ask();
    }

    /**
     * Wait, in real time, until the lease of the run that holds the workflow ID has passed: its worker is then
     * taken for dead.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $ask
     * @return TAnswer
     */
    protected function onceTheLeaseHasPassed(Closure $ask): mixed
    {
        while ($this->storedRun()['leaseExpiresAt'] > time()) {
            usleep(self::TICK);
        }

        return $ask();
    }

    /**
     * A chat turn stopped at the approval of a refund: the conversation ends on a tool call nobody answered.
     * The turn ran in the request, so its run ID is not in the ledger.
     */
    protected function threadWaitingForAnApproval(): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('refund', self::CALL_ID, ['iban' => self::IBAN])]),
            new AssistantMessage('The order is refunded.'),
        );
        $this->bench->cloud->register(self::AGENT, static fn (): Agent => new SupportAgent($provider, $conversations, $executions));

        $thread = $this->bench->cloud->workflow(self::AGENT, self::THREAD_ID);
        $thread->start(new UserMessage('Refund order 123.'))->run();
        $this->work();

        return $thread;
    }

    /**
     * The rows of a data provider: what the approval request of the thread holds and no answer may carry, on
     * each backend.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function whatAnApprovalHolds(): array
    {
        return self::onEachBackend([
            'an input of the tool' => [self::IBAN],
            'the ID of the tool call' => [self::CALL_ID],
            'the message of the approval' => ['requires approval'],
        ]);
    }

    /**
     * @return string the approval request of the thread in its own JSON form, which no answer may be built from
     */
    protected function approvalAsTheStoreHoldsIt(): string
    {
        $request = (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::THREAD_ID)?->interrupt;

        self::assertInstanceOf(InterruptRequest::class, $request);

        return json_encode($request, JSON_THROW_ON_ERROR);
    }

    /**
     * Address a message of the endpoint to the chat thread instead of the order.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    protected function aboutTheThread(array $message): array
    {
        return ['definition' => self::AGENT, 'workflowId' => self::THREAD_ID] + $message;
    }

    /**
     * The run the store holds for a workflow ID, read with Neuron's engine alone and told in the words of the protocol.
     *
     * @return array<string, mixed>|null
     */
    protected function storedRun(string $workflowId = self::WORKFLOW_ID): ?array
    {
        return StoredRun::of(($this->bench->persistence)(), $workflowId);
    }

    /**
     * The ledger of a workflow ID, read from the store without the SDK.
     *
     * @return array<string, int> run ID => use-by time
     */
    protected function ledger(string $workflowId = self::WORKFLOW_ID): array
    {
        $stored = ($this->bench->persistence)()->get(self::LEDGER_PARTITION_PREFIX . hash('sha256', $workflowId), 'starts');

        return $stored === null ? [] : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return ArrayObject<int, array{string, string}> the method and the partition of every operation the store
     *                                                 receives from now on, in order
     */
    protected function watchTheStore(): ArrayObject
    {
        /** @var ArrayObject<int, array{string, string}> $operations */
        $operations = new ArrayObject();
        $persistence = $this->bench->persistence;

        $this->bench->cloud->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            static function (string $operation, string $partition) use ($operations): void {
                $operations[] = [$operation, $partition];
            },
        ));

        return $operations;
    }

    /**
     * @param iterable<array{string, string}> $operations the method and the partition of each
     * @return string[] the methods of those that reached a ledger, in order
     */
    protected static function askedOfALedger(iterable $operations): array
    {
        $asked = [];
        foreach ($operations as [$method, $partition]) {
            if (str_starts_with($partition, self::LEDGER_PARTITION_PREFIX)) {
                $asked[] = $method;
            }
        }

        return $asked;
    }

    /**
     * From now on the store fails at every operation the test picks, and serves the others.
     *
     * @param Closure(string $operation, string $partition): bool $struck
     */
    protected function failTheStoreAt(Closure $struck): void
    {
        $persistence = $this->bench->persistence;

        $this->bench->cloud->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            static function (string $operation, string $partition) use ($struck): void {
                if ($struck($operation, $partition)) {
                    throw new StoreUnavailable(self::STORE_FAILURE);
                }
            },
        ));
    }

    /**
     * The platform orders the run that holds the workflow ID now abandoned.
     *
     * @param int|null $attemptsAhead how far the attempt it sends is from the one the run is at; null: it sends none
     */
    protected function abandonTheHolder(?int $attemptsAhead = 0): HttpResponse
    {
        $held = $this->storedRun();

        return $this->deliver($this->abandon($held['runId'], $attemptsAhead === null ? null : $held['attempt'] + $attemptsAhead));
    }

    /**
     * @return array{int, array<string, mixed>} the status of an answer and what it says, apart from when it was given
     */
    protected static function answered(HttpResponse $answer): array
    {
        return [$answer->statusCode, self::saidBy($answer)];
    }

    /**
     * @return array<string, mixed> what an answer of the endpoint says, apart from when it was given
     */
    protected static function saidBy(HttpResponse $answer): array
    {
        $fields = self::fieldsOf($answer);
        unset($fields['at']);

        return $fields;
    }
}
