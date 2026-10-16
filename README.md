# Neuron Cloud SDK

Connect the workflows and agents of a [Neuron AI](https://github.com/neuron-core/neuron-ai) application to Neuron Cloud.

Your application keeps running every workflow in its own processes and keeps every run in its own store. Neuron Cloud keeps a list of your runs and a clock: it fires the timers your workflows wait on, retries a run after a failure or a crashed worker, and starts runs on a schedule. Your workflow and agent classes stay ordinary Neuron code: they know nothing about the SDK.

Only coordination data reaches the platform: definition names, workflow and run IDs, statuses, wake-up times and the class of an exception. Workflow state, inputs, prompts, answers and results stay in your application.

## Requirements

- PHP 8.1 or later
- `neuron-core/neuron-ai` 4.1.9 or later
- A workflow store every process shares: Neuron's `DatabasePersistence`, `EloquentPersistence` or `RedisPersistence`
- A durable queue with at least one worker
- A public HTTPS URL the platform can call

## Installation

```bash
composer require neuron-core/cloud-sdk
```

Using Laravel? Install `neuron-core/neuron-cloud-laravel` instead: it builds the service from a config file, runs the queue jobs, registers the endpoint and adds the deploy command.

Coding agents get two skills with the package, `neuron-cloud` and `neuron-cloud-laravel`:

```bash
npx skills add ./vendor/neuron-core/cloud-sdk/skills -y
```

## How it works

A run executes in **segments**: from a start, an answer or a wake-up, to the next wait or to the end. Before a segment the SDK tells the platform "this run is starting"; after it, "this is the run now". When a timer or a retry comes due, the platform calls one signed URL of your application, the SDK puts that call on your queue, and a worker continues the run.

You wire five pieces:

| Piece | What it does |
|---|---|
| The service | `NeuronCloud`, built once per process from your API key and signing key |
| The dispatcher | Hands a message of the SDK to your queue |
| The endpoint | The URL the platform calls |
| The worker | Takes one message off the queue and gives it to the SDK |
| The sync | Publishes your workflows and the endpoint URL at the end of every deploy |

## 1. The service

One bootstrap file, required by the endpoint, the worker, the deploy script and your application:

```php
// bootstrap.php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronCore\Cloud\NeuronCloud;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(getenv('DATABASE_DSN'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$redis = new Redis();
$redis->connect('127.0.0.1');

return (new NeuronCloud(
    apiKey: getenv('NEURON_CLOUD_API_KEY'),
    signingKey: getenv('NEURON_CLOUD_SIGNING_KEY') ?: null,
))
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->register('order-fulfilment', fn () => new OrderWorkflow())
    ->register('support-agent', fn () => new SupportAgent());
```

- **`register()`** gives a workflow or an agent the name the platform knows it by. The factory builds the class from nothing but the workflow ID: a worker has no request and no logged-in user.
- **`setPersistence()`** takes a closure that returns the store. `DatabasePersistence` needs Neuron's `workflow_store` table.
- **The lease**, `register(..., leaseTimeout: 600)` by default, must be longer than your slowest node.

## 2. The dispatcher

The SDK never decides how your queue works. It hands each message to a dispatcher of yours:

```php
use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;

class RedisDispatcher implements DispatcherInterface
{
    public function __construct(protected Redis $redis)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $this->redis->rPush('neuron-cloud', json_encode($invocation->toArray(), JSON_THROW_ON_ERROR));
    }
}
```

The queue must be durable and as trusted as your code: a queued message can hold serialized PHP.

## 3. The endpoint

```php
// public/neuron-cloud.php
$cloud = require __DIR__ . '/../bootstrap.php';

$response = $cloud->handle($_SERVER['REQUEST_METHOD'], getallheaders(), file_get_contents('php://input'));

http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $response->body;
```

The SDK verifies the signature of every request and answers it by itself. Keep the route bare: no authentication, CSRF check or rate limiter in front of it.

## 4. The worker

```php
// bin/worker.php
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;

$cloud = require __DIR__ . '/../bootstrap.php';
$redis = new Redis();
$redis->connect('127.0.0.1');

while ([, $job] = $redis->blPop(['neuron-cloud'], 0)) {
    $invocation = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

    try {
        $cloud->invoke($invocation);
    } catch (CloudException $e) {
        // Refused for good, or a message of yours past its use-by time: keep it for a person.
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;

        $redis->rPush($final || $spent ? 'neuron-cloud:failed' : 'neuron-cloud:later', $job);
    }
}
```

`invoke()` executes one segment, or posts one notice to the platform. It returns a `Report` for every message it dealt with, a failed segment included, and throws only when the message should be tried again later or kept aside. Never retry a segment yourself: the platform retries a failed run with a new message.

## 5. The sync

At the end of every deploy, after the workers have restarted on the new code:

```php
// bin/sync.php
$cloud = require __DIR__ . '/../bootstrap.php';

$cloud->sync('https://shop.example.com/neuron-cloud.php');
```

It publishes every registered definition and the endpoint URL. The platform must be able to reach that URL over HTTPS.

## Starting and answering runs

Every start and every answer goes through `$cloud->workflow()`. A workflow you run directly is never announced: the platform would not fire its timers or retry it.

```php
$order = $cloud->workflow('order-fulfilment', "order:{$orderId}");

// Start the run on your queue...
$runId = $order->start(new OrderPlaced($orderId))->dispatch();

// ...or execute its first segment in this process
$state = $order->start(new OrderPlaced($orderId))->run();

// Answer the event a node waits for with awaitEvent('payment.received')
$order->signal('payment.received', ['amount' => $amount])->dispatch();
```

| Call | Executes | Returns |
|---|---|---|
| `->dispatch()` | On your queue, in a worker | The run ID |
| `->run()` | In this process | The workflow state |
| `->events()` | In this process, as a stream | Neuron's own event generator |

An agent turn is started with its messages, and streamed in the request:

```php
$thread = $cloud->workflow('support-agent', $threadId);
$thread->recover();   // finish a turn that failed, before the new one

$thread->configure(fn (SupportAgent $agent) => $agent->setStreamAdapter(fn () => $adapter));

$events = $thread->start(new UserMessage($prompt), stream: true)->events();
$events->valid();     // admit the run now: a refusal becomes an HTTP status, not a frame

foreach (SSEEncoder::encode($events) as $frame) {
    echo $frame;
    flush();
}
```

Approvals and other answers to a suspended run go through `submitInputs($payload, $translator)`, with the same `run()`, `events()` and `dispatch()`.

## Tracing and evaluations

Both are optional.

```php
// bootstrap.php: one more call in the chain that builds the service sends every segment as a trace
    ->enableTracing(captureContent: false)
```

Every segment the SDK executes is then sent as an OpenTelemetry trace when it ends. A trace always carries the message and the stack trace of an exception. With `captureContent: true`, the default, it also carries prompts, completions, tool arguments and tool results.

```php
// evaluation.php: send the results of vendor/bin/neuron evaluation to the platform
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\Cloud\NeuronCloud;

$apiKey = getenv('NEURON_CLOUD_API_KEY');

return [
    'output' => array_filter([
        ConsoleOutput::class,
        $apiKey ? (new NeuronCloud($apiKey))->evaluationOutput(environment: 'ci') : null,
    ]),
];
```

The evaluation output needs the API key and nothing else. Listing it only when the key is set lets a machine without it still evaluate.

## Datasets for evaluators

An evaluator can run on a dataset kept on Neuron Cloud instead of a JSON file. It receives `NeuronCloud` in its constructor and returns one of its datasets from `getDataset()`:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronCore\Cloud\NeuronCloud;

class RefundEvaluator extends BaseEvaluator
{
    public function __construct(protected NeuronCloud $cloud)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return $this->cloud->dataset('refund-requests');   // the slug of the dataset on the platform
    }

    public function run(array $datasetItem): mixed
    {
        return SupportAgent::make()->chat(new UserMessage($datasetItem['input']['question']))->getMessage()->getContent();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected_output']['answer']), $output);
    }
}
```

Each item is an array with the `input` and the `expected_output` you stored on the platform.

Neuron's evaluation command builds every evaluator through a resolver. Make that resolver the container that holds your `NeuronCloud`:

- **Laravel, with `neuron-core/neuron-cloud-laravel`**: there is nothing to wire. The container holds `NeuronCloud` as a singleton, and the `neuron:evaluate` command of Neuron's Laravel guide builds evaluators through it. For `vendor/bin/neuron evaluation`, add `'resolver' => fn (string $class): object => app($class)` to `evaluation.php`. Put `NEURON_CLOUD_API_KEY` in the environment the evaluation reads: `.env.evaluation` when the command runs with `--env=evaluation`.
- **Another container**: add `'resolver' => fn (string $class): object => $container->get($class)` to `evaluation.php`. The container must build classes by name, as PHP-DI does, and hold the `NeuronCloud` of your bootstrap file.
- **No container**: `evaluation.php` builds the service and gives it to the evaluators that need it:

```php
// evaluation.php
use App\Evaluators\RefundEvaluator;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\Cloud\NeuronCloud;

