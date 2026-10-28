# Orchestration in Laravel

Orchestration is the advanced stage of Neuron Cloud. Read this file only when the developer asks for it: durable runs, timers, retries or scheduled starts the platform drives, runs that survive a crash, a deploy or a wait of days, or one of its pieces by name (definitions, the queues, the endpoint, `neuron-cloud:sync`, `InvocationSettled`).

It comes after the stages of the skill, and changes none of them: the package is installed, `NEURON_CLOUD_API_KEY` is set, and tracing and evaluation keep working as they are. This stage wires the rest of the package: the definitions, the workflow store, two queues, the endpoint the platform calls and the sync of a deploy. Then the turns of every registered agent go through `$cloud->workflow()`.

It stands on two other skills. **neuron-laravel-integration** lays the Laravel foundations of Neuron: the `App\Neuron` layout, `NeuronServiceProvider`, the tables, agents built by the container, the error mapping. **neuron-cloud** (its `references/orchestration.md`) says what a segment is and the rules every run obeys. Read both before wiring this stage: this file only adds what is Laravel-shaped about it.

## The Mental Model

**The package builds the service, your controllers call it, the queue runs the segments.**

| Piece | Where | Notes |
|---|---|---|
| `NeuronCloud` | The lazy singleton of the package, built from `config/neuron-cloud.php` | Inject it in controllers and jobs. Never build your own |
| Definitions | `config/neuron-cloud.php`, name => class | The container builds each class, from the workflow ID alone |
| The workflow store | Your `PersistenceInterface` binding in `NeuronServiceProvider` | The package asks the container for it at every build: `bind()`, never `singleton()` |
| Segments | `RunInvocation` jobs on the `neuron-cloud` queue | One job, one segment, run once |
| Notices | `ObserveRun` jobs on the `neuron-cloud-observe` queue | Announcements and reports for the platform, retried until recorded |
| The endpoint | `POST /neuron-cloud`, registered by the package | Outside every middleware group |
| The deploy | `php artisan neuron-cloud:sync` | Publishes the definitions and `APP_URL/neuron-cloud` |
| The outcome of a queued segment | The `InvocationSettled` event | Where you close a browser stream or tell a user |
| Traces of segments | The SDK's own listener, when `NEURON_CLOUD_TRACING` is on | One trace per run, named after the definition |

Rules that decide correctness, beside those of **neuron-cloud**:

- **Every turn goes through `$cloud->workflow($name, $threadId)`.** Once an agent is a definition, never call `chat()`, `stream()` or `run()` on it for a real conversation: the platform would not hear of the run. Evaluations and tests are the exception (Tracing and Evaluation of Definitions).
- **A worker has no request.** The agent takes everything from the thread ID, and names where a background segment streams in its own `streamAdapter()` and `channel()` hooks.
- **The queue never retries a segment.** No `$tries`, no `release()`, no job of your own around a turn: the platform retries a failed run with a new message.
- **`env()` only inside `config/*.php`**, as everywhere in Laravel: the keys of this stage are read with `config()`.

## Setup

Check first: an application already orchestrated lists its definitions in `config/neuron-cloud.php` (`grep -sA4 "'definitions'" config/neuron-cloud.php` shows a non-empty list), and `grep -rlsE 'DispatchFailed|->workflow\(' bootstrap app` lists `bootstrap/app.php` and the controllers that start runs. Lay the foundations of **neuron-laravel-integration** before these steps (steps 1 to 6 of its Setup): the tables and `NeuronServiceProvider` are used as they are, except for the `PersistenceInterface` binding of its step 5 ("Container wiring"), which step 3 below replaces.

**1. The config and the signing key.** Publish the config, unless an earlier stage already did:

```bash
php artisan vendor:publish --tag=neuron-cloud-config
```

Add the signing key beside the API key, in `.env` and in the environment of every worker:

```dotenv
NEURON_CLOUD_SIGNING_KEY=...    # verifies what the platform sends; without it the endpoint answers 401
```

**2. Definitions**, in `config/neuron-cloud.php`. Class names only, so that `config:cache` works.

