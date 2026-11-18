# Orchestration: Durable Runs on IQ

Stage 3 of the journey in [SKILL.md](../SKILL.md), for developers who ask for it. Read it when a run must survive a crash, a deploy or a wait of days, when the platform should fire timers, retry failed runs or start runs on a schedule, or when runs are started and answered through `$iq->workflow()`. Most applications never need it: tracing (stage 1) and evaluation (stage 2) work the same with orchestration on or off.

It builds on the service of stage 0, in the container or in `bootstrap.php`, and adds the signing key, a store, a queue, an endpoint, a worker and a sync. The workflow itself stays ordinary Neuron code: write it with **neuron-workflow** and **neuron-agent**.

## The Mental Model

**Your application runs the workflow and keeps its data. The platform keeps a list of runs and a clock.**

A run executes in *segments*: from a start, an answer or a wake-up, to the next wait or the end. Every segment runs in your processes, on your store. Before a segment the SDK tells the platform "this run is starting" (the announcement); after it, "this is the run now" (the report). When a timer or a retry comes due, the platform calls one signed URL of your application; the SDK puts that call on your queue and a worker continues the run.

| Piece | What it is | Who provides it |
|---|---|---|
| `IQ` | The service of stage 0, now with the signing key and the settings below | You, in the container entry or `bootstrap.php` |
| Definition | A name the platform knows, a factory that builds the workflow, a version, a lease | `register()` |
| Store | Neuron's own `PersistenceInterface`, shared by every process | `setPersistence()`, a closure that returns a store |
| Dispatcher | Hands one `Invocation` to your queue | `setDispatcher()`, a class of yours |
| Endpoint | The URL the platform calls | One file that calls `handle()` |
| Worker | Takes one message off the queue and calls `invoke()` | A loop of yours |
| Sync | Publishes the definitions and the endpoint URL | `sync()`, the last step of a deploy; `handle()` answers the same body when the platform asks for it |

Rules that decide correctness:

- **Every start and every answer of a registered workflow goes through `$iq->workflow()`.** A direct `run()`, `chat()` or `stream()` on the class is not announced: the platform never fires its timers or retries it.
- **The factory rebuilds the workflow from the workflow ID alone.** A worker has no request and no logged-in user. Input of one run goes in the start event, never in the constructor.
- **One store, shared by every process.** `sync()` refuses `InMemoryPersistence` and `FilePersistence`. The SDK sets its store and its lease on every workflow it builds: what the class declares is replaced.
- **The queue runs a segment once.** Never retry a segment job: the platform retries a failed run with a new message. The only messages your queue tries again are the ones `invoke()` throws for (see the worker).
- **Only coordination data reaches the platform**: names, IDs, statuses, wake-up times, the class of an exception. State, inputs, answers and results stay in your store, unless tracing is on (stage 1).
- **Authorize the user for the workflow ID before calling the SDK.** A workflow ID and an event name reach the platform: no secret in them.

## Setup

Lay these down in order. Each step is one file. The package and the API key are in place since stage 0.

**1. The signing key.** `IQ_SIGNING_KEY` verifies what the platform sends to the endpoint. It comes from the dashboard, beside the API key. Only the endpoint needs it.

**2. The store.** `DatabasePersistence` needs Neuron's `workflow_store` table and a PDO in exception mode: the schema is in **neuron-workflow** ("Database Table Schema"). `EloquentPersistence` and `RedisPersistence` work too.

**3. The dispatcher.** Plain PHP has no queue: you supply one, durable and as trusted as your code (a queued message holds serialized PHP). `toArray()` is JSON-safe and `fromArray()` reads it back.

```php
// src/RedisDispatcher.php
use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;

class RedisDispatcher implements DispatcherInterface
{
    public function __construct(protected Redis $redis)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $this->redis->rPush('iq', json_encode($invocation->toArray(), JSON_THROW_ON_ERROR));
    }
}
```

A dispatcher that cannot queue the message throws: the SDK then fails the call (`dispatch_failed`) before anything runs.

**4. The service.** The `bootstrap.php` of stage 0 grows: the endpoint, the worker, the sync script and the application all require it, with tracing on or off, since the application starts its runs through it.

