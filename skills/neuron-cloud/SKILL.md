---
name: neuron-cloud
description: Connect Neuron AI workflows and agents to Neuron Cloud with the neuron-core/cloud-sdk package — durable orchestration (the application runs every segment on its own queue and keeps its own data, the platform keeps the clock for timers, retries and scheduled starts), tracing of every segment as OpenTelemetry spans with enableTracing(), evaluation results sent from evaluation.php with evaluationOutput(), and evaluators that run on datasets kept by the platform with dataset(). Use this skill whenever the user mentions Neuron Cloud, the cloud SDK, durable or orchestrated workflows, a workflow or an agent that must survive a crash, a deploy or a wait of days, timers and retries driven by a platform, sending traces or evaluation results to Neuron Cloud, evaluating on a dataset stored on Neuron Cloud, or wiring NeuronCloud into an application. Also trigger for NeuronCloud, ManagedWorkflow, PendingSegment, DispatcherInterface, Invocation, CloudException, CloudOutput, CloudDataset, CloudListener, FakePlatform, FakeDispatcher, enableTracing, evaluationOutput, dataset(), sync(), setEndpointUrl(), handle(), invoke(), NEURON_CLOUD_API_KEY or NEURON_CLOUD_SIGNING_KEY. In a Laravel application, wire it with the neuron-cloud-laravel skill.
---

# Neuron Cloud SDK

`neuron-core/cloud-sdk` connects the workflows and agents of an application to Neuron Cloud. It gives three things, and each is wired on its own:

| Feature | What it does | What you wire |
|---|---|---|
| **Orchestration** | A run survives a crash, a deploy and a wait of days. The platform fires its timers, retries it after a failure or a dead worker, and starts it on a schedule | The service, a queue, one endpoint, a worker, a sync at deploy |
| **Observability** | Every segment the SDK executes is sent as one OpenTelemetry trace: nodes, inferences, tool calls, retrievals | One call, `enableTracing()`, on the service of the orchestration |
| **Evaluations** | The results of `vendor/bin/neuron evaluation` reach the platform, and an evaluator can run on a dataset the platform keeps | An output in `evaluation.php`, and `dataset()` in an evaluator built with the service. Both need the API key and nothing else |

The workflow itself stays ordinary Neuron code that knows nothing about the SDK: write it with **neuron-workflow** and **neuron-agent**. This skill is the plain PHP wiring. **In a Laravel application use the neuron-cloud-laravel skill**: its package builds the service, the queue jobs, the endpoint and the deploy command. Any other framework is wired as below, with the service in its container and its own queue behind the dispatcher.

## The Mental Model

**Your application runs the workflow and keeps its data. The platform keeps a list of runs and a clock.**

A run executes in *segments*: from a start, an answer or a wake-up, to the next wait or the end. Every segment runs in your processes, on your store. Before a segment the SDK tells the platform "this run is starting" (the announcement); after it, "this is the run now" (the report). When a timer or a retry comes due, the platform calls one signed URL of your application; the SDK puts that call on your queue and a worker continues the run.

| Piece | What it is | Who provides it |
|---|---|---|
| `NeuronCloud` | The one service. Built once per process from the API key and the signing key | You, in a bootstrap file |
| Definition | A name the platform knows, a factory that builds the workflow, a version, a lease | `register()` |
| Store | Neuron's own `PersistenceInterface`, shared by every process | `setPersistence()`, a closure that returns a store |
| Dispatcher | Hands one `Invocation` to your queue | `setDispatcher()`, a class of yours |
| Endpoint | The URL the platform calls | One file that calls `handle()` |
| Worker | Takes one message off the queue and calls `invoke()` | A loop of yours |
| Sync | Publishes the definitions and the endpoint URL | `sync()`, the last step of a deploy; `handle()` answers the same body when the platform asks for it |

Rules that decide correctness:

- **Every start and every answer of a registered workflow goes through `$cloud->workflow()`.** A direct `run()`, `chat()` or `stream()` on the class is not announced: the platform never fires its timers or retries it.
- **The factory rebuilds the workflow from the workflow ID alone.** A worker has no request and no logged-in user. Input of one run goes in the start event, never in the constructor.
- **One store, shared by every process.** `sync()` refuses `InMemoryPersistence` and `FilePersistence`. The SDK sets its store and its lease on every workflow it builds: what the class declares is replaced.
- **The queue runs a segment once.** Never retry a segment job: the platform retries a failed run with a new message. The only messages your queue tries again are the ones `invoke()` throws for (see the worker).
- **Only coordination data reaches the platform**: names, IDs, statuses, wake-up times, the class of an exception. State, inputs, answers and results stay in your store, unless you turn tracing on (Observability).
- **Authorize the user for the workflow ID before calling the SDK.** A workflow ID and an event name reach the platform: no secret in them.