```php
'definitions' => [
    'support-agent' => App\Neuron\Agents\SupportAgent::class,
    // Versions of one workflow: new runs start on the highest; an older one stays until the sync lists it as retirable.
    'order-fulfilment' => [1 => App\Neuron\Workflows\OrderWorkflow::class, 2 => App\Neuron\Workflows\OrderWorkflowV2::class],
],

'lease_timeout' => 600,   // seconds; replaces the lease the class declares; must exceed the slowest node
'timeout' => 300,         // seconds a worker gives one segment; must exceed the longest segment
```

A name is at most 64 lowercase letters, digits, dots, underscores or hyphens, starting with a letter or a digit; a version is a number from 1. A definition the SDK refuses fails the build of `NeuronCloud` with `invalid_request`, which names it: `$cloud->workflow()`, the endpoint (503) and `neuron-cloud:sync` fail until it is fixed. With tracing on, the agents the container builds still run, untraced, and the exception is reported once per process, as stage 1 of [SKILL.md](../SKILL.md) says.

A definition that needs a closure or a lease of its own is registered in `NeuronServiceProvider::register()` with `extend()`, never `afterResolving()`:

```php
$this->app->extend(NeuronCloud::class, fn (NeuronCloud $cloud): NeuronCloud => $cloud->register(
    'nightly-import',
    fn (string $workflowId) => new NightlyImport($workflowId),
    leaseTimeout: 1800,
));
```

A `CloudException` thrown in an `extend()` fails the build of `NeuronCloud` in the same way: `app(NeuronCloud::class)` throws it, and with tracing on the agents still run untraced.

**3. The store binding.** Replace the binding of the Laravel guide with this one, in `NeuronServiceProvider::register()`:

```php
use Illuminate\Support\Facades\DB;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

$this->app->bind(PersistenceInterface::class, function (): PersistenceInterface {
    $connection = DB::connection();
    // One query of Laravel on the write connection: Laravel reconnects when the server closed it.
    $connection->select('select 1', [], false);

    return new DatabasePersistence($connection->getPdo());
});
```

A `DatabasePersistence` keeps the PDO it is given, and `getPdo()` hands out the one Laravel holds without testing it. Once the server has closed that connection, a worker whose queue is not on it (Redis, SQS) would hand the closed PDO to every segment from then on: the query makes Laravel replace it first.

**4. The queue.** A connection that keeps jobs for a worker: `database`, `redis`, `sqs` or `beanstalkd`. `sync`, `null`, `deferred` and `background` are refused. Its `retry_after` must exceed the `timeout` of step 2: Laravel ships 90, so set `DB_QUEUE_RETRY_AFTER=360` (`REDIS_QUEUE_RETRY_AFTER` on Redis). Run a worker on each of the two queues, in local development too:

```bash
php artisan queue:work --queue=neuron-cloud            # one process per segment that runs at the same time
php artisan queue:work --queue=neuron-cloud-observe    # the notices: a platform outage never delays a segment
```

**5. The endpoint.** Nothing to write: the package registers `POST /neuron-cloud` outside every middleware group, and the SDK verifies each request's signature over its raw body. Keep it bare: no global middleware that authenticates, rate-limits or rewrites the body, and no firewall rule that answers in its place. `APP_URL` must be the public `https` address: the sync publishes `APP_URL/neuron-cloud`.

**6. The error mapping**, added to the one of the Laravel guide in `bootstrap/app.php`:

```php
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;

// The queue did not take a message, or this process runs older code than the run: both pass. Any other reason is a bug.
$exceptions->render(fn (CloudException $e) => in_array($e->reason, [Code::DispatchFailed, Code::UnknownVersion], true)
    ? response()->json(['message' => 'The conversation is unavailable. Retry later.'], 503, ['Retry-After' => 5])
    : null);
```

The guide's renderers already turn what the SDK lets through into statuses: `InputTranslationException` 400, `RunInFlightException` and any other `WorkflowException` 409.

**7. The deploy**, at the end of every one, in this order:

```bash
php artisan queue:restart       # a worker on old code cannot build a new version
php artisan neuron-cloud:sync   # publishes the definitions and the endpoint
```