$apiKey = getenv('NEURON_CLOUD_API_KEY');
$cloud = $apiKey ? new NeuronCloud($apiKey) : null;

return [
    'output' => array_filter([ConsoleOutput::class, $cloud?->evaluationOutput(environment: 'ci')]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => match ($class) {
        RefundEvaluator::class => new RefundEvaluator($cloud ?? throw new RuntimeException('Set NEURON_CLOUD_API_KEY: RefundEvaluator runs on a dataset of Neuron Cloud.')),
        default => new $class(),
    },
];
```

What to expect:

- **A dataset needs the API key and nothing else**: no store, no queue, no signing key.
- **The items are read once per evaluator**, when Neuron loads the dataset: before the first item runs, and before `--concurrency` forks. Building the evaluator reads nothing.
- **A dataset that cannot be read stops its evaluator.** For an unknown slug, a refused API key, or no answer within 15 seconds, `load()` throws a `CloudException`. Neuron's command then reports that evaluator as one that could not run, runs the others, and exits with 1. A missing API key has the same effect one step earlier, when the evaluator is built: the bridge's container throws, and so does the resolver above.
- **Only the active items of a dataset are sent.** A dataset with none runs nothing.
- **`--cache` keys an item by its content.** An item you change on the platform runs again; an unchanged one is served from the cache.

## Testing

`FakePlatform` stands in for Neuron Cloud and `FakeDispatcher` for your queue:

```php
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Testing\FakeDispatcher;
use NeuronCore\Cloud\Testing\FakePlatform;

$platform = new FakePlatform();
$queue = new FakeDispatcher();
$store = new InMemoryPersistence();

$cloud = (new NeuronCloud('test-key', $platform->signingKey, httpClient: $platform))
    ->setPersistence(fn () => $store)
    ->setDispatcher($queue)
    ->register('order-fulfilment', fn () => new OrderWorkflow());

$cloud->workflow('order-fulfilment', 'order:123')->start(new OrderPlaced('123'))->dispatch();
$queue->runAll($cloud);   // what a worker does

$platform->assertReported('order:123', Outcome::Suspended);
```

## Further reading

- [`SDK_DESIGN.md`](SDK_DESIGN.md): the whole design, the public API, the guarantees you must keep, and what happens when things go wrong.
- [`PLATFORM_PROTOCOL.md`](PLATFORM_PROTOCOL.md): the protocol between the SDK and the platform.
- [`skills/`](skills): the skills for coding agents.

## License

MIT. See [LICENSE](LICENSE).