## Setup: Orchestration in Plain PHP

Lay these down in order. Each step is one file.

**1. Install and keys.**

```bash
composer require neuron-core/cloud-sdk
```

`NEURON_CLOUD_API_KEY` authenticates what the application sends; `NEURON_CLOUD_SIGNING_KEY` verifies what the platform sends to the endpoint. Both come from the dashboard. Only the endpoint needs the signing key.

**2. The store.** `DatabasePersistence` needs Neuron's `workflow_store` table and a PDO in exception mode: the schema is in **neuron-workflow** ("Database Table Schema"). `EloquentPersistence` and `RedisPersistence` work too.

**3. The dispatcher.** Plain PHP has no queue: you supply one, durable and as trusted as your code (a queued message holds serialized PHP). `toArray()` is JSON-safe and `fromArray()` reads it back.

```php
// src/RedisDispatcher.php
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

A dispatcher that cannot queue the message throws: the SDK then fails the call (`dispatch_failed`) before anything runs.

**4. The service.** One bootstrap file, required by the endpoint, the worker, the sync script and the application.

```php
// bootstrap.php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronCore\Cloud\NeuronCloud;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(getenv('DATABASE_DSN'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1');

return (new NeuronCloud(
    apiKey: getenv('NEURON_CLOUD_API_KEY'),
    signingKey: getenv('NEURON_CLOUD_SIGNING_KEY') ?: null,
))
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->setEndpointUrl('https://shop.example.com/neuron-cloud.php')   // what the sync publishes
    ->register('order-fulfilment', fn () => new OrderWorkflow());
```

- **`register(name, factory, version: 1, leaseTimeout: 600)`.** The name is what the platform shows: lowercase letters, digits, `.`, `_`, `-`, at most 64 characters. The factory may declare `(string $workflowId, ?string $runId)`; the SDK binds the workflow to the workflow ID itself.
- **The lease must exceed the slowest node** (one inference, one whole batch of tools). A sync refuses a lease under 60 seconds.
- **`setPersistence()` takes a closure**, called for every workflow the SDK builds. In a worker that lives for hours, return a store on a connection that is still open: a `DatabasePersistence` keeps the PDO it is given.
- **With no dispatcher set, every message runs in place.** That is for scripts and tests only.

**5. The endpoint.** One public HTTPS URL, with nothing in front of it that answers in its place or rewrites the body: no authentication, no CSRF check, no rate limiter. The SDK verifies the signature over the raw body, hands a start or a wake to the dispatcher and answers by itself. `handle()` never throws.

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

**6. The worker.** One message, one `invoke()`. It returns a `Report` for every message it dealt with, also for a segment that failed. It throws `CloudException` only when the message was not dealt with, and `$e->reason` says what to do.

```php
// bin/worker.php
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;

$cloud = require __DIR__ . '/../bootstrap.php';
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1');

while ([, $job] = $redis->blPop(['neuron-cloud'], 0)) {
    $invocation = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

    try {
        $cloud->invoke($invocation);
    } catch (CloudException $e) {
        // The platform refused the message for good: a person looks at it.
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        // A start, an answer or a signal of yours that could not run before its use-by time.
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;

        $redis->rPush($final || $spent ? 'neuron-cloud:failed' : 'neuron-cloud:later', $job);
    }
}
```

| `$e->reason` | What the worker does |
|---|---|
| `report_undelivered` | The platform did not record a notice. Give the same message to `invoke()` again later (10, 30, 60, 300, then every 900 seconds), without limit |
| `unknown_definition`, `unknown_version`, `not_executed`, `announce_failed`, `unavailable` | Nothing ran: a worker on old code during a deploy, a store that was down. Try again later while `time() < $invocation->expiresAt`, then keep it aside |
| `unauthorized`, `invalid_request`, `unsupported_protocol` | Final. Keep it aside until the key or the URL is fixed, then replay it |

Run the worker under a process supervisor, one process per segment that should execute at the same time, and move the `later` list back with a cron. Read the `Report` too: a start of your own that did not run comes back as `rejected`, `refused` or `aborted`, and nothing else tells you.

**7. The sync, at the end of every deploy.** Restart the workers first: a worker on old code cannot build a new version.

```php
// bin/sync.php
$cloud = require __DIR__ . '/../bootstrap.php';

$result = $cloud->sync();   // the URL of setEndpointUrl(); a URL given here is published instead