The sync fails, and publishes nothing, when a key is missing, the queue driver keeps no job, `retry_after` does not exceed `timeout`, no store is bound, nothing is registered or `APP_URL` is not `https`. Its message names the cause: fix that, never work around the check. Local development needs an HTTPS tunnel to be synced.

An owner can also request a sync from the platform's dashboard. The endpoint then answers with what the command would publish, `APP_URL/neuron-cloud` included, whatever host the request came through: nothing to configure. Where the SDK would refuse to publish, the endpoint answers `409 sync_refused` without the reason: run `neuron-cloud:sync` to read it.

## The Agent

The agent of **neuron-laravel-integration** stays as it is, with these changes:

```php
// app/Neuron/Agents/SupportAgent.php
use App\Neuron\Channels\ChannelFactory;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

/** The protocol of a segment that no request streams: a queued turn, a wake of the platform, a recovered turn. */
protected function streamAdapter(): AGUIAdapter
{
    return new AGUIAdapter(threadId: (string) $this->getThreadId());
}

/** Where such a segment delivers: the channel the browsers of the thread listen to. */
protected function channel(): StreamingChannelInterface
{
    return $this->channels->make((string) $this->getThreadId());
}
```

- **Add the two hooks**, with the `ChannelFactory` of the guide injected in the constructor (`protected ChannelFactory $channels`). A background segment is built by the container in a worker: nothing there can call `setStreamAdapter()` or `setChannel()` for it.
- **The channel is named from the thread alone**: a worker has no run ID of a browser's request, so the guide's `make($threadId, $runId)` loses its second argument.
- **Remove `recoverFailedTurn()`**: `$cloud->workflow(...)->recover()` replaces it.
- **Leave `leaseTimeout()` alone**: the `lease_timeout` of the config replaces it.

## Controllers

Inject `NeuronCloud`, bind the thread with `workflow()`, and prepare one segment per call. The thread is authorized by the FormRequest before, as in the guide.

**A streamed turn.** The guide's streaming endpoint with the SDK in place of the direct agent calls:

```php
// app/Http/Controllers/ChatController.php
use NeuronCore\Cloud\NeuronCloud;

public function stream(RunAgentRequest $request, NeuronCloud $cloud): StreamedResponse
{
    $threadId = $request->threadId();
    Cache::forget(SupportAgent::stopKey($threadId));

    $thread = $cloud->workflow('support-agent', $threadId);
    $thread->recover();                                   // finishes a failed turn; it streams where the agent's hooks say

    $adapter = new AGUIAdapter(threadId: $threadId, runId: $request->input('runId'), messages: $request->input('messages'), state: $request->array('state'));
    $thread->configure(fn (SupportAgent $agent) => $agent
        ->setStreamAdapter(fn (): AGUIAdapter => $adapter)
        ->setChannel(fn () => null)                       // this segment streams in the response, not on the channel
        ->addFrontendTools($request->frontendTools()));

    $events = $request->isContinuation()
        ? $thread->submitInputs($request->all(), new AGUIInputTranslator)->events()
        : $thread->start(new UserMessage($request->prompt()), stream: true)->events();

    $events->valid();                                     // admit now: a refusal is an HTTP status, not a frame

    return response()->stream(function () use ($events, $adapter): Generator {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $e) {
            report($e);
            foreach ($adapter->error($e) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }, 200, $adapter->getHeaders());
}
```

**A turn that outlives the request.** No job of your own: `dispatch()` queues the segment, a worker builds the agent from the thread ID, and its output reaches the browser through the agent's `channel()`.

```php
// app/Http/Controllers/BackgroundChatController.php
public function dispatch(BackgroundRunRequest $request, NeuronCloud $cloud): JsonResponse
{
    $thread = $cloud->workflow('support-agent', $request->threadId());

    $segment = $request->has('decisions')
        ? $thread->submitInputs($request->array('decisions'), new ApprovalTranslator())
        : $thread->start(new UserMessage($request->string('message')->value()), stream: true);

    return response()->json(['runId' => $segment->dispatch()], 202);
}
```

**A JSON turn** is the same two calls with `->run()`, which returns the state: `$state->isInterrupted()` tells an approval is pending, `$state->getMessage()` is the answer.

**A workflow and the webhook that answers its wait:**

