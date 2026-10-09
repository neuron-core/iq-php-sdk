<?php

declare(strict_types=1);

/*
 * A process that streams one segment and forks while it does. Its first argument is the file its workflows
 * count their node executions in; its second says where the fork happens:
 *   "between-two-frames": the consumer forks while it holds the first word, the child ends, the parent takes the rest;
 *   "on-the-event-of-the-wait": the consumer forks while it holds the last event of a segment that suspended;
 *   "before-the-first-frame": the stream is prepared, a child takes all of it, and the parent releases its copy.
 * Parent and child share the queue, which prints each notice it is handed with the process that handed it.
 */

namespace NeuronCore\Cloud\Tests\Support\Processes;

use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Tests\Stub\NarratingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Units\SharedPrintingQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;

use function dirname;
use function iterator_to_array;
use function pcntl_alarm;
use function pcntl_fork;
use function pcntl_waitpid;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A process that a failed test left waiting must not outlive it.
pcntl_alarm(30);

[, $log, $where] = $argv;

$store = new InMemoryPersistence();
$executions = new ExecutionLog($log);
$cloud = (new NeuronCloud('test-api-key', null, 'https://platform.test', new FakePlatform()))
    ->setPersistence(static fn (): PersistenceInterface => $store)
    ->setDispatcher(new SharedPrintingQueue())
    ->register('narration', static fn (): Workflow => new NarratingWorkflow($executions))
    ->register('order', static fn (): Workflow => new WaitingWorkflow($executions));

// The child ends as a process ends: PHP releases its copy of everything the parent held.
$forkAChildThatEnds = static function (): void {
    $child = pcntl_fork();

    if ($child === 0) {
        exit(0);
    }

    pcntl_waitpid($child, $status);
};

if ($where === 'before-the-first-frame') {
    $events = $cloud->workflow('narration', 'order:123')->start()->events();
    $child = pcntl_fork();

    if ($child === 0) {
        iterator_to_array($events, false);

        exit(0);
    }

    pcntl_waitpid($child, $status);
    unset($events);

    exit(0);
}

$events = $cloud->workflow($where === 'on-the-event-of-the-wait' ? 'order' : 'narration', 'order:123')->start()->events();
$forked = false;

foreach ($events as $event) {
    if (!$forked && ($where === 'between-two-frames' || $event instanceof InterruptEvent)) {
        $forked = true;
        $forkAChildThatEnds();
    }
}
