<?php

declare(strict_types=1);

/*
 * A process that executes one segment whose node streams a word and then calls exit(). Its argument says how
 * the segment is executed: "run", "events", or "encoded" for events() streamed as example (b) of the design
 * streams them. The shutdown function prints what the process leaves: the notices its queue was handed and the
 * status of the run. Plain PHP and an in-memory store only, so that the script also runs on a PHP built
 * without the extensions the test suite needs.
 */

namespace NeuronCore\Cloud\Tests\Support\Processes;

use Generator;
use InvalidArgumentException;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Tests\Stub\Units\ExitingNode;
use NeuronCore\Cloud\Tests\Stub\Units\FramingAdapter;
use NeuronCore\Cloud\Tests\Support\Runner\InspectableDispatcher;

use function array_map;
use function dirname;
use function implode;
use function iterator_to_array;
use function register_shutdown_function;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const WORKFLOW_ID = 'order:123';

$store = new InMemoryPersistence();
$queue = new InspectableDispatcher();
$thread = (new NeuronCloud('test-api-key', null, 'https://platform.test', new FakePlatform()))
    ->setPersistence(static fn (): PersistenceInterface => $store)
    ->setDispatcher($queue)
    ->register('farewell', static fn (): Workflow => (new Workflow())->addNodes([new ExitingNode()]))
    ->workflow('farewell', WORKFLOW_ID)
    ->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): FramingAdapter => new FramingAdapter()));

register_shutdown_function(static function () use ($store, $queue): void {
    $handed = array_map(static fn (Invocation $notice): string => $notice->verb->value, $queue->dispatched());
    $run = (new WorkflowEngine($store))->inspect(WORKFLOW_ID);

    echo 'shutdown: the queue was handed ', implode(', ', $handed), '; the run is ', $run?->status->value ?? 'gone';
});

$segment = $thread->start();
$streamedAsExampleB = static function (Generator $events): Generator {
    yield from SSEEncoder::encode($events);
};

match ($argv[1]) {
    'run' => $segment->run(),
    'events' => iterator_to_array($segment->events(), false),
    'encoded' => iterator_to_array($streamedAsExampleB($segment->events()), false),
    default => throw new InvalidArgumentException("The script does not know how to execute a segment with '{$argv[1]}'."),
};

echo 'The node did not end the process. ';