echo $result->modified ? "Published.\n" : "Nothing changed.\n";
```

The list is complete and replaces the one the platform holds. `sync()` throws a `CloudException` and publishes nothing for an endpoint that is not `https`, when no endpoint URL is given or set (`not_configured`), for an in-memory or file store, when nothing is registered, and when the platform could not reach the endpoint (`endpoint_unverified`): local development needs an HTTPS tunnel.

The platform can also ask the endpoint for a sync, when an owner requests one from its dashboard. `handle()` answers it with the body `sync()` would post, so `setEndpointUrl()` belongs in the bootstrap the endpoint requires, not only in `bin/sync.php`. Where `sync()` would throw before sending, the endpoint answers `409 sync_refused` without the reason: run `bin/sync.php` to read it.

## Starting and Answering Runs

`$cloud->workflow($name, $workflowId)` returns the workflow bound to one workflow ID. Each call on it prepares one segment, used once:

```php
$order = $cloud->workflow('order-fulfilment', "order:{$orderId}");

// Start: on the queue, or in this process
$runId = $order->start(new OrderPlaced($orderId))->dispatch();
$state = $order->start(new OrderPlaced($orderId))->run();

// Answer the event the run waits for (awaitEvent('payment.received') in a node)
$order->signal('payment.received', ['amount' => $amount])->dispatch();

// Answer the current wait of any kind, with a translator when the payload is a frontend's
$order->submitInputs($payload, $translator)->run();

// Finish a failed or dead run now, instead of waiting for the platform's retry. Null: nothing to do
$order->recover();
```

| Call | Runs | Returns |
|---|---|---|
| `->dispatch()` | On the queue, in a worker | The run ID |
| `->run()` | In this process | The `WorkflowState` |
| `->events()` | In this process, as a stream | Neuron's own generator of events |

- **`start()`** takes an event, the messages of an agent turn (`new UserMessage('…')`, or an array of messages, with `stream: true` when the provider should stream), or nothing for the workflow's own start event. A start never replaces a run: a workflow ID that already holds one throws `RunInFlightException`. Two starts queued before either ran are both accepted: the worker runs the first, and the report of the second says `refused`.
- **`signal()` and `submitInputs()` check the answer in this request**, before anything is queued: `InputTranslationException` when no run is suspended on a wait, `WorkflowRefusedException` when the run is not at that wait. Map both to a 409 for a webhook that will call again. Nothing buffers an event that arrives before its wait.
- **`run()` and `events()` throw what Neuron throws**: the refusal, or the exception of a node. `dispatch()` returns as soon as the queue has the message.
- **A `CloudException` in a request** is `dispatch_failed` (your queue did not take the message) or `unknown_version` (this process runs older code than the run): answer 503. Any other reason is a programming error.
- **`configure(fn (Workflow $workflow) => …)`** adjusts the copy that `run()` and `events()` execute in this request: a stream adapter, a channel, tools of the browser. Segments that run in a worker never see it: there the class's own hooks decide.

### An agent turn streamed in a request

```php
$thread = $cloud->workflow('support-agent', $threadId);
$thread->recover();                                   // finish a turn that failed, before the new one

$thread->configure(fn (SupportAgent $agent) => $agent->setStreamAdapter(fn () => $adapter));

$events = $thread->start(new UserMessage($prompt), stream: true)->events();
$events->valid();                                     // admit now: a refusal is an HTTP status, not a frame

foreach (SSEEncoder::encode($events) as $frame) {
    echo $frame;
    flush();
}
```

Prime the generator with `valid()` before any output: admission is lazy, and a refusal thrown after the headers were sent cannot become a status. Protocols, adapters and channels: **neuron-streaming** and **neuron-frontend-integration**.

### Versions

A breaking change to a workflow that may have suspended runs (a node, event or state class renamed or reshaped, waits reordered) gets a new version; the old one stays registered until no run is open on it.

```php
$cloud->register('order-fulfilment', fn () => new OrderWorkflow(), version: 1)
      ->register('order-fulfilment', fn () => new OrderWorkflowV2(), version: 2);   // new runs start here
```

`$result->retirable` of a sync lists the versions with no open run (`['order-fulfilment' => [1]]`); `$result->stranded` lists versions that left the code while runs of them are open.

## Observability

```php
// bootstrap.php: one more call in the chain that builds the service
    ->setDispatcher(new RedisDispatcher($redis))
    ->enableTracing(attributes: ['deployment.environment' => 'production'])
    ->register('order-fulfilment', fn () => new OrderWorkflow());
