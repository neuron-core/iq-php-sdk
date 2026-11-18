<?php

declare(strict_types=1);

/*
 * One queue worker in a process of its own. It rebuilds the IQ of the bench that spawned it from its
 * only argument, says "ready", waits on its standard input for the instant to act at, gives its invocation to
 * invoke() and prints the report that came back.
 */

namespace NeuronCore\IQ\Tests\Support\Concurrency;

use DateTimeImmutable;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Concurrency\DyingPersistence;
use NeuronCore\IQ\Tests\Stub\Concurrency\StallingWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\ExecutionLog;
use NeuronCore\IQ\Tests\Support\TestBench;

use function dirname;
use function fgets;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function pcntl_alarm;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const STDIN;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A worker that a failed test left stalled must not outlive it.
pcntl_alarm(30);

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$executions = new ExecutionLog($job['executions']);
$gate = new Gate($job['gate']);
$store = ProcessBench::open($job['store']);
if ($job['diesAfterClaim']) {
    $store = new DyingPersistence($store);
}

$iq = (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, new SharedPlatform($job['platform'])))
    ->setPersistence(static fn (): PersistenceInterface => $store)
    ->register(
        ProcessBench::COMPLETING,
        static fn (): CompletingWorkflow => new CompletingWorkflow($executions),
        leaseTimeout: $job['lease'],
    )
    ->register(
        ProcessBench::WAITING,
        static fn (): WaitingWorkflow => new WaitingWorkflow($executions),
        leaseTimeout: $job['lease'],
    )
    ->register(
        ProcessBench::STALLING,
        static fn (): StallingWorkflow => new StallingWorkflow($executions, $gate),
        leaseTimeout: $job['lease'],
    )
    ->register(
        ProcessBench::STALLING_AFTER_TIMER,
        static fn (): StallingWorkflow => new StallingWorkflow($executions, $gate, new DateTimeImmutable('@0')),
        leaseTimeout: $job['lease'],
    );

echo "ready\n";

$instant = fgets(STDIN);
if ($instant === false) {
    // The test went away before it gave the order.
    exit(1);
}
usleep((int) max(0, ((float) $instant - microtime(true)) * 1_000_000));

echo json_encode($iq->invoke(Invocation::fromArray($job['invocation']))->toArray(), JSON_THROW_ON_ERROR), "\n";
