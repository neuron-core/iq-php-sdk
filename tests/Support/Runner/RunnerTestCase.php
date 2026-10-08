<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Runner;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\RacingPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;

use function base64_encode;
use function hash;
use function is_array;
use function parse_url;
use function serialize;
use function time;
use function usleep;

use const PHP_URL_PATH;

/**
 * A test of the runner: invocations are built as a queue would hand them to
 * invoke(), and the test reads what reached the platform, the queue and the store.
 */
abstract class RunnerTestCase extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const PLATFORM = 'platform';

    protected const APPLICATION = 'application';

    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    /** The queue the cloud hands its messages to, in place of the bench's own, whose messages cannot be read. */
    protected InspectableDispatcher $queue;

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    /**
     * A bench whose queue can be read, with "greeting" registered: a workflow that completes in one segment.
     */
    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);
        $executions = $bench->executions;

        $this->queue = new InspectableDispatcher();
        $bench->cloud
            ->setDispatcher($this->queue)
            ->register('greeting', static fn (): CompletingWorkflow => new CompletingWorkflow($executions));

        return $bench;
    }

    /**
     * A start as it waits in the queue: the platform's, or one the application dispatched.
     *
     * @param array<string, mixed> $fields the fields that differ
     */
    protected function start(string $definition, string $origin = self::PLATFORM, array $fields = []): Invocation
    {
        return Invocation::fromArray($fields + [
            'invocationId' => 'inv_start',
            'verb' => Verb::Start->value,
            'origin' => $origin,
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => self::RUN_ID,
            'expiresAt' => time() + 900,
        ]);
    }

    /**
     * A wake fenced with the run ID and the attempt a report showed.
     */
    protected function wake(string $definition, Report $reported, string $origin = self::PLATFORM): Invocation
    {
        return $this->continuation(Verb::Wake, $definition, $reported, $origin);
    }

    /**
     * An answer the application dispatched, fenced with the run ID and the attempt a report showed.
     *
     * @param array<string, mixed> $payload
     */
    protected function answer(string $definition, Report $reported, array $payload): Invocation
    {
        return $this->continuation(Verb::Answer, $definition, $reported, self::APPLICATION, ['payload' => base64_encode(serialize($payload))]);
    }

    /**
     * A signal the application dispatched, fenced with the run ID and the attempt a report showed.
     *
     * @param array<string, mixed> $payload
     */
    protected function signal(string $definition, Report $reported, string $event, array $payload = []): Invocation
    {
        return $this->continuation(
            Verb::Signal,
            $definition,
            $reported,
            self::APPLICATION,
            ['signal' => $event, 'payload' => base64_encode(serialize($payload))],
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function continuation(Verb $verb, string $definition, Report $reported, string $origin, array $fields = []): Invocation
    {
        return Invocation::fromArray($fields + [
            'invocationId' => 'inv_' . $verb->value . '_' . $reported->run['attempt'],
            'verb' => $verb->value,
            'origin' => $origin,
            'definition' => $definition,
            'workflowId' => self::WORKFLOW_ID,
            'runId' => $reported->run['runId'],
            'attempt' => $reported->run['attempt'],
        ]);
    }

    /**
     * An observe as a worker queues it for a job that died: it names the segment and cannot say how it ended.
     */
    protected function observeOf(Invocation $segment): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'obs_' . $segment->invocationId,
            'verb' => Verb::Observe->value,
            'origin' => $segment->origin,
            'definition' => $segment->definition,
            'workflowId' => $segment->workflowId,
            'runId' => $segment->runId,
            'body' => ['invocationId' => $segment->invocationId, 'verb' => $segment->verb->value],
        ]);
    }

    /**
     * What invoke() throws for an invocation it did not deal with.
     */
    protected function thrownBy(Invocation $invocation): CloudException
    {
        try {
            $this->bench->cloud->invoke($invocation);
        } catch (CloudException $notDealtWith) {
            return $notDealtWith;
        }

        self::fail("invoke() returned a report for the '{$invocation->verb->value}' it should have thrown for.");
    }

    /**
     * From now on the test steps into every store the cloud builds, just before each operation.
     *
     * @param Closure(string $operation, string $partition): void $before
     */
    protected function interceptStore(Closure $before): void
    {
        $persistence = $this->bench->persistence;

        $this->bench->cloud->setPersistence(
            static fn (): PersistenceInterface => new InterceptedPersistence($persistence(), $before),
        );
    }

    /**
     * From now on the store fails whenever $operation reaches $partition.
     *
     * @param string $operation a method of PersistenceInterface
     */
    protected function failStoreOn(string $operation, string $partition): void
    {
        $this->interceptStore(static function (string $attempted, string $reached) use ($operation, $partition): void {
            if ($attempted === $operation && $reached === $partition) {
                throw new StoreUnavailable("The store failed on {$operation}.");
            }
        });
    }

    /**
     * From now on another process claims a start of the same workflow ID just before each of the first
     * $races conditional writes of the cloud.
     */
    protected function rivalsWinTheLedger(int $races): void
    {
        $rival = new Ledger($this->bench->persistence);
        $rivals = 0;

        $store = new RacingPersistence(($this->bench->persistence)(), $races, static function () use ($rival, &$rivals): void {
            $rival->claim(self::WORKFLOW_ID, '1-rival-' . ++$rivals, time() + 900);
        });

        $this->bench->cloud->setPersistence(static fn (): PersistenceInterface => $store);
    }

    /**
     * Neuron and the ledger read time(), so only the real clock can make a use-by time or a lease pass.
     */
    protected function holdUntil(int $instant): void
    {
        while (time() < $instant) {
            usleep(50_000);
        }
    }

    /**
     * Where the ledger keeps the starts of the workflow ID of these tests.
     */
    protected function ledgerPartition(): string
    {
        return '__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID);
    }

    /**
     * The ledger record of the workflow ID as the store holds it; null when nothing was ever written.
     */
    protected function ledgerRecord(): ?string
    {
        return ($this->bench->persistence)()->get($this->ledgerPartition(), 'starts');
    }

    protected function isClaimed(string $runId = self::RUN_ID): bool
    {
        return (new Ledger($this->bench->persistence))->has(self::WORKFLOW_ID, $runId);
    }

    /**
     * @return array<int, array<string, mixed>> the announcements the cloud posted, recorded by the platform or not, in order
     */
    protected function announcementsSent(): array
    {
        return $this->sentTo(self::ANNOUNCEMENTS);
    }

    /**
     * @return array<int, array<string, mixed>> the reports the cloud posted, recorded by the platform or not, in order
     */
    protected function reportsSent(): array
    {
        return $this->sentTo(self::REPORTS);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentTo(string $path): array
    {
        $bodies = [];
        foreach ($this->bench->platform->received() as $request) {
            if (parse_url($request->uri, PHP_URL_PATH) === $path && is_array($request->body)) {
                $bodies[] = $request->body;
            }
        }

        return $bodies;
    }
}