```

From then on every segment this process executes (in a request, in a worker, as a stream) sends one trace to the platform when it ends. Nothing else is wired: the SDK subscribes its own listener (`CloudListener`) to each workflow it builds, and names the spans after the definition. Call it in the bootstrap file, so that it is on before any `workflow()`, `handle()` or `invoke()`.

- **A trace is the run.** Its trace ID comes from the run ID, so every segment of a run lands in the same trace. Spans follow the OpenTelemetry conventions for generative AI: `invoke_workflow` or `invoke_agent`, a span per node, `chat <model>` per inference, `execute_tool <tool>` per tool call, `retrieval` for RAG.
- **Tracing sends what your application works on.** The message and the stack trace of an exception are always sent. Prompts, completions, tool arguments and tool results are sent unless you pass `captureContent: false`. Ask the developer which one they want when the data is sensitive: never decide it silently.
- **`attributes`** are stamped on every span and are the same for every run of the process: the environment, a release. There is no per-run attribute.
- **It is best effort.** A trace the platform did not take is logged with `error_log()` and dropped. It never changes the run, its report or what a caller receives.
- **The trace is posted by the process that ran the segment**, with a 3 second timeout: in a web request that is one call to the platform when the segment ends.
- **Only segments the SDK executes are traced.** A workflow you run directly is not. Events, custom listeners and logging on your own workflows: **neuron-monitoring**.

## Evaluation Output

`evaluationOutput()` returns an output driver for Neuron's evaluations. List it in `evaluation.php`, the file `vendor/bin/neuron evaluation` reads from the project root:

```php
// evaluation.php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\Cloud\NeuronCloud;

$apiKey = getenv('NEURON_CLOUD_API_KEY');

return [
    'output' => array_filter([
        ConsoleOutput::class,
        // Sent only where the key is set: a laptop without it still evaluates.
        $apiKey ? (new NeuronCloud($apiKey))->evaluationOutput(environment: getenv('NEURON_CLOUD_EVALUATION_ENVIRONMENT') ?: null) : null,
    ]),
];
```

- **`evaluationOutput()` returns a `CloudOutput`**, built with the API key, the platform URL and the HTTP client of the service it comes from.
- **Only the API key is needed**: no store, no dispatcher, no registered definition. Build a `NeuronCloud` here, or require the bootstrap file when its other dependencies are available where evaluations run.
- **Guard the key.** Without it, `new NeuronCloud(getenv('NEURON_CLOUD_API_KEY'))` throws a `TypeError` in an `evaluation.php` that declares `strict_types`, and the run ends with no result. Without `strict_types` it builds a service with an empty key, and the platform refuses every request.
- **One message per evaluator** is sent when all evaluators are done: its class, its summary, and for every dataset item the input, the output, the scores and the failed assertions. Inputs and outputs do leave the application: that is the point of this output.
- **`environment`** names where the evaluation ran (`ci`, `local`, a branch), or is null.
- **It is best effort too.** A platform that is away is logged; the other outputs still run and the exit code of the command is what the assertions decide.

Evaluators, datasets, assertions and the other output drivers: **neuron-evaluation**.

## Evaluation Datasets

`dataset()` returns a dataset of Neuron's evaluations whose items Neuron Cloud keeps, a `CloudDataset`. An evaluator returns it from `getDataset()` in place of a `JsonDataset`, with the service it receives in its constructor:

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

Neuron's evaluation command builds every evaluator through the `resolver` of `evaluation.php`. Make it the application's container when it builds classes by name and holds the service, as PHP-DI does: `'resolver' => fn (string $class): object => $container->get($class)`. Without a container, `evaluation.php` builds the service and hands it over:

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

- **An item is an array with the `input` and the `expected_output` stored on the platform**, each whatever JSON was stored there. Only the active items of the dataset are sent. Ask the developer what an item holds, or read one, before writing `run()` and `evaluate()`.
- **Only the API key is needed**: no store, no dispatcher, no registered definition.
- **The items are read once per evaluator**, when Neuron's runner loads the dataset: after `setUp()`, before the first item runs, in the process of the command. `--concurrency` forks after that, and the children never call the platform. `dataset()` itself reads nothing.
- **A dataset that cannot be read stops its evaluator.** An unknown slug, a refused key, no answer within 15 seconds, or an answer that is not a dataset makes `load()` throw a `CloudException`. The command reports that evaluator as one that could not run, runs the others and exits with 1. A missing key stops it the same way, when it is built: that is what the `throw` in the resolver is for.
- **An empty dataset runs nothing**, and nothing fails.
- **`--cache` keys an item by its content**: an item changed on the platform runs again, an unchanged one is served from the cache.
- **Never build `CloudDataset` with `new`**: its constructor takes a class internal to the SDK.

## Testing

`NeuronCore\Cloud\Testing` ships two fakes. `FakePlatform` is the HTTP client of the service: it records what the SDK sends and plays the platform's side of the endpoint. `FakeDispatcher` is a queue the test runs by hand.

```php
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Testing\FakeDispatcher;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Verb;