```php
// bootstrap.php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(getenv('DATABASE_DSN'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1');

$iq = (new IQ(
    apiKey: getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
    signingKey: getenv('IQ_SIGNING_KEY') ?: null,
))
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->setEndpointUrl('https://shop.example.com/iq.php')   // what the sync publishes
    ->register('order-fulfilment', fn () => new OrderWorkflow());

// The enableTracing() block of stage 1, when it is already there, stays here: before any workflow(), handle() or invoke().

return $iq;
```

- **Keep the `enableTracing()` block of stage 1 when the file has one, and add none.** Orchestration does not turn tracing on: a request for durable runs is not a request for traces, and its content setting is the developer's answer (stage 1 of [SKILL.md](../SKILL.md)).
- **An entry script that requires this file and builds traced agents hands `Tracing::subscribe()` the service it holds**, `fn (): IQ => $iq`. The closure of stage 1 would require the file a second time, and open a second connection to the database and to Redis. Without the key such a script stops at its own `require`, tracing on or off: orchestration needs the service.
- **`register(name, factory, version: 1, leaseTimeout: 600)`.** The name is what the platform shows: lowercase letters, digits, `.`, `_`, `-`, at most 64 characters. The factory may declare `(string $workflowId, ?string $runId)`; the SDK binds the workflow to the workflow ID itself.
- **The lease must exceed the slowest node** (one inference, one whole batch of tools). A sync refuses a lease under 60 seconds.
- **`setPersistence()` takes a closure**, called for every workflow the SDK builds. In a worker that lives for hours, return a store on a connection that is still open: a `DatabasePersistence` keeps the PDO it is given.
- **With no dispatcher set, every message runs in place.** That is for scripts and tests only.
- **With a container**, the same calls go in the factory of `IQ::class`, and each script below starts with `$iq = $container->get(IQ::class)`. A definition's factory that takes the agent from the container calls PHP-DI's `make()`, which builds a new one every time: `get()` would hand every run the same object. The tracer of stage 1 that agent carries where tracing is on leaves the run to the SDK.
- **`evaluation.php` requires this file too** (stage 2). Where evaluations run without the database or Redis, give it a `new IQ()` of its own, built with the API key alone.

**5. The endpoint.** One public HTTPS URL, with nothing in front of it that answers in its place or rewrites the body: no authentication, no CSRF check, no rate limiter. The SDK verifies the signature over the raw body, hands a start or a wake to the dispatcher and answers by itself. `handle()` never throws.

```php
// public/iq.php
$iq = require __DIR__ . '/../bootstrap.php';

$response = $iq->handle($_SERVER['REQUEST_METHOD'], getallheaders(), file_get_contents('php://input'));

http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $response->body;
```

**6. The worker.** One message, one `invoke()`. It returns a `Report` for every message it dealt with, also for a segment that failed. It throws `IQException` only when the message was not dealt with, and `$e->reason` says what to do.

```php
// bin/worker.php
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;

$iq = require __DIR__ . '/../bootstrap.php';
$redis = new Redis();
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1');

while ([, $job] = $redis->blPop(['iq'], 0)) {
    $invocation = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

    try {
        $iq->invoke($invocation);
    } catch (IQException $e) {
        // The platform refused the message for good: a person looks at it.
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        // A start, an answer or a signal of yours that could not run before its use-by time.
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;

        $redis->rPush($final || $spent ? 'iq:failed' : 'iq:later', $job);
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
$iq = require __DIR__ . '/../bootstrap.php';

$result = $iq->sync();   // the URL of setEndpointUrl(); a URL given here is published instead

echo $result->modified ? "Published.\n" : "Nothing changed.\n";
```

The list is complete and replaces the one the platform holds. `sync()` throws an `IQException` and publishes nothing for an endpoint that is not `https`, when no endpoint URL is given or set (`not_configured`), for an in-memory or file store, when nothing is registered, and when the platform could not reach the endpoint (`endpoint_unverified`): local development needs an HTTPS tunnel.

The platform can also ask the endpoint for a sync, when an owner requests one from its dashboard. `handle()` answers it with the body `sync()` would post, so `setEndpointUrl()` belongs in the bootstrap the endpoint requires, not only in `bin/sync.php`. Where `sync()` would throw before sending, the endpoint answers `409 sync_refused` without the reason: run `bin/sync.php` to read it.