```php
// app/Http/Controllers/OrderController.php
$runId = $cloud->workflow('order-fulfilment', "order:{$orderId}")->start(new OrderPlaced($orderId))->dispatch();

// The webhook of the payment provider. A run that is not at that wait is a 409: the provider calls again.
try {
    $cloud->workflow('order-fulfilment', "order:{$orderId}")->signal('payment.received', ['amount' => $amount])->dispatch();
} catch (InputTranslationException|WorkflowRefusedException) {
    return response()->noContent(409);
}
```

- **Prime before returning**, as in the guide: `$events->valid()` turns a refusal into a status.
- **`configure()` is for this request only.** The browser's AG-UI seed and frontend tools exist only here: a turn that needs them continues with `events()` in the request, never with `dispatch()`.
- **Dispatch after commit.** With `after_commit` on the queue connection, a segment dispatched inside a transaction is queued when it commits. Never wrap a turn in a transaction.
- **Reading is unchanged**: reload a conversation with `MessageStoreInterface` and `WorkflowEngine::inspect()`, as the guide does. Only starts and answers go through `NeuronCloud`.
- **`resetConversation()` and `abandon()` stay Neuron's own**: call them on the container's agent bound with `for($threadId)`.

### What the SDK replaces in the Laravel guide

| The guide | With the package |
|---|---|
| `RunSupportAgent` job, `$tries = 3`, `settle()`, `release()` | Deleted. `->dispatch()` queues the segment; the platform retries with a new message |
| `$agent->recoverFailedTurn()` | `$cloud->workflow(...)->recover()` |
| `$agent->for($threadId)->chat()` / `stream()` / `submitInputs()` | `$cloud->workflow('support-agent', $threadId)->start()` / `submitInputs()`, then `run()`, `events()` or `dispatch()` |
| The job sets the stream adapter and the channel | The agent's `streamAdapter()` and `channel()` hooks; `configure()` for a segment run in the request |
| `failed()` closes the browser stream | A listener of `InvocationSettled` |
| `leaseTimeout()` on the class | `lease_timeout` in `config/neuron-cloud.php` |

## Telling the Browser How a Queued Segment Ended

`NeuronCore\Cloud\Laravel\Events\InvocationSettled` carries the SDK's `Report` of every segment a job ran. It fires once more, with the outcome `observed`, when the queue gave a job up (a timeout, a killed worker): the report then says what the store shows. It replaces the `failed()` method of the guide's job: listen to it to close the stream of a segment that ended without telling the browsers.

```php
// app/Providers/AppServiceProvider.php, in boot()
Event::listen(InvocationSettled::class, CloseBrowserStream::class);
```

```php
// app/Listeners/CloseBrowserStream.php: whether browsers still wait for an end that nothing will send
return match ($report->outcome) {
    Outcome::Completed, Outcome::Suspended, Outcome::Failed => false,   // the segment told its own channel
    Outcome::Refused, Outcome::Rejected => !in_array($report->code, ['stale_attempt', 'stale_run', 'already_claimed'], true),
    Outcome::Aborted => $report->origin === 'application',              // the platform sends its own message again
    Outcome::Observed => $this->diedWithTheStreamOpen($report),         // the job died: read the snapshot
};
```

Read [invocation-settled.md](invocation-settled.md) when writing it: it has the complete listener, the one method it asks of the `ChannelFactory`, and what an `observed` report cannot tell.

- **It can fire twice, and before the platform has recorded anything**: a listener is idempotent.
- **`$report->run` can be a later run than `$report->runId`**: compare the two before acting on its status.
- **`$report->exception` exists in the worker only.** Log or store `$report->toArray()`; a queued listener receives the report without the exception.
- A segment that fails ends its job normally, and the package hands what it threw to Laravel's exception handler: it reaches the log and the error tracker like the exception of any job. No failed job is kept for it.
- The event does not fire for a segment run in a request with `run()` or `events()`: the request has the result.

## Tracing and Evaluation of Definitions

Stages 1 and 2 of the skill keep working as they are. What changes once agents are definitions:

