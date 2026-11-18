<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use Closure;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\IQ\Tests\Stub\Flows\RedisDispatcher;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\Faults\StoreFault;
use NeuronCore\IQ\Tests\Support\Faults\StoreJournal;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function end;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_PATH;

/**
 * A whole flow of an application, played against the fake platform with the
 * queue and the worker of the plain PHP example. Every body the flow sent the
 * platform must respect the protocol.
 */
abstract class FlowTestCase extends IQTestCase
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    protected FakeRedis $redis;

    protected PlainPhpWorker $worker;

    protected Transcript $transcript;

    /** What the store of the application was asked to keep. */
    protected StoreJournal $journal;

    /** How many requests the platform received while a web request of the application was being served. */
    protected int $sentWhileServingRequests = 0;

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->transcript)) {
            unlink($this->transcript->path);
        }
    }

    /**
     * The bootstrap of the plain PHP example: one store, and the Redis list as the queue.
     */
    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);
        $this->redis = new FakeRedis();
        $this->worker = new PlainPhpWorker($this->redis);
        $this->transcript = new Transcript((string) tempnam(sys_get_temp_dir(), 'iq-'));
        $this->journal = new StoreJournal();

        $persistence = $bench->persistence;
        $bench->iq
            ->setDispatcher(new RedisDispatcher($this->redis))
            ->setPersistence(fn (): PersistenceInterface => new FaultyPersistence($persistence(), $this->journal, StoreFault::none()));

        return $bench;
    }

    /**
     * Serve one web request of the application, and count what the platform receives meanwhile.
     *
     * @template TAnswer
     * @param Closure(): TAnswer $handler
     * @return TAnswer
     */
    protected function inARequest(Closure $handler): mixed
    {
        $before = count($this->bench->platform->received());

        try {
            return $handler();
        } finally {
            $this->sentWhileServingRequests += count($this->bench->platform->received()) - $before;
        }
    }

    /**
     * The worker of the example deals with everything that waits in the queue.
     */
    protected function work(): void
    {
        $this->worker->work($this->bench->iq);
    }

    /**
     * What the platform does when a timer comes due or a run has failed: it
     * sends a wake fenced with the run ID and the attempt of the last report
     * it recorded for the workflow ID, which the endpoint puts on the queue.
     */
    protected function platformWakes(string $workflowId): void
    {
        $reports = $this->reportsOf($workflowId);
        $last = end($reports);
        self::assertIsArray($last, "The platform has no report to fence a wake of '{$workflowId}' with.");
        self::assertIsArray($last['run'], "The last report of '{$workflowId}' shows no run to wake.");

        $wake = new Invocation(
            'inv_wake_' . $last['run']['attempt'],
            Verb::Wake,
            Origin::PLATFORM,
            $last['definition'],
            $workflowId,
            $last['run']['runId'],
            $last['run']['attempt'],
        );

        $this->redis->rPush(PlainPhpWorker::QUEUE, json_encode($wake->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return Invocation[] what a list of the queue holds now, in order
     */
    protected function waitingIn(string $list): array
    {
        return array_map(
            static fn (string $job): Invocation => Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR)),
            $this->redis->lRange($list),
        );
    }

    /**
     * @return array<int, array<string, mixed>> the reports the platform was sent about a workflow ID, in order
     */
    protected function reportsOf(string $workflowId): array
    {
        return $this->sentAbout($workflowId, self::REPORTS);
    }

    /**
     * @return array<int, array<string, mixed>> the announcements the platform was sent about a workflow ID, in order
     */
    protected function announcementsOf(string $workflowId): array
    {
        return $this->sentAbout($workflowId, self::ANNOUNCEMENTS);
    }

    /**
     * How the segments of a workflow ID ended, as the platform heard it.
     *
     * @return string[] "verb: outcome" for every report, in order
     */
    protected function outcomesReported(string $workflowId): array
    {
        return array_map(
            static fn (array $report): string => "{$report['verb']}: {$report['outcome']}",
            $this->reportsOf($workflowId),
        );
    }

    /**
     * The run holding a workflow ID, as the store shows it.
     */
    protected function holder(string $workflowId): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect($workflowId);
    }

    /**
     * Whatever a flow planted, in clear or in the form a queued message
     * carries it, must be in no request the platform received.
     *
     * @param array<string, string> $planted where it was planted => the marker
     */
    protected function assertNothingReachedThePlatform(array $planted): void
    {
        $received = $this->bench->platform->received();
        self::assertNotSame([], $received, 'The flow sent the platform nothing: there is no boundary to check.');

        $secrets = $planted + $this->carriedInTheQueue();
        $leaks = [];

        foreach ($received as $request) {
            $sent = json_encode([$request->uri, $request->headers, $request->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            foreach ($secrets as $where => $secret) {
                if (str_contains($sent, $secret)) {
                    $leaks[] = "What was planted in {$where} left the application in {$sent}";
                }
            }
        }

        self::assertSame([], $leaks);
    }

    /**
     * @return array<string, string> the start event and the payload of every message the queue was ever handed, as
     *                               the message carries them
     */
    protected function carriedInTheQueue(): array
    {
        $carried = [];

        foreach ($this->redis->everPushed(PlainPhpWorker::QUEUE) as $number => $job) {
            $message = json_decode($job, true, flags: JSON_THROW_ON_ERROR);

            foreach (['event', 'payload'] as $field) {
                if (isset($message[$field])) {
                    $carried["the {$field} that message {$number} of the queue carries"] = $message[$field];
                }
            }
        }

        return $carried;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentAbout(string $workflowId, string $path): array
    {
        $bodies = array_map(
            static fn (HttpRequest $request): array => parse_url($request->uri, PHP_URL_PATH) === $path && is_array($request->body) ? $request->body : [],
            $this->bench->platform->received(),
        );

        return array_values(array_filter($bodies, static fn (array $body): bool => ($body['workflowId'] ?? null) === $workflowId));
    }
}