$platform = new FakePlatform();
$queue = new FakeDispatcher();
$store = new InMemoryPersistence();

$cloud = (new NeuronCloud('test-key', $platform->signingKey, httpClient: $platform))
    ->setPersistence(fn () => $store)
    ->setDispatcher($queue)
    ->register('order-fulfilment', fn () => new OrderWorkflow());

$cloud->workflow('order-fulfilment', 'order:123')->start(new OrderPlaced('123'))->dispatch();
$queue->assertDispatched(Verb::Start, 'order:123');

$queue->runAll($cloud);                    // the worker: it runs the segment and reports it
$platform->assertReported('order:123', Outcome::Suspended);

$platform->wake($cloud, 'order:123');      // the platform's wake-up, signed, through handle()
$queue->runAll($cloud);                    // ...which a worker then executes
```

- One `InMemoryPersistence` shared through the closure is enough for a test; a test that calls `sync()` needs a real store, such as `DatabasePersistence` on SQLite.
- `runAll()` returns what every `invoke()` returned (a `Report`) or threw (a `CloudException`), and keeps queued what a worker would try again. A segment run in the test itself with `run()` or `events()` queues its two notices: `runAll()` posts them.
- `$platform->failNext(2, 503)` makes the next two requests fail; `$platform->deliver($cloud, [...])` sends the endpoint any message, signed.
- Fake the model with `FakeAIProvider` (**neuron-test**): the SDK never touches the provider.

## Pitfalls

- A registered workflow run with `->run()` or `->chat()` on the class itself → the platform never hears of it: no timer fires, no retry comes. Go through `$cloud->workflow()`.
- A factory that reads the request, the session or `auth()` → it works in the request and fails in the worker. Build the workflow from the workflow ID.
- Retrying a segment in the queue (`$tries`, a redelivery policy) → the same work runs twice. The platform owns retries; try again only what `invoke()` threw for.
- `InMemoryPersistence` or `FilePersistence` in the service → `sync()` refuses it (`unsuitable_persistence`): their conditional writes do not hold across processes.
- A lease shorter than the slowest node → a live run is taken for dead and a second worker takes it over.
- The endpoint behind authentication, CSRF or a body-rewriting middleware → every message of the platform is answered by something else. Keep the route bare.
- `sync()` with an `http://` URL or from a machine the platform cannot reach → refused. Use the public HTTPS address, or a tunnel in development.
- A dispatched start for a workflow ID that already holds a run → `RunInFlightException` in the request. It never replaces a run.
- Iterating `events()` without `valid()` first → a refusal arrives as an exception in the middle of a response.
- `enableTracing()` with default arguments on sensitive data → prompts and tool results reach the platform. Pass `captureContent: false`; exception messages are sent either way.
- `enableTracing()` called after `$cloud->workflow()` → that workflow object was prepared without tracing. Call it in the bootstrap file.
- `new NeuronCloud(getenv('NEURON_CLOUD_API_KEY'))` unguarded in `evaluation.php` → on a machine without the key, a `TypeError` and no result where the file declares `strict_types`, and a service whose every request is refused where it does not. Build the service only when the key is set.
- An evaluator that takes `NeuronCloud` in its constructor and no `resolver` in `evaluation.php` → "requires constructor arguments: build it with a resolver". Give it the service through the resolver.
- An evaluator that catches the `CloudException` of its dataset and returns an empty list → it runs nothing, and the command passes. Let it throw.

## Related

- **neuron-cloud-laravel** — the same wiring in a Laravel application, through the `neuron-core/neuron-cloud-laravel` package.
- **neuron-workflow** — nodes, waits (`awaitEvent`, `sleepUntil`, `interrupt`), `memoize()`, persistence backends and their tables.
- **neuron-agent** — agents, providers, tools, chat history.
- **neuron-streaming** and **neuron-frontend-integration** — stream adapters, channels, AG-UI and Vercel clients.
- **neuron-evaluation** — evaluators, datasets, assertions, output drivers.
- **neuron-monitoring** — Neuron's events and listeners.
- **neuron-test** — `FakeAIProvider` and the other fakes.