- **Every segment the SDK executes is traced**, in a web request or in a worker, when `NEURON_CLOUD_TRACING` is on: one OpenTelemetry trace per run, named after its definition (`invoke_agent support-agent`), and every segment of a run lands in the same trace.
- **Once, by the SDK.** The container builds the workflow of a definition, so the package's hook gives it a listener as well. The SDK's own listener claims the segment, and the package's sends nothing for it: no second trace, and nothing to configure.
- **It is best effort.** A trace the platform refuses is written to PHP's error log and dropped: it never changes the segment or its report.
- **Set `NEURON_CLOUD_TRACING` in the environment of the workers too**, and restart them (`php artisan queue:restart`): a worker reads its config once. With `config:cache`, cache again.
- **Evaluators keep calling the agent directly**, on in-memory stores, as the guide builds them. Never evaluate through `$cloud->workflow()`: every item would be announced to the platform as a real run.

## Testing

Bind the SDK's `FakePlatform` as `neuron-cloud.http` and give the application its signing key, before `NeuronCloud` is first resolved:

```php
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Testing\FakePlatform;

$platform = new FakePlatform();
$this->app->instance('neuron-cloud.http', $platform);
config(['neuron-cloud.api_key' => 'test-key', 'neuron-cloud.signing_key' => $platform->signingKey]);

$cloud = $this->app->make(NeuronCloud::class);
$cloud->workflow('order-fulfilment', 'order:123')->start(new OrderPlaced('123'))->dispatch();

$platform->assertReported('order:123', Outcome::Suspended);   // the sync queue ran the segment in place
$platform->wake($cloud, 'order:123');                         // the platform's wake-up, signed, through the endpoint
```

- **With the `sync` queue of `phpunit.xml`**, a dispatched segment runs in place, before `dispatch()` returns, and `InvocationSettled` fires with its real report. A wake the fake platform sends runs in place the same way.
- **To look at what was queued**, `Queue::fake()` and `Queue::assertPushed(RunInvocation::class)`; `$job->invocation()` is the message.
- **The agent is faked as in the guide**: `setAiProvider(new FakeAIProvider(...))` on the container's agent, then `$this->app->instance(SupportAgent::class, ...)`. The definitions build the agent from the container, so they get the fake.
- **With `neuron-cloud.tracing.enabled` on**, each segment sends its trace to the fake, named after its definition: `$platform->assertTraced('invoke_workflow order-fulfilment')`, or `invoke_agent support-agent` for an agent.
- **`Http::fake()` sees nothing of this**: the SDK and the providers use Neuron's HTTP client.

## Pitfalls

- A turn started with `$agent->for($threadId)->chat()` in an application that registered the agent → not announced: no retry, no timer, and it replaces a failed run the SDK would have kept. Use `$cloud->workflow()`.
- A job of your own around a turn, or `$tries` on it → the turn runs twice. Use `->dispatch()`.
- `PersistenceInterface` as a `singleton`, or bound without the `select 1` → a worker hands a closed PDO to every segment after the database dropped the connection.
- `QUEUE_CONNECTION=sync` outside tests → `neuron-cloud:sync` refuses it; nothing would survive the request.
- `retry_after` at Laravel's 90 with `timeout` 300 → a second worker takes a job that still runs. The sync refuses it: set `DB_QUEUE_RETRY_AFTER=360`.
- One worker on the default queue only → nothing runs: the package uses `neuron-cloud` and `neuron-cloud-observe`.
- A global middleware, a rate limiter or a WAF rule on `/neuron-cloud` → the platform's messages are answered by something else. Exempt the route.
- `APP_URL=http://…` or a URL the platform cannot reach → the sync fails. Use the public `https` address, or a tunnel in development.
- `neuron-cloud:sync` before `queue:restart` → workers on old code abort the first messages of the new version. Restart first.
- An agent with no `channel()` hook → a queued turn, a platform retry or a recovered turn streams nowhere.
- `afterResolving(NeuronCloud::class, …)` to register a definition → a throw there leaves the singleton half configured for the life of a worker. Use `extend()`.
- `NEURON_CLOUD_TRACING=true` in the web environment only → requests are traced and queued segments are not.
- Evaluating through `$cloud->workflow()` → the platform lists every dataset item as a run.
- `Http::fake()` to fake the platform → the real one is called. Bind `FakePlatform` as `neuron-cloud.http`.