## Starting and Answering Runs

`$iq->workflow($name, $workflowId)` returns the workflow bound to one workflow ID. Each call on it prepares one segment, used once:

```php
$order = $iq->workflow('order-fulfilment', "order:{$orderId}");

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
- **An `IQException` in a request** is `dispatch_failed` (your queue did not take the message) or `unknown_version` (this process runs older code than the run): answer 503. Any other reason is a programming error.
- **`configure(fn (Workflow $workflow) => …)`** adjusts the copy that `run()` and `events()` execute in this request: a stream adapter, a channel, tools of the browser. Segments that run in a worker never see it: there the class's own hooks decide.

### An agent turn streamed in a request

```php
$thread = $iq->workflow('support-agent', $threadId);
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
$iq->register('order-fulfilment', fn () => new OrderWorkflow(), version: 1)
      ->register('order-fulfilment', fn () => new OrderWorkflowV2(), version: 2);   // new runs start here
```

`$result->retirable` of a sync lists the versions with no open run (`['order-fulfilment' => [1]]`); `$result->stranded` lists versions that left the code while runs of them are open.

## Tracing Orchestrated Segments

With `enableTracing()` on the service (stage 1), every segment the SDK executes sends one trace when it ends: in a request, in a worker, as a stream. Nothing more is wired. The SDK subscribes its own listener to each workflow it builds, and names the spans after the definition: `invoke_agent support-agent`.

- **A trace is the run.** Its trace ID comes from the run ID, so every segment of a run lands in the same trace.
- **A run is traced once.** A factory that returns the container's agent, which already carries a `tracer()` listener, or a `configure()` callback that subscribes one, leaves the run to the SDK's listener: that one traces it, and the other sends nothing for it.
- **Call `enableTracing()` where the service is built**, so that it is on before any `workflow()`, `handle()` or `invoke()`.
- **The trace is posted by the process that ran the segment**: with a queue set, by the worker, before the report of the segment.
- **It is best effort.** A trace the platform refuses is logged and dropped: it never changes the segment or its report.

## Pitfalls

- A registered workflow run with `->run()` or `->chat()` on the class itself → the platform never hears of it: no timer fires, no retry comes. Go through `$iq->workflow()`.
- A factory that reads the request, the session or `auth()` → it works in the request and fails in the worker. Build the workflow from the workflow ID.
- A factory that returns `$container->get(SupportAgent::class)` → every run shares one agent object. Use `make()`.
- Retrying a segment in the queue (`$tries`, a redelivery policy) → the same work runs twice. The platform owns retries; try again only what `invoke()` threw for.
- `InMemoryPersistence` or `FilePersistence` in the service → `sync()` refuses it (`unsuitable_persistence`): their conditional writes do not hold across processes.
- A lease shorter than the slowest node → a live run is taken for dead and a second worker takes it over.
- The endpoint behind authentication, CSRF or a body-rewriting middleware → every message of the platform is answered by something else. Keep the route bare.
- `sync()` with an `http://` URL or from a machine the platform cannot reach → refused. Use the public HTTPS address, or a tunnel in development.
- A dispatched start for a workflow ID that already holds a run → `RunInFlightException` in the request. It never replaces a run.
- Iterating `events()` without `valid()` first → a refusal arrives as an exception in the middle of a response.
- `enableTracing()` called after `$iq->workflow()` → that workflow object was prepared without tracing. Call it where the service is built.
- Evaluating through `$iq->workflow()` → the platform lists every dataset item as a run. Evaluators keep calling the agent directly.

## Related

- [SKILL.md](../SKILL.md) — the journey: the service, observability, evaluation.
- [testing.md](testing.md) — `FakePlatform` and `FakeDispatcher` for orchestrated runs.
- **iq-laravel** — the same orchestration in a Laravel application.
- **neuron-workflow** — nodes, waits (`awaitEvent`, `sleepUntil`, `interrupt`), `memoize()`, persistence backends and their tables.
- **neuron-agent** — agents, providers, tools, chat history.
- **neuron-streaming** and **neuron-frontend-integration** — stream adapters, channels, AG-UI and Vercel clients.
