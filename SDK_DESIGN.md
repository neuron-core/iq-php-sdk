# Neuron Cloud SDK: design

A proposal for `neuron-core/cloud-sdk`, the package that connects Neuron AI workflows and agents to Neuron Cloud. Milestones 0 to 3 of appendix D are implemented and tested: the public types, the ledger, the runner, the two test fakes, the protocol schemas, the local path (`workflow()`, `start()`, `submitInputs()`, `signal()`, `recover()`, `run()`, `dispatch()`), and the signed endpoint with App Sync (`handle()`, `sync()`). Everything else is still a proposal.

- **Builds on:** [DURABLE_PLATFORM_READINESS.md](DURABLE_PLATFORM_READINESS.md) and the decisions in its section 12.
- **Requires:** `neuron-core/neuron-ai ^4.1.8` (typed refusals, the snapshot fields, and a late copy of a continuation refused as stale in every interleaving).
- **Date:** 2026-10-10
- **Reading path:** sections 1 to 8 are what you need to approve the design. Section 9 onwards and the appendices are for building it.

## Contents

1. [The design in one page](#1-the-design-in-one-page)
2. [Questions for you](#2-questions-for-you)
3. [What ships in v1](#3-what-ships-in-v1)
4. [Packages and public API](#4-packages-and-public-api)
5. [Developer experience](#5-developer-experience)
6. [Costs and limits to accept](#6-costs-and-limits-to-accept)
7. [When things go wrong](#7-when-things-go-wrong)
8. [Decisions and the alternatives rejected](#8-decisions-and-the-alternatives-rejected)
9. [How it works](#9-how-it-works)
10. [The Laravel and Symfony bridges](#10-the-laravel-and-symfony-bridges)

Appendices: [A. Wire protocol](#appendix-a-wire-protocol) · [B. What the platform must do](#appendix-b-what-the-platform-must-do) · [C. Testing support](#appendix-c-testing-support) · [D. Implementation order](#appendix-d-implementation-order) · [E. How this design was checked](#appendix-e-how-this-design-was-checked)

---

## 1. The design in one page

Your workflow runs in your application and keeps its data in your database. Neuron Cloud keeps a list of your runs and a clock. When a run stops to wait (for a timer, for an event that your application will deliver, or for a retry after a failure), the SDK tells the platform what the run is waiting for. When a timer or a retry comes due, the platform calls one signed URL in your application. The SDK puts that call on your queue, a worker continues the run through Neuron, and the SDK tells the platform what the run looks like now. When your own code starts or answers a run, it goes through `$cloud->workflow()`, so the platform hears about that run too. Any call can arrive twice or late: Neuron and the SDK refuse the copy. If the platform hears nothing, it asks. Your database is always right.

Five rules carry everything else.

1. **Your store is the truth.** The platform holds a copy of identifiers and statuses, and reads the truth again whenever it is unsure.
2. **The platform sends three orders: start a run, wake a run and, only when a person asks for it, abandon a run.** It can also ask two questions: inspect a run, ping the endpoint. Nothing else comes in.
3. **Every execution says "starting", then "this is the run now".** These are the announcement and the report. A queue worker sends them directly. A web request hands them to your queue, so no call to the platform ever sits on a user's request.
4. **A start runs at most once.** The SDK writes every start that arrives through a queue or over the wire into a small ledger in your store, and refuses a second copy, even after the run has finished and its records are gone.
5. **The platform owns retries.** Your queue runs each segment once, and a retry is a new wake sent by the platform. The only messages your queue retries are the SDK's own notices to the platform, which run no workflow code, and a start, an answer or a signal of your own that was aborted before any workflow code ran, for the reasons 9.5 lists.

**What it costs.** A queue and a worker become mandatory, also for an application that only streams chat inside requests, and a request fails when the queue is down. A start that travels through a queue runs once or not at all. The SDK keeps one small row per workflow ID in your workflow store, and refuses `FilePersistence` and `InMemoryPersistence`. The signing key alone can start runs. Queue retries are off for segments: the platform's retry policy replaces `$tries`. Section 6 has the details.

### What crosses the wire

| Reaches the platform | Never leaves your application |
|---|---|
| Definition names and versions, your endpoint URL | Workflow state, step results, memos |
| Workflow IDs, run IDs, attempt numbers | Start inputs, prompts, messages |
| A status or an outcome: running, suspended, failed, completed | Answers, approvals, event payloads |
| For a wait: its number, its type, the event name, the time to wake | The text and the tool inputs of an approval request |
| The lease deadline of a running run | Exception messages and traces |
| The class name of an exception | Results |

Workflow IDs and event names do reach the platform, so they must not contain secrets. A thread ID built from a user ID is personal data there.

### Vocabulary

| Term | Meaning |
|---|---|
| Definition | A workflow or agent class registered under a name and a version |
| Workflow ID | The stable address of a workflow (`order:123`, a chat thread). It holds one run at a time |
| Run ID, attempt | One execution, and a counter Neuron raises each time a process takes that execution over |
| Segment | One uninterrupted stretch of a run, until it suspends, completes or fails |
| Fence | The run ID and attempt a wake or an answer carries. Neuron refuses it when they are no longer current |
| Lease | A time-limited claim on a running run. When it expires, another process may take the run over |
| Wake | An order with no input: Neuron decides whether a timer is due or a failed or dead run must be finished |
| Snapshot | What `inspect()` reads from your store: the run holding a workflow ID, or nothing |
| Ledger | The SDK's list, in your store, of the starts it has accepted |
| Invocation | One message in your queue: an order to execute one segment, or an announcement or an observe to post |
| Observe | A queued reminder: "read the store and tell the platform" |
| Manifest | The list of definitions and versions that the last `sync` published |
| Trigger, firing | What makes the platform start a run (a schedule, an API call), and one occurrence of it. Triggers are set up on the platform, not in the SDK |
| Operator | A person acting on the platform, through its dashboard or API |
| Retry policy | How many times, and how far apart, the platform wakes a failed or dead run before it gives up and alerts. It is a setting kept on the platform: `register()` has no retry argument and the protocol carries none. It replaces the guides' `$tries`. Its values are not designed in this document |

## 2. Questions for you

Each is a decision that is yours. Where a question has them, the recommendation comes first, then its cost, then what a "no" means.

1. **The public API of section 4.** Confirm the names: `NeuronCloud` with `register()`, `setPersistence()`, `setDispatcher()`, `sync()`, `handle()`, `invoke()`, `workflow()`; `ManagedWorkflow`, `PendingSegment`, `DispatcherInterface`, `Invocation`, `Report`, `SyncResult`, the enums `Verb`, `Outcome`, `Code`, and `CloudException`; `Testing\FakePlatform` and `Testing\FakeDispatcher` with the methods of appendix C; and in the bridges the config keys, the `InvocationSettled` event, the `neuron-cloud:sync` command and the route `POST /neuron-cloud`. One naming choice to check: `NeuronCloud::setPersistence()` takes a factory closure, as Neuron's `setResources()` and `setChannel()` do, where `Workflow::setPersistence()` takes an instance.

2. **The root after neuron-ai 4.1.5.** The Cloud sections left the guides, so this design has no `GuzzleTransport` and no `listener()`, `dataset()` or `evaluationOutput()`. Recommended. If they are coming back, they fit beside the rest on the same root.

3. **A ledger in the user's store, under a `__neuron_cloud:` partition.** Recommended: it is what takes the platform out of correctness. The costs: two more store operations per queued start; one row of about 260 characters per workflow ID stays until the developer deletes it with a statement the guide gives; and the SDK writes its own records into a store whose interface says the engine owns all record semantics, under a prefix the engine keeps for itself, so core should reserve `__neuron_cloud:` in one sentence. If no: the platform must remember finished runs and answer before every start, which is the first design (section 8).

4. **No completion retention under the SDK.** Recommended: fewer rules, and a chat thread is free the moment a turn ends. The cost: when a worker dies right after its last write, the platform cannot tell that the run finished and shows "ended, outcome unknown". If no: a release step, a rule for who releases what, and threads that can be held until the release.

5. **Web requests never call the platform.** A segment run in a request, like the chat turn of example (b), still executes in the request. Only the SDK's two notices to the platform (the announcement and the observe) go through your queue. Recommended for chat latency. The cost: two messages go on the queue per segment run in a request, and such a request fails when the queue is down. A durable queue and a worker are needed whatever you answer here, because every order from the platform (a wake that retries a failed turn, a timer, a start) is queued by the endpoint and executed by a worker (9.4). If no: one call of up to 3 seconds before a segment and one after it, on the user's request, whenever the platform is slow.

6. **A queued start runs once or not at all, and the platform never replaces a lost one by itself.** Recommended. The cost: a start that was claimed and did not run, or that is still unclaimed when its use-by time passes, waits for a person (section 6); a start your own code dispatched has no operator; and for a schedule whose workflow ID contains the date, the next firing does not make up for the lost one. Sending the same start again is part of v1 before the claim and within its use-by time, because the ledger stops any copy that did claim: the platform does so for its own when a report or an `inspect` shows it unclaimed (appendix B, rule 13), and the bridges do so for yours after the aborts listed in 9.5. A start that stays silent is not sent again: the platform asks about it only after its use-by time (appendix B, rule 12). Not in v1: queueing a start of yours again after its worker was killed or its job was lost. If no to the whole rule: the platform has to prove a start never ran before replacing it, and two ways to be wrong were reproduced.

7. **The platform retries a failed chat turn like any other run.** The retry rule has no exception. A turn that failed because the browser closed or the user pressed Stop is run again in a worker: the provider is paid again, the answer goes to the agent's channel and not to the closed response, and a message sent while that retry runs is refused with 409 until it ends. The guides today finish a turn that failed inside a request only when the next message arrives; a queued turn is retried by the queue (`$tries = 3`, Messenger's three retries). Decide before the guides are written: one rule for every run, as designed, or no platform retry for a run whose failed segment ran in a request.

8. **No events through the platform in protocol 1.** Your application delivers an event itself with `signal()`. One that arrives before its wait is refused and not kept, so the workflow looks the fact up before it waits. The platform starts runs from a schedule or its API, not from an event. Recommended for v1; the verbs `answer` and `signal` are reserved. This closes "event semantics" in section 12 of the readiness document.

9. **No payload in protocol 1.** A platform-started workflow loads its input from its workflow ID, and a platform-started agent builds its prompt in `startEvent()`. Recommended; the field `input` is reserved. If no: a hydrator and a validator in the SDK, and payload storage on the platform.

10. **The SDK's persistence and lease replace the class's own.** Recommended: a plain `Workflow` defaults to in-memory storage and no lease, and the SDK cannot read what a class declares. The cost: an agent that overrides `leaseTimeout()` for a slow tool is silently cut to the bridge's `lease_timeout`, which is one value for every definition listed in the config. A lease shorter than the slowest node lets a second worker take a live run, and the node in flight then runs twice. A definition that needs its own lease is registered in a provider with `register(..., leaseTimeout: ...)`. A public getter in core would let the SDK verify instead of override.

11. **Exception class names reach the platform**, never messages. Recommended: without them the platform cannot group failures or choose a retry policy.

12. **The signing key alone can start runs.** Whoever holds it can start any registered definition that starts without input, on any workflow ID. The platform cannot stop it, only alert afterwards. The key also acts through whichever registered definition a message names: a wake that names another definition continues the run on that definition's nodes, and an abandon that names another definition skips the guard of an Agent. The SDK cannot refuse either, because Neuron stores no definition with a run: only a core change, storing the definition with the run, closes it. Recommended for v1: accept it. The alternative that keeps correctness local is the reserved per-definition switch `platformStart`, with which the SDK refuses a wire start for a definition that did not allow it.

13. **`recover()` before every agent turn in the guides.** Today `recoverFailedTurn()` is called only by the endpoints that run a turn inside the request (in the Symfony guide only before a new turn, not before a continuation); the endpoints that queue a turn do not call it, and the job or handler finishes a failed or dead run it meets. Recommended. It is a second retry path beside the platform's wake; the fence lets only one of the two execute.

14. **Bridge floors**: Laravel `^12.15 || ^13.0` on PHP 8.2 (12.15 is what the streaming example of section 5 needs), Symfony `^6.4` on PHP 8.1. Recommended.

## 3. What ships in v1

The core package (registration and App Sync, one signed endpoint, the runner, local starts and answers, the ledger, two test fakes, the protocol as JSON Schema) and two bridge packages, for Laravel and for Symfony.

| Not in v1 | Why | What the protocol reserves |
|---|---|---|
| Start inputs and event payloads through the platform | Only coordination data crosses the wire (question 9) | The field `input` on a start |
| Events routed or buffered by the platform, answers delivered by the platform | Your application already receives the webhook or the click (question 8) | The inbound verbs `answer` and `signal` |
| A replacement start issued by the platform on its own | It would have to prove that the first one never ran (question 6) | An `inspect` that first writes the run ID into the ledger, so that no copy still in a queue can run |
| Completion retention | The ledger does its job with less (question 4) | Nothing |
| A retry policy declared in code | The platform holds it | Nothing |
| A local abandon, `resetConversation()`, a structured-output turn | Not wrapped. Call Neuron's own `abandon()` or `resetConversation()` on your workflow, on the same store; the platform notices at its next wake or read. A structured turn is started with `start()` and an `AgentStartEvent` whose options name the output class | Nothing |
| Tracing, datasets, evaluation | neuron-ai 4.1.5 removed them from the guides; not designed here | The method names `listener`, `dataset`, `evaluationOutput` |
| Failure classification, a "do not retry" marker | A later core change; the platform can key on the exception class | The report field `retryable` |
| A per-definition switch "the platform may start this" | Question 12 | The manifest field `platformStart` |
| Segment budget, node heartbeat, listing runs, child workflows, step telemetry, a pull transport, a PSR-15 adapter, a core CLI binary | Decided earlier, or no user yet | Nothing |

## 4. Packages and public API

| Package | Namespace | Requires |
|---|---|---|
| `neuron-core/cloud-sdk` | `NeuronCore\Cloud\` | `php ^8.1`, `neuron-core/neuron-ai ^4.1.8`, nothing else |
| `neuron-core/neuron-cloud-laravel` | `NeuronCore\Cloud\Laravel\` | the core, Laravel `^12.15 \|\| ^13.0` (PHP 8.2) |
| `neuron-core/neuron-cloud-symfony` | `NeuronCore\Cloud\Symfony\` | the core, Symfony `^6.4` and later (PHP 8.1) |

Three repositories, like `neuron-ai` and `neuron-laravel`. The core adds no dependency: outbound HTTP uses neuron-ai's `HttpClientInterface`, the endpoint returns neuron-ai's `HttpResponse`, and signing is `hash_hmac`.

### The core's public surface

```php
namespace NeuronCore\Cloud;

class NeuronCloud
{
    public function __construct(
        protected string $apiKey,
        protected ?string $signingKey = null,                 // only the endpoint needs it
        protected string $platformUrl = 'https://cloud.neuron-ai.dev',
        ?HttpClientInterface $httpClient = null,              // null: a CurlHttpClient that follows no redirect
    );

    /** @param Closure(string $workflowId, ?string $runId): Workflow $factory */
    public function register(string $name, Closure $factory, int $version = 1, int $leaseTimeout = 600): static;
    /** @param Closure(): PersistenceInterface $factory called for every workflow the SDK builds */
    public function setPersistence(Closure $factory): static;
    public function setDispatcher(DispatcherInterface $dispatcher): static;

    public function sync(string $endpointUrl): SyncResult;                                // App Sync
    public function handle(string $method, array $headers, string $body): HttpResponse;   // the endpoint; never throws
    public function invoke(Invocation $invocation): Report;                               // the body of a queue job
    public function workflow(string $name, string $workflowId): ManagedWorkflow;          // what your own code starts or answers
}

/** A registered workflow bound to one workflow ID. */
final class ManagedWorkflow
{
    /** @param Closure(Workflow): mixed $callback adjusts the copy that run() and events() execute, for the segments
     *  prepared after this call; dispatch() and recover() never apply it */
    public function configure(Closure $callback): static;
    /** @param Event|Message|Message[]|null $input
     *  @param bool $stream for messages only: whether the provider streams its answer */
    public function start(Event|Message|array|null $input = null, bool $stream = false): PendingSegment;
    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null): PendingSegment;
    public function signal(string $event, array $payload = []): PendingSegment;
    public function recover(): ?WorkflowState;      // finish a failed or dead run now; null when there is nothing to do
}

final class PendingSegment
{
    public function run(): WorkflowState;           // in this process
    public function events(): Generator;            // in this process, streaming
    public function dispatch(): string;             // on your queue; returns the run ID
}

interface DispatcherInterface
{
    public function dispatch(Invocation $invocation): void;   // hand one message to the application's queue
}

final class Invocation      // what travels in your queue
{
    public readonly string $invocationId;
    public readonly Verb $verb;
    public readonly string $origin;      // 'platform' or 'application'; set by the SDK, never read from the wire
    public readonly string $definition;
    public readonly string $workflowId;
    public readonly string $runId;
    public readonly ?int $attempt;       // the fence of a wake, an answer or a signal
    public readonly ?int $expiresAt;     // the use-by time
    // only in your queue, never read from the wire:
    public readonly ?string $signal;     // the event name of a signal
    public readonly ?array $payload;     // an answer or a signal payload; PHP-serialized and base64-encoded in toArray()
    public readonly ?string $event;      // the start event, PHP-serialized and base64-encoded
    public readonly ?array $body;        // a prepared announcement; for an observe, the segment's invocationId and
                                         // verb and, when the job got that far, how it ended

    public function toArray(): array;    // JSON-safe, unless a signal names an event that is not UTF-8
    public static function fromArray(array $data): self;
}

final class Report          // what invoke() returns, what InvocationSettled carries and what the platform receives
{
    // readonly properties, one per report field of appendix A: $invocationId, $verb, $origin, $definition, $workflowId,
    // $runId, $outcome, $code, $errorClass, $executedAttempt, $run, $claimed, $snapshotRead, $at

    /** @internal the SDK serializes its own reports */
    public function toArray(): array;
}

final class SyncResult
{
    public readonly bool $modified;     // false: nothing changed; the sync commands print it
    public readonly array $retirable;   // name => int[]: older versions with no open run
    public readonly array $stranded;    // name => [version => open runs]: gone from the code, runs remain
}

enum Verb: string { case Start = 'start'; case Wake = 'wake'; case Answer = 'answer'; case Signal = 'signal';
    case Announce = 'announce'; case Observe = 'observe'; }

enum Outcome: string
{
    case Completed = 'completed';
    case Suspended = 'suspended';
    case Failed = 'failed';
    case Refused = 'refused';     // Neuron refused the request, or the guard before a wake did
    case Rejected = 'rejected';   // the SDK refused it for good
    case Aborted = 'aborted';     // nothing ran; it may be sent again unless the start was already claimed
    case Observed = 'observed';   // nothing is known about the call; only the store was read
}

enum Code: string { /* one case per SDK and platform code of appendix A */ }

class CloudException extends NeuronException
{
    public function __construct(string $message, public readonly Code $reason, ?Throwable $previous = null);
}

namespace NeuronCore\Cloud\Testing;

class FakePlatform implements HttpClientInterface {}
class FakeDispatcher implements DispatcherInterface {}
```

Everything else is internal, with protected members, under `NeuronCore\Cloud\Runtime\`.

- **A registration is a name, a closure, a version and a lease.** One `register()` call per version; the highest is current. There is no definition class to write.
- **The factory receives the workflow ID and the run ID** (null when the SDK reads a snapshot, and when the endpoint abandons a run). PHP ignores arguments a closure does not declare, so `fn () => new OrderWorkflow()` is valid. The SDK calls `for($workflowId)` itself.
- **Local calls throw what Neuron throws**: `WorkflowRefusedException` (and its two subclasses), `InputTranslationException`, a plain `WorkflowException` for an answer or a signal payload that is not JSON-compatible, or the node's own exception. `CloudException` covers only what Neuron cannot say.
- **`workflow()` checks what it is given and reads no store.** It throws a `CloudException`: `not_configured` before `setPersistence()`, `unknown_definition` for a name that is not registered in this process, and `invalid_request` for a workflow ID outside Neuron's rule. Neuron refuses such an ID itself, but for a start run in place only after the segment was announced. The handle uses the persistence and the dispatcher that are set when it is called, so call `setPersistence()` and `setDispatcher()` first.
- **`handle()` takes the headers in either shape**: name => value, as `getallheaders()` gives them, or name => list of values, as a framework does. Names are read in any case, and of two names that differ only in case the first is read. The values of a list are joined with a space, so each signature is tried. A value that is not a string is ignored, and `$_SERVER` keys are not read.
- **`configure()` applies to the segments prepared after it**, and a second call replaces the first. `run()` and `events()` apply it just before the announcement. `dispatch()` and `recover()` never do: a dispatched segment is built by the factory in a worker, and the wake of `recover()` delivers where the class's own hooks say. The persistence, the lease and the completion policy of 9.1 stay the SDK's: they are imposed again after the callback.
- **A `PendingSegment` is used once.** A second `run()`, `events()` or `dispatch()` on the same object, or on a copy of it, throws a `CloudException` (`segment_used`): it would execute the same run ID again. The first use counts whatever it threw (`dispatch_failed`, `RunInFlightException`, the exception of a `configure()` closure). An answer or a signal prepared again carries the same fence, so a copy the queue took after all is refused as stale. A start prepared again is a new run under a new run ID.
- **With no dispatcher set, every message runs in place.** That is for scripts and tests. A notice that cannot be posted in place counts as a dispatcher that threw, so an unreachable platform then fails `run()` and `recover()` before anything executes. `dispatch()` executes the whole segment before it returns, and returns the run ID also when a node threw.
- **`register()` checks what it is given.** It throws a `CloudException` (`invalid_request`) for a name or a version the protocol cannot carry and for a lease under 1 second. A second call with the same name and version replaces the first.
- **The workflow must extend `NeuronAI\Workflow\Workflow`**, because the setters the SDK needs are on the concrete class, not on `WorkflowInterface`.

## 5. Developer experience

### (a) Plain PHP: an event wait and a timer

`OrderWorkflow` is ordinary Neuron code and knows nothing about the SDK. Its start event `OrderPlaced` carries the order ID; its first node waits with `awaitEvent('payment.received', $deadline)` and its second with `sleepUntil()`.

```php
// src/RedisDispatcher.php (autoloaded). Plain PHP has no queue: you supply one.
class RedisDispatcher implements DispatcherInterface
{
    public function __construct(protected Redis $redis) {}

    public function dispatch(Invocation $invocation): void
    {
        $this->redis->rPush('neuron-cloud', json_encode($invocation->toArray(), JSON_THROW_ON_ERROR));
    }
}

// bootstrap.php: required by the endpoint, the worker and the sync script
$pdo = new PDO(getenv('DATABASE_DSN'));      // the database with Neuron's workflow table
$redis = new Redis();
$redis->connect('127.0.0.1');

$cloud = (new NeuronCloud(apiKey: getenv('NEURON_CLOUD_API_KEY'), signingKey: getenv('NEURON_CLOUD_SIGNING_KEY') ?: null))
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->register('order-fulfilment', fn () => new OrderWorkflow());

// public/neuron-cloud.php: the endpoint
$response = $cloud->handle($_SERVER['REQUEST_METHOD'], getallheaders(), file_get_contents('php://input'));
http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) { header("{$name}: {$value}"); }
echo $response->body;

// bin/worker.php: one message, one call
while ([, $job] = $redis->blPop(['neuron-cloud'], 0)) {
    $invocation = Invocation::fromArray(json_decode($job, true));
    try {
        $cloud->invoke($invocation);
    } catch (CloudException $e) {
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;
        $redis->rPush($final || $spent ? 'neuron-cloud:failed' : 'neuron-cloud:later', $job);
    }
}

// bin/sync.php: the last step of a deploy
$cloud->sync('https://shop.example.com/neuron-cloud.php');

// checkout: start locally, on the queue...
$cloud->workflow('order-fulfilment', "order:{$orderId}")->start(new OrderPlaced($orderId))->dispatch();
// ...or run the first segment in this process
$state = $cloud->workflow('order-fulfilment', "order:{$orderId}")->start(new OrderPlaced($orderId))->run();

// payment webhook: answer the wait locally
try {
    $cloud->workflow('order-fulfilment', "order:{$orderId}")->signal('payment.received', ['amount' => $amount])->dispatch();
} catch (InputTranslationException|WorkflowRefusedException) {
    http_response_code(409);                         // the run is not at that wait: the sender retries
}
```

Run `bin/worker.php` under a process supervisor, one process per concurrent segment. A cron moves the `later` list back every minute, which simplifies the delays of 9.5. A real worker also reads the `Report` that `invoke()` returns and logs a start of your own that did not run (`rejected`, `refused`, or `aborted` after its claim): nothing else in plain PHP tells you, although `dispatch()` returned its run ID. The `failed` list holds what the platform refused for good, which a person replays after fixing the key or the URL, and invocations of your own that passed their use-by time. Section 9.5 says which `CloudException` means which.

### (b) Laravel: a chat agent with tool approval

`SupportAgent` is the agent of the Laravel guide. This is the guide's own streaming endpoint with the SDK in place of the direct agent calls: eight lines change, and the agent gains two hooks.

```php
// config/neuron-cloud.php, with NEURON_CLOUD_API_KEY and NEURON_CLOUD_SIGNING_KEY in .env
'definitions' => ['support-agent' => App\Neuron\Agents\SupportAgent::class],

public function stream(RunAgentRequest $request, NeuronCloud $cloud): StreamedResponse
{
    $threadId = $request->threadId();
    Cache::forget(SupportAgent::stopKey($threadId));

    $thread = $cloud->workflow('support-agent', $threadId);
    $thread->recover();                                   // replaces the guide's recoverFailedTurn()

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

- **Refusals.** A refusal thrown by `$events->valid()` is rendered by the mapping the guide already puts in `bootstrap/app.php` (`RunInFlightException` to 409, `InputTranslationException` to 400). Add a renderer there for `CloudException`: 503 for `dispatch_failed` (your queue did not take the announcement) and for `unknown_version` (this process runs older code than the run was started on); the other reasons are programming errors.
- **After `recover()`.** When an approved tool had failed, `recover()` replays the approval the run had already accepted and may end the turn, with its output going where the agent's own hooks say and not to this response. The `submitInputs()` that follows in the same request then finds no wait and throws `InputTranslationException`, which the mapping renders as 400 although the decision was applied. How a chat client should treat that is for the guides to settle, with question 7.
- **Background segments.** An approval that should continue in the background is one call: `$thread->submitInputs($decisions, new ApprovalTranslator())->dispatch()`. A background segment (a dispatched approval, a wake from the platform) is built by the factory in a worker, with no browser request. `SupportAgent` therefore declares what the guide's job used to set: a `streamAdapter()` hook and a `channel()` hook that name the destination from the thread ID.
- **What a background segment does not have.** The AG-UI seed (`runId`, `messages`, `state`) and the browser's frontend tools come from the browser's request. A turn that needs either runs its continuation in the request with `events()`, not with `dispatch()`, and a platform retry of such a turn runs without them.
- **A chat turn needs no `AgentStartEvent` in application code**: `start()` fills the agent's own start event with the messages. Only a structured-output turn builds one (section 3).

### (c) Symfony: a workflow the platform starts

`NightlyReconciliation` is an ordinary workflow service. A schedule on the platform starts it every night with the workflow ID `reconciliation:{date}` and no input; the first node reads the date from `$state->getWorkflowId()`. Its last node checks whether the books are closed and, if not, waits with `awaitEvent('books.closed', $deadline)` and checks again when it wakes.

```yaml
# config/packages/neuron_cloud.yaml
neuron_cloud:
    platform_url: '%env(NEURON_CLOUD_PLATFORM_URL)%'
    api_key: '%env(NEURON_CLOUD_API_KEY)%'
    signing_key: '%env(NEURON_CLOUD_SIGNING_KEY)%'
    transport: async
    definitions:
        nightly-reconciliation: App\Workflow\NightlyReconciliation

# config/routes/neuron_cloud.yaml
neuron_cloud:
    resource: '@NeuronCloudBundle/config/routes.php'
```

```php
// where the books close
try {
    $this->cloud->workflow('nightly-reconciliation', "reconciliation:{$date}")->signal('books.closed')->dispatch();
} catch (InputTranslationException|WorkflowRefusedException) {
    // the run is not at that wait yet: it checks the books itself before waiting
}
```

Not shown, and required: a `PersistenceInterface` service declared `shared: false` (the container build fails otherwise), the `async` transport with `redeliver_timeout` above the longest segment, the path `/neuron-cloud` outside the firewall, a worker (`messenger:consume async`), and `framework.router.default_uri` set to the public URL, because the sync command has no request to read it from. The deploy runs `bin/console neuron-cloud:sync`. The nightly schedule and its workflow-ID pattern are created on the platform; nothing in the application declares them.

### What you must guarantee

1. **The factory rebuilds the workflow from the workflow ID alone.** A worker has no request and no logged-in user. A platform-started agent builds its prompt in its own `startEvent()` hook.
2. **One store and one serializer, shared by every process and by all versions of a definition**, with workflow IDs unique across the definitions that share it. For agents, a durable message store. The SDK's persistence and lease replace whatever the class declares.
3. **Per-run input goes in the start event**, never in a state seed. Side effects live inside `memoize()`, and tools are idempotent: the node in flight when a worker dies runs again.
4. **The lease exceeds the slowest node.** The job timeout exceeds the longest segment. The queue's `retry_after` or `redeliver_timeout` exceeds the job timeout. No database transaction is open around a segment.
5. **Every start and answer of a registered workflow goes through `$cloud->workflow()`.** A direct `chat()` or `run()` is not announced, so the platform never fires its timers or retries it, and an agent's direct `chat()` silently replaces a failed or dead run that the SDK would have refused to replace. Abandoning a run and resetting a conversation are the exception: use Neuron's own `abandon()` and `resetConversation()`.
6. **The user is authorized for the workflow ID before the SDK is called.**
7. **The queue is durable and as trusted as your code.** It carries the SDK's announcements and reports, and a queued start, answer or signal holds serialized PHP: whoever can write to the queue can run code in the workers and answer any wait.
8. **A breaking change gets a new version**: renaming or moving a node class, renaming or reshaping an event, state or request class, or changing the order of waits or the memo names in a node where a run can be suspended. The old class stays registered until `sync` lists it as retirable.
9. **An event sent before its wait is the workflow's job to look up.** `signal()` refuses when the run is not at that wait; nothing buffers the event.

The SDK checks four of these for you and fails the deploy: the persistence class, a queue that would run in the request, `retry_after` against the job timeout (Laravel), and a bus that wraps the handler in a transaction (Symfony). The rest it cannot see.

### Moving from the integration guides

| The guide today | With the SDK |
|---|---|
| `$tries = 3`, Messenger's three retries: the queue is the recovery mechanism | The queue runs a segment once. The platform retries with a fenced wake |
| Continuations rebuilt at job time from a fresh `inspect()` | Fences are captured when the answer is submitted and travel with it. An answer is staged once: a failed run is finished by a wake or by `recover()`, which replay the accepted answer |
| `settle()` and `release()`: the job heals other runs and waits for leases | The job reports the refusal and ends. The platform retries the holder. A dispatched start that meets a live run is refused in the request, as a 409 |
| The class's `leaseTimeout()` hook sets the lease | The `lease_timeout` key of the bridge config does; the hook is ignored |
| `failed()` or `PublishRunFailure` close the browser stream | A listener on the bridge's `InvocationSettled` event. On Laravel, after a job timeout or a killed worker it fires with an `observed` report once the queue gives the job up. On Symfony it fires that way only for a message given up before its handler ran: there is no job timeout, and on the Doctrine transport the message of a killed worker is delivered to the handler again after `redeliver_timeout`, so the event carries the report of a second copy (`stale_attempt`, `already_claimed`, or `expired` for a start past its use-by time), not an `observed` one |
| The job sets the stream adapter, the channel and the frontend tools | The agent's own `streamAdapter()` and `channel()` hooks for background segments, built from the thread ID alone; `configure()` for a segment run in the request with `run()` or `events()`; `recover()` does not apply it |
| `resetConversation()` when a failed turn cannot be finished | Unchanged and outside the SDK: call it on your own agent instance bound to the thread, with the same persistence the SDK was given. It is the only way to free a thread whose failed turn ends on a tool call: `recover()` throws again and the platform's `abandon` is answered `409 abandon_refused` |

Runs that were started before the migration keep working: a run ID the SDK did not mint is handled on the lowest registered version. One exception: a completion the old code retained with `retainCompletionUntilAcknowledged()` and had not acknowledged. The SDK never acknowledges, so acknowledge such runs with the old code before the first SDK call. A second exception: a run started before the migration under a reserved run ID of your own that looks minted (one to six digits not starting with 0, then a dash, such as `2026-10-07-nightly` or `42-import`) is taken for a run of that version, and every wake or answer of it is aborted `unknown_version`. Finish or abandon such runs with the old code first.

## 6. Costs and limits to accept

- **Your queue becomes part of every local segment.** A segment run in a request puts two messages on your queue, also when the platform is healthy, and fails with a `CloudException` before anything runs when the queue is down. The platform hears of that run only through those two messages. If both are lost, a run the platform has never seen stays unknown to it and its timer never fires. For a workflow ID it already knows, it notices at its next read.
- **A queued start runs once or not at all.** It does not run when its worker dies between the claim and Neuron's first write, when the store fails at that write, when the store commits the claim and loses the answer, or when the job waits in the queue longer than its use-by time (one hour for a start your code dispatched). The queue runs a job once. Before the claim, the platform sends one of its own starts again when a report or an `inspect` shows it unclaimed, and the bridges queue one of yours again after the aborts listed in 9.5; after the claim nothing does. A start of yours whose job is killed or lost is not queued again at all, even unclaimed. A platform start that did not run is shown to an operator. One of yours is gone although `dispatch()` returned its run ID: `InvocationSettled` tells you when a report exists. With the guides' queue retries it would have run.
- **"Lost" and "finished without a report" can look the same.** A start that died between the claim and Neuron's first write, and a run that completed in a worker that died before reporting, both leave no run and a claimed run ID. The platform shows "ended, outcome unknown" and a person decides. Only an unclaimed run ID means "never ran", and only for a start that travelled through a queue: a start run in the calling process is never claimed.
- **A wrong or revoked API key stops nothing when a dispatcher is set.** Runs execute, every announcement and report ends as a failed job, and no timer or retry fires for those runs until the key is fixed and the jobs are replayed.
- **One small record per workflow ID stays in your store.** A ledger entry is about 50 bytes and is dropped by the next claim on that workflow ID once it is an hour past its use-by time. The record itself is never removed by the SDK: about 260 characters per workflow ID on SQL, for every workflow ID that was ever started through a queue or abandoned by the platform. The persistence interface cannot list or expire records, so cleaning up is a statement the guide will give: on SQL, rows of that partition prefix not updated for a day; on Redis, a key scan of the same prefix; on Eloquent it needs the model's timestamps. Never delete a younger record, and never let the store evict keys: a ledger record that disappears lets a late copy of its start run again.
- **The signing key alone can start runs** (question 12). It also acts through whichever registered definition a message names, and an abandon that names another definition skips the guard of an Agent. Only a core change, storing the definition with the run, closes that.
- **The ledger uses a partition name that begins with `__`.** It relies on Neuron continuing to refuse such workflow IDs.
- **Clocks.** Your hosts must agree with each other within a few seconds. A lease is written with one host's clock and judged with another's, so a difference of D seconds shortens every lease by D, and a lease that ends early lets a second worker take a live run. That is true of Neuron today. Under the SDK it shows sooner, because the platform wakes a run as soon as its lease has passed instead of waiting for the next user message.
- **`FilePersistence` and `InMemoryPersistence` are refused.** Their conditional writes do not hold across processes.
- **Two calls to the platform per segment run in a job** (announcement and report, 3 seconds each at most): a slow platform adds up to 6 seconds to a job, never to a web request.
- **Store operations the SDK adds.** A queued or platform start: one read and one conditional write for the claim. A start run in a request: no claim. A dispatched local start: one snapshot read in the request. A wake: one snapshot read before it executes. Every segment: the snapshot read for its report. An observe of a start: one ledger read. An `inspect` with a run ID: one ledger read. An `abandon`: one ledger read, and one conditional write when the run ID was not there, which creates the ledger record of a workflow ID that had none.

## 7. When things go wrong

| # | Failure | What happens | Why the run stays correct |
|---|---|---|---|
| 1 | The same start is delivered twice, by the platform or by the queue | The second copy finds its run ID in the ledger and reports `rejected: already_claimed` | The claim is one atomic write in your store |
| 2 | A copy of a start arrives after its run completed and its records are gone | The same | The ledger outlives the run |
| 3 | A start arrives after its use-by time | `rejected: expired`; nothing is claimed | The time is checked where execution happens, and again at the claim |
| 4 | A wake or an answer is delivered twice | `refused: stale_attempt` with the snapshot, or `stale_run` when the first copy completed the run. | Every admission that executes raises the attempt, and a completed run leaves no record |
| 5 | A report is lost | An observe waits in your queue with the outcome and reports the state as it is then | The outcome was committed before the report was built |
| 6 | The platform is down for an hour | Segments keep running. Announcements and observes pile up on their own queue and drain afterwards. Timers fire late | Nothing local waits for the platform |
| 7 | A worker is killed mid-segment | No report. Laravel's `failed()` queues an observe; on Symfony the listener does so only when the transport gives the message up before the handler runs. Otherwise the platform asks when the lease it was told runs out. It then sends a wake | The lease and conditional writes: committed steps replay, only the node in flight runs again |
| 8 | A slow worker is taken for dead | Its next write is refused, `stale_attempt`. The node it was in has run in both workers, which is why the lease must exceed the slowest node and side effects sit in `memoize()` | Conditional writes |
| 9 | A node throws | `failed`, with the exception class and the snapshot | `failed` is committed before the exception surfaces |
| 10 | The store fails during a commit, or cannot be read for the report | The outcome and the snapshot can disagree: `failed` with a snapshot that says `running`, `suspended` or no run, when a write was committed and its answer lost; `aborted` although the attempt was raised. Or `snapshotRead` is false, and the platform asks | The platform plans from a snapshot that was read, never from the word |
| 11 | A job is accepted and never runs | Silence. The platform asks: a wake is sent again; a start is asked about 300 seconds after its use-by time, too late to be sent again, and closed as lost when unclaimed or as "ended, outcome unknown" when claimed | A `202` promised nothing |
| 12 | A deploy removes or has not yet delivered a version | `404` at the endpoint or `aborted` in an old worker; both are sent again while the version is in the manifest | Runs meet the code they were started on |
| 13 | A timer fires early (clock difference) | Neuron returns the same wait and attempt | Neuron reads its own clock |
| 14 | An event arrives before its wait; an approval is sent twice | `signal()` throws before anything is queued; the second approval is `stale_attempt`, or `stale_run` when the first one completed the run | Nothing was written; an accepted answer cannot change |
| 15 | A browser closes a streamed segment | Neuron fails the run. The observe reports it, or on a hard abort the announcement's lease does | The platform retries by policy (question 7) |
| 16 | A start meets a run that failed earlier on the same workflow ID | `RunInFlightException` to the caller, in the request | A start never replaces a run. `recover()` is the explicit verb |
| 17 | Wrong signing key, altered body, replayed request | `401`; a replayed start is a duplicate (row 1), a replayed wake is stale (row 4) | Nothing unverified runs |
| 18 | The worker dies between the claim and Neuron's first write | That start never runs. The platform cannot tell it from a run that completed without a report, and shows "ended, outcome unknown"; a local one is lost with its job | The claim is never undone, so nothing can run twice |
| 19 | The API key is wrong or revoked | Segments run. Every announcement and report is answered `401` and ends as a failed job that can be replayed | The store is untouched; the platform catches up from the replayed observes |

## 8. Decisions and the alternatives rejected

| Decision | Chosen | Alternative, and why not |
|---|---|---|
| Stopping a repeated start | A ledger in your own store | Asking the platform before every start, with the platform remembering finished runs. The first merged design did this; its review found three ways to run a workflow twice in those rules, and it made the platform part of correctness |
| Completion retention | Off | Keeping a completed run until it is acknowledged. With the ledger its only remaining use is to survive a worker dying right after its last write; it costs a release step, a rule for who releases what, and chat threads that can be held |
| Undoing a claim | Never | Undoing it when nothing seems to have run. When the store commits a write and loses the answer, "nothing ran" is wrong and the start runs twice (reproduced) |
| Replacing a lost start | An operator, or the next firing | The platform inferring "it never ran" from an empty answer. Two ways to be wrong were reproduced (a ledger entry already dropped; a worker clock behind) |
| Platform calls from a web request | None; the queue carries them | A direct call with a 3 second timeout: up to 6 seconds added to a chat message whenever the platform is slow |
| Payloads | Only coordination data crosses, in both directions | A start input passed through the platform needs a hydrator, a validator and payload storage there. Reserved as the field `input` |
| Events | Your application routes them with `signal()` and `submitInputs()`, checked against the current wait, never buffered | A platform event bus needs payload storage and a meaning for "sent before the wait", which Neuron does not have |
| A start on a workflow ID held by a failed or dead run | Refused; `recover()` is explicit | Finishing the old run inside `start()` is right for a chat turn and a double execution for `order:123` |
| Who owns retries | The platform, with a fenced wake | Queue retries re-send a fence the first attempt already consumed |
| Versions | An integer per `register()` call, carried at the front of the run ID (`2-…`) | A version hashed from the code, as DBOS does, strands every in-flight run at each deploy. Storing the version with the run is the clean fix and needs a core change, deferred earlier |
| Persistence and lease | Imposed on every workflow the SDK builds | Leaving them to the class lets a plain `Workflow` run on its in-memory default with no lease, unnoticed |
| The definition API | `register(name, closure, version, lease)` | A definition class adds a public type per workflow |
| The root object | `NeuronCloud` with an optional `httpClient`, as Neuron's providers name it | `transport: GuzzleTransport::discover()` protected a snippet that neuron-ai 4.1.5 no longer publishes |

---

## 9. How it works

### 9.1 Building a workflow

Every segment, snapshot and management call builds its workflow the same way:

```php
$workflow = $factory($workflowId, $runId)->for($workflowId)
    ->setPersistence($persistence())
    ->setLeaseTimeout($leaseTimeout)
    ->retainCompletionUntilAcknowledged(false);
```

Neuron takes the lease as an argument of each admission and does not store it with the run, so the SDK sets it every time. The serializer stays the workflow's own, and must be the same for every version of a definition: a local call reads a run through the current version before it knows which version the run is pinned to. `sync()` refuses `InMemoryPersistence` and `FilePersistence` before it publishes anything.

### 9.2 The ledger

Neuron refuses a second copy of a start while the run's records exist. Once the run completes, its records are deleted and Neuron has no memory of it: the same start would run again. The ledger is that memory.

- **Where.** In your store, through the same persistence the workflows use: partition `__neuron_cloud:` followed by the SHA-256 of the workflow ID, one key `starts`, holding JSON `{"<runId>": <use-by time>, ...}`. Neuron refuses workflow IDs that begin with `__`, so no workflow can collide with it.
- **Which starts.** Every start that travels through a queue or over the wire: one the platform sent, and one your code dispatched. A start executed in the calling process (`run()`, `events()`) exists in one copy and is not entered: its report says `claimed: false` although it ran. Nothing in the SDK queues such a start, and its segment can be used once, so no second copy of it can reach a worker.
- **Claim.** Immediately before a start is given to Neuron, the SDK adds its run ID with one atomic conditional write (`initializeIfAbsent`, or `writeIfUnchanged` on the bytes just read). When another process writes at the same moment it reads again; after five lost tries the start is `aborted: unavailable` and nothing was written. The same write drops entries whose use-by time is more than an hour old.
- **The rule.** A run ID is claimed once and the claim is never undone. A copy that finds its run ID in the ledger does nothing and reports `rejected: already_claimed` with the snapshot.
- **Use-by time.** Every start carries one that the runner enforces (`expiresAt`, at most an hour ahead). The upper bound is checked where a start is created, by the endpoint for a platform start (9.4) and by `dispatch()` for a local one, never by the runner. A start that carries none counts as expired. The runner refuses a start whose time has passed, and the claim refuses it again with the clock read that prunes: a use-by time that has passed means `rejected: expired` and nothing is written. An entry therefore only has to outlive its start by its use-by time, however long a copy is held up between the check and the claim, and the ledger stays small.

### 9.3 The start event

1. No input: the workflow's own `getStartEvent()`.
2. A `Message` or a list of them, for an `Agent` only: a copy of `getStartEvent()`, which must be an `AgentStartEvent`, with the messages set and `stream` taken from the argument. The agent's `startEvent()` hook is respected. `stream` is ignored for any other input.
3. An `Event`: used as given. For an `Agent` its class must be that of `getStartEvent()`.
4. For every workflow that is not an `Agent`, a dry build of the graph proves that a node handles the event: `(clone $workflow)->setStartEvent($event)->export()` throws when none does and touches no store. It proves that some node handles the event, not that the graph begins there: an event a later node handles ignites the run at that node, as in Neuron.
5. An `Agent` started with no messages is refused: it would ignite and then fail on an empty conversation.

A broken rule is `invalid_start`, thrown from `start()` or reported as `rejected` by a job, before anything is claimed or ignited: the event cannot be rebuilt or stored with a run, an agent's event is not of the class of its own, carries no message or carries something that is not a `Message`, or no node handles it. A start event is immutable once a run exists, so a bad one must never create a run.

A hook of the workflow that throws while the event or the graph is built (`startEvent()`, `resources()`, `nodes()`, a node or middleware factory) breaks no rule. `start()` throws it as it is, and a job reports `aborted: not_executed` with its class, like a factory that throws, so the start can be sent again: a store that is down for a moment while `startEvent()` loads the input must not cost the start. That holds for a start the platform sent. For one your code dispatches, the event is resolved in the request and travels in the invocation, so a hook that throws there is thrown to the caller and nothing is queued. One case cannot be told apart: a hook that throws a plain `WorkflowException` during the dry build looks like the graph's own refusal and counts as a broken rule.

### 9.4 The endpoint: `handle()`

1. A method other than `POST`, compared whatever its case: `405`, before the signature is looked at.
2. Verify the signature over the raw body (appendix A). No key configured, an empty key, a bad signature or a timestamp out of the window: `401`. Nothing is decoded before this.
3. Decode and check the fields of the verb against their formats. Otherwise `400`. For a start that includes a run ID of the minted form and an `expiresAt` no more than 3600 seconds, plus the signature window, ahead. A use-by time that has already passed is not refused here: the start is queued, and the runner reports it `rejected: expired`. A body longer than 65,536 bytes is `invalid_request` and is not decoded; that check comes after the signature, so an unsigned request of any size is still `401`. A body the endpoint cannot decode as a JSON object is `invalid_request`: no JSON, a list or a scalar, text that is not UTF-8, a string with an unpaired surrogate escape, or values nested 512 levels deep, the body counted as the first. An object whose `protocol` is not 1 is `unsupported_protocol`, whatever else it holds. Of two fields with the same name the last one counts. The `invocationId` of a start or a wake that is not the `webhook-id` header is malformed, and an optional field is left out, never sent as null. An `Invocation` is built field by field with `origin` set to `platform`; `payload`, `signal`, `event` and anything else that exists only in your queue are never read from the wire.
4. Look the definition up, and for `start` and `wake` the version in the run ID: `404`. An `inspect` and an `abandon` need only the definition: they read the store through the version the run ID pins when this process has it, otherwise through the current one, whose `abandon()` is then the one called, and are never answered `unknown_version`.
5. `start`, `wake`: hand the invocation to the dispatcher and answer `202`. If the dispatcher throws: `503` with `Retry-After`. No workflow is built and the store is not touched. With no dispatcher set (section 4) the invocation runs in place instead: the segment executes inside `handle()` before the `202`, and what `invoke()` throws there is answered `503 dispatch_failed`. That is for scripts and tests, never for an endpoint the platform calls.
6. `inspect`, `abandon`, `ping`: answer in the request (appendix A). Anything thrown while the workflow is built or the store is read is answered like a store that cannot be read, whatever threw it: the store, the persistence factory, the application's factory or a hook of the workflow. An `inspect` then answers `snapshotRead` false. An `abandon` answers `503 unavailable` when it happens while the run ID is claimed in the ledger, the workflow is built or the run is discarded, also after the ledger entry was written: the abandon sent again then finds the entry (appendix A).
7. Any other throwable: `500`. Nothing leaves `handle()`, so the application's exception renderers never answer the platform.

`handle()` works before `setPersistence()`: a ping, every refusal and, with a dispatcher set, a start and a wake need no store. With no persistence set, an `inspect` is answered `snapshotRead` false and an `abandon` `503 unavailable`, as when the store cannot be read.

### 9.5 The runner: `invoke()` in a queue job

For a **start**:

1. The definition or the version is missing in this process: `aborted` (`unknown_definition`, `unknown_version`). A worker still running old code during a deploy is the usual cause; the invocation can be sent again.
2. The use-by time has passed: `rejected: expired`.
3. Build the workflow (9.1) and the start event (9.3). A broken rule of 9.3: `rejected: invalid_start`. A factory or a hook of the workflow that throws: `aborted: not_executed`.
4. Announce (9.8).
5. Claim the run ID (9.2). The use-by time has passed since step 2: `rejected: expired`. Already there: `rejected: already_claimed`. The store fails, or five tries are lost: `aborted: unavailable`.
6. `run(ExecutionRequest::start($event, $runId, recoverFailed: false))` and classify the result. The SDK subscribes to the events of the workflow it built and keeps the execution of the first: a segment opened if one fired, and that execution gives the attempt. A listener of your own that throws on every event of the workflow still hides the segment: the call is then reported `aborted: not_executed` although a node ran.

| Result | Outcome |
|---|---|
| A state | `completed` or `suspended` |
| `WorkflowRefusedException` | `refused`, with its reason. A `conflict` is tried once more in place when no segment opened. A refusal other than `stale_attempt` that surfaces after a segment opened escaped from a node: it is `failed`, with its class |
| Another throwable, after a segment opened | `failed`, with the exception class |
| Another throwable, no segment opened | `aborted: not_executed`. The claim stays, so this start is not sent again. When the store committed Neuron's first write and lost the answer, the snapshot shows the run, and the platform finishes it with a wake once its lease has passed |

7. Read a fresh snapshot through a newly built workflow, in its own `try`, and post the report (9.8). When the pinned version is missing in this process the snapshot is read through the current version, because all versions share one store. When the definition is missing no snapshot can be read, and the report says `snapshotRead` false.

`recoverFailed` is always false: a start only ever creates a run, so a repeated start can never become a retry.

For a **wake**, an **answer** and a **signal**: steps 1, 3 (without the event), 4, 6 and 7, with the fence taken from the invocation and never read again at job time:

| Verb | Neuron call |
|---|---|
| `wake` | `ExecutionRequest::resume(null, $runId, $attempt)` |
| `answer` | `ExecutionRequest::resume($payload, $runId, $attempt)` |
| `signal` | `ExecutionRequest::signal($signal, $payload, $runId, $attempt)` |

A failure while one of these requests is built is `aborted: not_executed` with its class, never `rejected`: only a start can be rejected.

A wake, an answer or a signal that carries no attempt is never executed, because Neuron would read it as "no fence": `invoke()` throws `invalid_request` before anything is posted.

Before a wake executes, as the first part of step 6, the runner reads the snapshot and refuses with `executing` when the run with that run ID and attempt is `running` with no lease. That can only be a run started outside the SDK, and a wake would take it over while its process is alive.

`invoke()` returns the `Report` (for an `announce`, one with the outcome `observed`). It throws `CloudException` when the message was not dealt with, and `$e->reason` tells the worker what to do. For an abort, the exception names the definition, the workflow ID and the run ID, and its previous is the throwable that caused it:

| Reason | What the worker does |
|---|---|
| `report_undelivered` | Give the same message to `invoke()` again later (after 10, 30, 60, 300, then every 900 seconds), without limit |
| The code of an abort (`unknown_definition`, `unknown_version`, `not_executed`, `announce_failed`, `unavailable`), for an invocation your own code issued | The same, while `time() < $invocation->expiresAt`; after that time, keep it aside like a final one. A start that was aborted after its claim is not thrown: it cannot run again |
| `unauthorized`, `invalid_request`, `unsupported_protocol` | The platform refused the message for good, or the message cannot be executed as it is (a wake, an answer or a signal without an attempt): keep it aside for a person |

`not_configured` is thrown before the message is looked at, when `setPersistence()` was never called: nothing was done and nothing was reported, and the same message can be given to `invoke()` again once the bootstrap is fixed.

The bridges do this. A worker you write yourself must.

### 9.6 A segment run inside a web request

`start()->run()`, `start()->events()`, `submitInputs()->run()` and their like execute in the calling process and, with a dispatcher set, make no call to the platform:

1. Check the event and mint the run ID (`<version>-<uuid>`): `start()` does both. `run()` then applies the `configure()` closure and hands an announcement to the dispatcher. A closure that throws is thrown as it is and nothing is announced. If the dispatcher throws, the call fails with a `CloudException` (`dispatch_failed`) before anything ignites.
2. Execute. A `conflict` on a start is tried once more in place, as in 9.5: Neuron retries it by itself only for a start that reserves no run ID, and the SDK always reserves one. The state is returned, and any other refusal or the node's exception is thrown, as without the SDK.
3. Hand an observe that carries the outcome to the dispatcher. A worker reads the store and reports. If the dispatcher throws here, the state is still returned, or the exception of step 2 still thrown.

With `events()`, the event check and the announcement happen before the generator is returned, and admission happens on the first iteration, as in Neuron. When the consumer stops early the SDK first destroys Neuron's generator, which commits `failed`, and then hands the observe over. When a browser aborts hard, PHP runs none of this: the announcement is what lets the platform find that run when its lease expires.

`start()->dispatch()` reads the snapshot once, throws `RunInFlightException` when a run holds the workflow ID, and otherwise hands the start to the dispatcher with a use-by time one hour ahead. The job claims it.

### 9.7 Answers, signals and `recover()`

`submitInputs()` and `signal()` check everything in the request that received the answer:

1. Read the snapshot, through the current version: the run ID, and with it the version, is not known before. No run, or one that is not suspended on a wait: `InputTranslationException`. This is stricter than Neuron's own `submitInputs()`, which checks only that a run exists and that a wait is attached. When a run is `running` or `failed` with its answered wait still attached, Neuron stages the answer and decides at `run()`: `executing` while the run is `running`; on a `failed` run the same answer is accepted and retries the run, and a different one is `not_awaited`.
2. Read the version from the run ID; that version is built once step 3 has accepted the payload. A run ID the SDK did not mint gets the lowest registered version. A version this process lacks is a `CloudException` (`unknown_version`), thrown before the translator is asked, also when the segment would only be dispatched to a worker that has it.
3. Translate with the given translator, against the current wait. For a signal, the wait must be a `WaitForEventRequest` with that name, otherwise `WorkflowRefusedException` (`not_awaited`). The wait's own rule is then applied, as Neuron applies it when it admits the answer: an answer or a signal payload the wait rejects, such as any answer for a run that sleeps on a timer, is `WorkflowRefusedException` (`not_awaited`) with the wait's own message, and a payload that is not JSON-compatible is Neuron's plain `WorkflowException`, a programming error the examples of section 5 do not catch. A payload Neuron would refuse is therefore never queued. Nothing has been written.
4. Capture the snapshot's run ID and attempt in the invocation. They are never read again, so a second delivery of the same answer is refused as `stale_attempt` (`stale_run` once the run has completed) and cannot reach a later wait. A dispatched answer or signal also carries an `expiresAt` 24 hours ahead; the runner never checks it, and the bridges use it only to stop queueing an aborted one again.
5. `run()`, `events()` or `dispatch()`.

`recover()` reads the snapshot and, when the run is `failed` or `running` with an expired lease, executes a wake fenced with the attempt just read, in this process. It returns the state, or null when there was nothing to do or when someone else got there first (`stale_run`, `stale_attempt`, `executing`), also when its own segment had opened and lost the run to another process while its node was executing. A node that fails again throws, as in Neuron. `recover()` leaves a suspended run alone even when its timer is due, which is the platform's wake, and a run that is `running` with no lease, which was started outside the SDK. It replays the answer a failed or dead run had already accepted. The SDK never calls `recover()` by itself.

### 9.8 Announcements, reports, observes

- **Announcement.** "A segment of run R is starting; its lease is L seconds." A notice, not a question: nothing in the SDK depends on the answer. A job posts it directly (one try, 3 seconds); if that fails, with a final answer or not, it hands it to the dispatcher and continues. If the dispatcher fails too, a segment your code issued is `aborted: announce_failed` before it ignites, and a segment the platform issued continues, because the platform already knows it. A web request only hands it to the dispatcher.
- **Report.** After a segment: the outcome, and a snapshot read from the store just before sending. A job posts it once; on any answer but `200`, a final one included, it hands the dispatcher an observe that carries the outcome, so the later report still says `completed` or `failed`. If the dispatcher throws there, the job still ends normally and `invoke()` returns the report: the platform learns the state when it asks (appendix B, rule 10). When the snapshot could not be read, the report is still posted, with `snapshotRead` false, and no observe follows a report the platform recorded: the platform asks (appendix B, rule 6).
- **Observe.** A worker reads a fresh snapshot and, for a start, the ledger, and posts one report: with the outcome the observe carries, or with `observed` when it carries none (a job that died). When the store cannot be read, the report is still posted, with the outcome the observe carries and `snapshotRead` false, and the platform asks. Undelivered, `invoke()` throws `report_undelivered` and the queue retries it with a back-off and without a time limit: from `invoke()` that reason only ever means the platform did not record the message. A final `4xx` ends it as a failed job that can be replayed, never as a completed one. The bridges keep announcements and observes on their own queue, so that a platform outage cannot delay real segments.

## 10. The Laravel and Symfony bridges

Both bridges were written as real code for the first design and run against a stub of its core, on Laravel 12 with the database queue and on Symfony 6.4 and 8.1 with Messenger on Doctrine. The rows below are that code with what the exercise corrected and what this revision changed (announcements on the observe queue, the unlimited retry of observes, the re-queue of an aborted local invocation, `InvocationSettled` fired by the observe of a job that died). The changed rows have not been run.

### Laravel

| Piece | Design |
|---|---|
| `NeuronCloudServiceProvider` | Auto-discovered. `NeuronCloud` is a lazy singleton built from `config/neuron-cloud.php`, with the application's `PersistenceInterface` binding resolved per build (a `DatabasePersistence` keeps its PDO). An unset API key throws a `CloudException` (`not_configured`) that names the env variable. An optional `neuron-cloud.http` binding supplies the HTTP client, which is how a test puts `FakePlatform` in |
| Config keys | The three credentials, `definitions` (`'name' => Class::class`, or `'name' => [1 => Old::class, 2 => New::class]`: class names only, so `config:cache` works), `lease_timeout` (600), `connection`, `queue`, `observe_queue`, `timeout` (300). A registration that needs a closure goes in a provider and is removed from the config |
| Route and `EndpointController` | `POST /neuron-cloud`, loaded with `loadRoutesFrom()`, outside every middleware group. Three lines: `$cloud->handle($request->getMethod(), $request->headers->all(), $request->getContent())` into a `Response` |
| `Jobs\RunInvocation` | `ShouldQueue`, `$tries = 1`, `$timeout` from config, pushed through the bus. It holds the invocation as one string encrypted with the application key, so that neither the queue nor a job recorder such as Telescope stores an approval in clear. `handle()` calls `invoke()` and fires `InvocationSettled` inside a `try`. On a `CloudException` that 9.5 says to try again, it dispatches a fresh, delayed copy. `failed()` only dispatches an observe, so a killed or timed-out job is reported as what the store shows |
| `Jobs\ObserveRun` | Carries announcements and observes, on `observe_queue`. `$tries = 0`; when `invoke()` throws `report_undelivered` it releases itself with the delays 10, 30, 60, 300, 900 s; a final refusal fails it. An observe that `failed()` dispatched fires `InvocationSettled` with its `observed` report, so a listener can close the stream of a killed or timed-out job |
| `Events\InvocationSettled` | Carries the `Report` only. Fired once per segment that a job ran, and once more for the observe of a job that died. The application's hook to close a browser stream; a listener ignores a `refused` or `rejected` report whose code is `stale_attempt`, `stale_run` or `already_claimed`, which only marks a harmless second copy |
| `neuron-cloud:sync` | Fails when the queue driver is `sync`, `null`, `deferred` or `background`, when `retry_after` does not exceed `timeout` (a warning where the driver has no such key), when a key is missing, when no definition is registered, or when the URL to publish is not `https`; `sync()` itself refuses the last two. Prints the URL and the leases it publishes, says "nothing changed" when that is so, and reminds to restart the workers first: a worker still running old code cannot build a new version. Prints the versions the answer lists as stranded: they are a warning, not a failure |

No `ShouldBeUnique` and no `WithoutOverlapping`: Neuron already refuses duplicates, and both either drop a redelivery after a kill or consume attempts.

### Symfony

| Piece | Design |
|---|---|
| `NeuronCloudBundle extends AbstractBundle` | Package type `symfony-bundle`. Config `neuron_cloud`: the three credentials, `transport`, `observe_transport`, `bus`, `lease_timeout` (600), `persistence` (a service ID, by default the interface name), `definitions` (name to service ID, or to a version map; declared with `normalizeKeys(false)`, or Symfony rewrites the dash in a name). The container build fails when the persistence service is shared (a shared store keeps a dead PDO in a long-running worker) and when the chosen bus carries `doctrine_transaction` (a whole segment would run inside one uncommitted transaction) |
| Route and `EndpointController` | `config/routes.php`: `neuron_cloud_endpoint`, `POST /neuron-cloud`, stateless. The application imports it and keeps the path out of its firewall |
| `MessengerDispatcher` | Dispatches `Messenger\RunInvocation` with a `TransportNamesStamp`, so an unrouted message never runs inside the platform's request. Throws when that transport is a `SyncTransport` |
| `Messenger\RunInvocationHandler` | Calls `invoke()`, dispatches `Event\InvocationSettled` inside a `try`, and returns normally, so Messenger never retries a segment that reached the handler. A message that 9.5 says to try again is dispatched again with a `DelayStamp`. Any unexpected throwable becomes an `UnrecoverableMessageHandlingException` |
| A listener on `WorkerMessageFailedEvent` | The counterpart of Laravel's `failed()`: when a segment's message is given up before the handler ran, it dispatches an observe, which fires `InvocationSettled` with its `observed` report. It never re-queues an observe. A worker killed mid-segment raises no such event on the Doctrine transport: the message is delivered to the handler again after `redeliver_timeout` and settles as a second copy |
| `neuron-cloud:sync` | A classic `Command`. Publishes the route's absolute URL, prints it, and refuses `localhost` and `http`. It fails when no definition is registered, as `sync()` itself does, and prints the versions the answer lists as stranded |

Symfony has no job timeout: the transport's `redeliver_timeout` bounds a segment and must exceed the longest one. On Redis each worker needs its own consumer name, and the bus needs `doctrine_ping_connection`.

---

## Appendix A. Wire protocol

JSON over HTTPS, `POST` only. Every request body carries `"protocol": 1`, except the sync request and the ping answer, which list `protocols`. Receivers ignore fields they do not know. No message to the endpoint is longer than 65,536 bytes: a longer one is answered `400 invalid_request` and is not decoded (the largest valid message of protocol 1 is under 4 KB). Instants are Unix seconds. An integer is written without a fraction or an exponent: `1.0` and `1e0` are malformed (`400 invalid_request`; for `protocol`, `400 unsupported_protocol`), and in the answer to a sync they make the answer unusable. The full field tables and a JSON Schema per message are the first deliverable of the implementation (appendix D); this appendix fixes the shape.

| Identifier | Format |
|---|---|
| `definition` | `^[a-z0-9][a-z0-9._-]{0,63}$` |
| `workflowId` | Neuron's rule: 1 to 255 characters, no control character, no leading `__`. That is the engine's rule. `DatabasePersistence` and `EloquentPersistence` also refuse a partition name longer than 255 bytes, so on those stores a longer ID (multibyte characters) throws `PersistenceException` at every store operation: every `inspect` of it is answered `snapshotRead` false and every `abandon` `503 unavailable`, after its ledger entry was written |
| `runId` minted by the SDK or the platform | `<version>-<unique>`: `^[1-9][0-9]{0,5}-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$`, such as `2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12` |
| Any other `runId` | Neuron's rule; it belongs to a run started outside the SDK and counts as the lowest version |
| `invocationId` | `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`; for a platform message it equals the `webhook-id` header and is the same on every re-send |

The version a run is pinned to is the number at the front of its run ID. No message outside the sync carries a version field. `leaseSeconds` is the `leaseTimeout` given to `register()`.

### Authentication

| Direction | Rule |
|---|---|
| Platform to application | Standard Webhooks: headers `webhook-id`, `webhook-timestamp`, `webhook-signature` (`v1,<base64>`; several allowed, separated by spaces in one header value: repeated `webhook-signature` lines that a web server folds with a comma are not read as several). Signed text: `{id}.{timestamp}.{raw body}`, HMAC-SHA256, compared with `hash_equals`, timestamp within 300 seconds either way. The timestamp is read as a number, so one written with leading zeros is the same instant. A request with no `webhook-id` header verifies only when it was signed over an empty ID. The `webhook-id` of an `inspect`, an `abandon` or a `ping` is covered by the signature and not otherwise checked; that of a `start` or a `wake` is its `invocationId` and must have that format. The key is `whsec_` plus base64 and the SDK signs with the decoded bytes. A key that is missing, empty or does not decode is no key: every `POST` is answered `401` |
| Application to platform | `Authorization: Bearer <apiKey>`, `User-Agent: neuron-cloud-php/<version>`, a 3 second timeout (15 for sync). A `401` or `403` answer is final (`unauthorized`) |
| Both | HTTPS. `sync()` refuses, before anything is sent, an endpoint that does not begin with `https://`; local development needs an `https` tunnel, because the platform pings the endpoint before it accepts a sync. The platform URL is used as given: anything but `https`, outside local development, sends the API key in clear. The SDK's default client follows no redirect; a client you supply keeps its own policy |
| Rotation | The platform signs with every active signing key and accepts the old and the new API key during a rotation. The SDK holds one of each |

| Key | What its holder can do |
|---|---|
| Signing key | Inspect any workflow ID; wake a run, which forces a retry of a failed or dead one, or fires a due timer, on the nodes of whichever registered definition the message names; abandon a run, and an abandon that names another definition skips the guard of an Agent; start any registered definition whose default start event a node handles. The SDK cannot tell which definition started a run (appendix B, rule 3) |
| API key | Publish a sync (definitions and endpoint), send announcements and reports |

### Application to platform

```json
POST {platformUrl}/v1/sync
{"protocols": [1], "sdk": "neuron-cloud-php/1.0.0", "endpoint": "https://shop.example.com/neuron-cloud",
 "definitions": [{"definition": "order-fulfilment", "version": 2, "leaseSeconds": 600},
                 {"definition": "order-fulfilment", "version": 1, "leaseSeconds": 600}]}

200 {"protocol": 1, "modified": true,
     "versions": [{"definition": "order-fulfilment", "version": 2, "openRuns": 14},
                  {"definition": "order-fulfilment", "version": 1, "openRuns": 0}],
     "stranded": [{"definition": "trial", "version": 3, "openRuns": 2}]}
```

The list is complete and replaces the previous one. It names the definitions in the order they were first registered, and under each its versions in the order they were first registered. `sync()` refuses three things itself, with `invalid_request` and before anything is sent: a process that registered no definition, because an empty list would remove every version the platform holds and strand those with open runs, and a bootstrap that failed to register is the likeliest cause; an endpoint that does not begin with `https://`; and a registered lease under 60 seconds. Before applying the list the platform sends a signed `ping` to the endpoint and answers `422 endpoint_unverified` when that fails, so a wrong URL or key fails the deploy. An older version with no open run is retirable; the highest registered one never is. A stranded version left the code while runs remain; the sync is accepted anyway.

A `401` or `403` (`unauthorized`) and a `4xx` that carries `invalid_request`, `unsupported_protocol` or `endpoint_unverified` are final. A `200` is the answer to a sync only when its `modified` is a boolean and its `versions` and `stranded` are lists whose entries each hold a definition name, a version of 1 or more and a number of open runs of 0 or more; a list that is left out or null counts as empty, and `protocol` is not looked at. Any other answer, a `200` of another shape included, is thrown as `report_undelivered`: the platform did not confirm the sync, which may have been applied, and it can be run again because the list replaces the previous one. A version in the answer whose definition this process did not register is not an error: nothing of it is retirable here.

```json
POST {platformUrl}/v1/announcements
{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123",
 "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1, "leaseSeconds": 600, "at": 1791453603}

POST {platformUrl}/v1/reports
{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 2,
 "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791457200}},
 "claimed": null, "snapshotRead": true, "at": 1791453604}
```

Both are answered `200 {"recorded": true}`. A `401` or `403` (`unauthorized`) and a `4xx` that carries the code `invalid_request` or `unsupported_protocol` are final. Any other answer counts as undelivered. A refusal carries `{"code": "..."}` at the top level of its body.

| Report field | Meaning |
|---|---|
| `verb` | `start`, `wake`, `answer` or `signal`: the kind of segment |
| `origin` | `platform` when the invocation arrived through the endpoint, `application` when your code created it |
| `outcome` | `completed`, `suspended`: Neuron returned the run's state, after a segment when `executedAttempt` is set, without one when it is null (a wake before its time returns the same wait and attempt). `failed`: a segment ran and threw. `refused`: Neuron said no, or the guard before a wake did; with an `executedAttempt`, a segment that lost its run while it was executing (`stale_attempt`). `rejected`: the SDK said no, for good. `aborted`: nothing ran. `observed`: nothing is known about the call; only the store was read |
| `code` | A `RefusalReason` value for `refused`; an SDK code for `rejected` and `aborted` |
| `errorClass` | The exception class of a `failed` call, and of an `aborted` one that an exception caused (null for `unknown_definition` and `unknown_version`). For an anonymous class, its nearest named parent: PHP's own name for it holds the path and line of the file that declares it. Never the message, which routinely quotes inputs |
| `executedAttempt` | The attempt this call executed under; null when it opened no segment |
| `run` | The run holding the workflow ID when the report was sent, or null: `runId`, `status` (`running`, `suspended`, `failed`, and `completed` only for a completion retained by code outside the SDK), `attempt`, `leaseExpiresAt`, and `wait` while suspended. A run that completes under the SDK is gone, so its report carries `run: null` |
| `wait` | `interruptId`, `type`, `eventName`, `wakeAt`. Built from typed getters, never from `jsonSerialize()`, which for an approval includes the message and the tool inputs. An event name that is not valid UTF-8 is shown with its invalid bytes replaced by U+FFFD, so two names can be shown alike; the platform never sends a name back. A timer or a deadline set before 1970 is shown as the instant 0 |
| `claimed` | Whether a start's run ID is in the ledger. In the report of the start's own job: `true` when this call wrote the entry or found it there, `null` when the call ended before the claim or the claim failed, because the ledger was not read. In a report posted by an observe and in an `inspect` answer that names a `runId`: read from the ledger, `true` or `false`, and `null` when the store could not be read (`snapshotRead` false). In an `abandon` answer: `true`, because the abandon writes the entry when it is not there. Null for every other verb and for an `inspect` without a `runId`. A start run in the calling process is never entered in the ledger, so its report says `false` although it ran: `claimed` tells "never ran" only for a start that travelled through a queue |
| `snapshotRead` | False when the store could not be read; `run` then means nothing, and so does `claimed`, except in an `abandon` answer, where it is always `true` |

### Platform to application: `POST {endpoint}`

```json
{"protocol": 1, "verb": "start", "invocationId": "inv_01JB2M", "definition": "nightly-reconciliation",
 "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W", "expiresAt": 1791368100}

{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB7K4T9QW2", "definition": "order-fulfilment",
 "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1}

{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e"}
{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e", "attempt": 2}
{"protocol": 1, "verb": "ping"}
```

| Verb | Fence | Runs in | Success |
|---|---|---|---|
| `start` | The run ID, minted by the platform with the current version, and `expiresAt`, 600 to 3600 seconds ahead; the endpoint checks only the upper bound (9.4) | The queue | `202` |
| `wake` | Run ID and attempt | The queue | `202` |
| `inspect` | None; `runId` is optional | The request | `200 {"run": {...} or null, "claimed": true, false or null, "snapshotRead": true, "at": ...}`; with `snapshotRead: false` when the store cannot be read |
| `abandon` | Run ID, and the attempt when the run exists | The request | The same body |
| `ping` | None | The request | `200 {"protocols": [1]}` |

**Abandon.** The SDK first claims the run ID in the ledger when it is not there, with a use-by time one hour ahead, so that a start still waiting in the queue never runs. When a run holds the workflow ID it then calls the workflow's own `abandon()`, which keeps an Agent's guard in force (answered `409 abandon_refused`), provided the message names the agent's own definition. For an Agent the guard answers before the fence: a conversation that ends on a tool call is `409 abandon_refused` also for a stale attempt and for the run ID of another run. An abandon that carries no attempt discards the run with that run ID at whatever attempt it is. A run that is `running` with no lease is discarded like any other, and the process that executes it loses its next write. When no run holds the workflow ID it answers `200` with `run` null and `claimed` true if this call wrote the entry: the start will never run. A `200` after a run was discarded has the same body: a `200` says that nothing will run under that run ID any more, not whether anything ran. If the entry was already there it answers `409 stale_run` with `run` null, and the SDK cannot tell why: a job has claimed the start and its run is about to appear, the run has already ended, the start stopped between its claim and its first write, or an earlier copy of this abandon wrote the entry or discarded the run. The platform then inspects: when a run with that run ID is shown it abandons again with the attempt shown; when none is shown it closes the run as "ended, outcome unknown" (appendix B, rule 12). When another run holds the workflow ID the answer is `409 stale_run` with that run attached, whether this call wrote the entry or found it; when that run leaves while the abandon is dealt with and this call wrote the entry, the answer is the `200` of "no run". The code of a `409` is Neuron's refusal and `run` is read afterwards, so a run that ended in between gives `executing` or `conflict` with `run` null: the platform plans from `run`, never from the code. When the ledger cannot be read or written, the workflow cannot be built or the store fails while the run is discarded, the answer is `503 unavailable`. A run whose records cannot be read in full is still abandoned on its control record, as Neuron's own `abandon()` does; when the run cannot be read for the answer, the answer carries `snapshotRead` false.

| Status | `code` | Meaning | The platform |
|---|---|---|---|
| `202` | | Verified, valid, queued; the body is `{}`. Nothing has run and no lease exists | Assumes nothing yet |
| `200` | | A question was answered | Uses `run` |
| `400`, `405` | `invalid_request`, `unsupported_protocol` | Malformed, longer than 65,536 bytes, an unknown verb, or not a `POST` | Never re-sends; alerts |
| `401` | `invalid_signature` | One status and one code for every signature problem: the answer does not say which check failed | Stops deliveries, keeps timers, alerts; resumes after a successful `ping` |
| `404` | `unknown_definition`, `unknown_version` | This deployment lacks that code | Re-sends with a back-off while the version is in the latest manifest |
| `409` | A `RefusalReason` value, or `abandon_refused` | The run's state refused the abandon, or the run ID was already in the ledger and no run holds the workflow ID (`stale_run` with `run` null); `run` is attached | Plans from `run` |
| `503` | `dispatch_failed`, `unavailable` | The queue or the store failed, or the application's factory threw; `Retry-After` in seconds (5 today) | Re-sends the same message |
| `500`, timeout, `3xx` | `internal_error` | Unknown | The same, bounded |

No answer of the endpoint carries `protocol`; every answer is a JSON object sent as `application/json`.

### Codes

| Origin | Codes |
|---|---|
| Neuron (`RefusalReason`) | `run_in_flight`, `stale_run`, `stale_attempt`, `executing`, `not_awaited`, `conflict`. Three more exist in Neuron and cannot occur under the SDK: `no_run` (only a continuation without a run ID gets it, and the SDK always sends one), `completed` (only when an abandon meets a completion retained by code outside the SDK) and `not_completed` (only from `acknowledge()`, which the SDK never calls) |
| Endpoint answers | `invalid_signature`, `invalid_request`, `unsupported_protocol`, `unknown_definition`, `unknown_version`, `dispatch_failed`, `unavailable`, `abandon_refused`, `internal_error` |
| Reports, `rejected` | `expired`, `invalid_start`, `already_claimed` |
| Reports, `aborted` | `not_executed`, `unknown_definition`, `unknown_version`, `announce_failed`, `unavailable` |
| Platform answers | `endpoint_unverified`, `unauthorized`, `invalid_request`, `unsupported_protocol` |
| Thrown locally only | `report_undelivered` (an announcement or an observe was not delivered, or the platform did not confirm a sync), `dispatch_failed` (the dispatcher threw in a request), `unsuitable_persistence` (`sync()` on an in-memory or file store), `not_configured` (a key is missing, or `invoke()`, `workflow()` or `sync()` is called before `setPersistence()`), `segment_used` (a `PendingSegment` was used a second time). `invalid_request` is thrown locally too: by `register()` for a name, a version or a lease that cannot be used, by `workflow()` for a workflow ID outside Neuron's rule, by `invoke()` for a wake, an answer or a signal without an attempt, and by `sync()`, before anything is sent, when no definition is registered, for an endpoint that does not begin with `https://` and for a registered lease under 60 seconds. Thrown locally as well: `invalid_start` by `start()` (9.3), `unknown_definition` by `workflow()`, `unknown_version` by `submitInputs()`, `signal()` and `recover()` (9.7) |

## Appendix B. What the platform must do

A run stays correct whatever the platform does with its clock, its memory and its timers. Five rules about how the platform writes its own orders are the exception, and the SDK cannot check them.

| # | Correctness depends on these |
|---|---|
| 1 | Never use a run ID twice. A start keeps one `expiresAt` for the life of its run ID, never more than 3600 seconds after the start is first sent: the ledger entry an abandon writes outlives the start only within that bound |
| 2 | A wake carries a run ID and an attempt that the application itself reported or returned from `inspect`. An abandon carries the same or, for a start the platform sent and has heard nothing of, that start's run ID with no attempt. Never guess or increment an attempt |
| 3 | Send a run its own definition: the one of the start the platform issued, or of the first announcement or report whose `runId` is that run. Never the definition of a message about another run |
| 4 | One start per firing of a trigger |
| 5 | Abandon only when an operator asks |

Everything else is about getting a run woken at the right time and knowing when it ended.

6. **Plan from a snapshot that was read.** A snapshot that names a run replaces what the platform holds for that run when its attempt is higher, or equal with a later lease deadline or a status that left `running`. A report whose `snapshotRead` is false carries no snapshot: ignore its `run` and `claimed`, keep its outcome for rule 7, and `inspect` after 30 seconds, doubling to 600, until an answer is read.
7. **Know when a run has ended.**
   - A report closes the run named by its `runId` when its outcome is `completed`.
   - A snapshot that was read and shows no run, or another run, closes a run only if an earlier snapshot named it: the run ended and the outcome is unknown.
   - A run the application started may be known from its announcement alone. When `leaseSeconds` have passed since that announcement and no snapshot has named the run, `inspect`, and close it the same way if the answer shows no run, or another run. An announcement or a snapshot for that run with a later `at` opens it again.
   - An announcement never reopens a run closed as `completed`: a second copy of a start or of a wake posts its announcement before it is refused.
   - `rejected: already_claimed` closes nothing.
   - A `200` answer to an abandon closes the run named in the abandon as abandoned, whether or not a snapshot ever named it. It is never shown as "never ran": the answer is the same when a run was discarded. A `409` answer is read like any other snapshot, and an abandoned start that it leaves open is asked about as in rule 12.
   - A start reported `refused`, `rejected: expired`, `rejected: invalid_start`, or `aborted` with `claimed` true is never sent again. If a snapshot that was read names its run ID, in that report or in an earlier one, or the run is already closed, the run exists or has existed: the report closes nothing by itself, and its snapshot is read like any other. Otherwise the start will never run under that run ID: mark it spent and show the code to an operator. `rejected: expired` is the exception, because it says only that this copy did not run: ask as in rule 12 before closing it.
8. **One timer per wait.** Send a wake at `wakeAt`. The same wait and attempt back means "too early by the application's clock": try again after 1 to 60 seconds.
9. **Retries are wakes.** After a `failed` snapshot, wait by policy and send a wake with the attempt just reported. Count one failure per run ID and attempt. When the policy gives up, leave the run failed and alert.
10. **Silence means ask.** After an announcement, no report within `leaseSeconds`: `inspect`. After a `202` for a wake, no announcement within 900 seconds: `inspect`, and send the wake again when the attempt is unchanged. A wake may always be sent again; its fence makes copies harmless.
11. **A dead run is retried like a failed one.** When a snapshot shows `running` with a lease that has passed, send a wake with the attempt shown, under the same policy and count. Never send a wake to a running run before its lease deadline, and never to one shown `running` with no lease: it was started outside the SDK. Read it now and then, and alert.
12. **A start that stays open is asked about, not replaced.** When a start is still open at its `expiresAt` plus 300 seconds, because it stayed silent or because its reports closed nothing, `inspect` with the run ID. If the run holds the workflow ID, plan from the snapshot. If it does not and `claimed` is false, no copy claimed it and none can any more: close it as lost. If it does not and `claimed` is true, close it as "ended, outcome unknown": it completed without a report, was abandoned, or stopped before its first write, and the answer cannot tell which. Both are shown to an operator, the second with the warning that the workflow may have run. An `inspect` made later than `expiresAt` plus 3600 seconds can answer `claimed: false` for a start that ran, because its entry may have been dropped: treat that as outcome unknown too. The platform never issues a replacement on its own.
13. **Re-send only what can be re-sent**, and only an order the platform itself sent, never a segment the application issued: after a `503`; after a `500` or a `3xx`, a bounded number of times; a `404` for a version in the manifest, a timeout, an `aborted` report whose `claimed` is not true, or an `observed` report or `inspect` answer for a start the platform issued that shows `claimed: false` and no run under its run ID. In an `aborted` report a `null` is re-sent like a `false`: a copy of a start that was in fact claimed is `rejected: already_claimed` and changes nothing. An `observed` report or an `inspect` answer whose `snapshotRead` is false shows nothing and falls under rule 6. With a back-off; a start never after its `expiresAt`.
14. **Read a wait that has no timer now and then.** A run suspended without `wakeAt` is inspected daily at first, then less often, at least every 7 days. This is how the platform notices a run that was abandoned, reset or answered without a report.
15. **Keep only coordination data** from what the application sends, and answer `recorded: true` only after a durable write. Accept announcements and reports for runs it did not start: the application wins.
16. **Sync.** Ping the endpoint first. Accept only `https` endpoints that are publicly routable, and never follow redirects. Ask an owner to confirm before moving an environment's endpoint to another host. Never invoke a stranded version.
17. **Do not flood.** Limit the requests in flight to one endpoint, honour `Retry-After`, and spread out timers that come due together after an outage.
18. **Alert** on an announcement for a platform start it never issued (the signing key may have leaked), and on a report whose `at` is more than 300 seconds from its own clock.

### Durations

| Duration | Value | Must exceed |
|---|---|---|
| Outbound request timeout | 3 s (sync 15 s) | |
| Endpoint answer | 10 s allowed | |
| Signature window | 300 s either way | The clock difference between the platform and any application host |
| Use-by time of a start | 600 to 3600 s for the platform, of which the endpoint checks only the upper bound; 3600 s for a dispatched local start | The queue delay you tolerate |
| `expiresAt` of a dispatched answer or signal | 24 h, used only to stop queueing an aborted one again | |
| A ledger entry | Kept until its use-by time plus 1 h | |
| Lease (`leaseSeconds`) | 600 s by default; 60 s at least, checked by `sync()` before anything is sent. `register()` refuses only a lease under 1 second, so that tests can use short ones | The slowest node, plus the clock difference between your own hosts |
| Job timeout | Laravel `timeout`, 300 s by default. Symfony has none: `redeliver_timeout` bounds a segment | The longest segment |
| Queue `retry_after`, `redeliver_timeout` | Your queue config | The job timeout |
| Queue retry of an announcement or an observe | 10, 30, 60, 300, then every 900 s, no limit | |
| Segment silence | `leaseSeconds` after an announcement | |
| Wake silence | 900 s after the `202` | The queue delay you tolerate |
| Start silence | `expiresAt` plus 300 s | The signature window |
| Retry of a timer wake that came too early | 1 to 60 s | |
| Periodic read of a wait without a timer | Daily, then at least every 7 days | |
| Follow-up of a report whose snapshot was not read | `inspect` after 30 s, doubling to 600 s | |
| Report clock alert | `at` more than 300 s from the platform's clock | |

## Appendix C. Testing support

Shipped in `src/Testing`, in neuron-ai's "queue responses, record calls, assert" style.

```php
class FakePlatform implements HttpClientInterface
{
    public readonly string $signingKey;
    public function failNext(int $times = 1, int $status = 503): static;
    public function deliver(NeuronCloud $cloud, array $message, ?int $timestamp = null): HttpResponse;   // signs, calls handle()
    public function wake(NeuronCloud $cloud, string $workflowId): HttpResponse;    // fences from the last report
    public function assertSynced(): void;
    public function assertAnnounced(string $workflowId, ?Verb $verb = null): void;
    public function assertReported(string $workflowId, Outcome $outcome, ?string $code = null): void;
}

class FakeDispatcher implements DispatcherInterface
{
    public function assertDispatched(Verb $verb, ?string $workflowId = null): void;
    // Runs what is queued through invoke(), each message $times times: 2 proves a second delivery harmless.
    // Like a worker, it keeps queued a message invoke() threw for, unless it is final or past its expiresAt.
    public function runAll(NeuronCloud $cloud, int $times = 1): array;   // what each invoke() returned (a Report) or threw (a CloudException)
}
```

A test shares one `InMemoryPersistence` through `setPersistence(fn () => $store)`. A test that calls `sync()` uses SQLite `DatabasePersistence` instead, because `sync()` refuses the in-memory store, and registers leases of 60 seconds or more (the default is 600), because `sync()` refuses a shorter one. Neuron reads `time()` directly, so tests use past deadlines and short real leases, never a fake clock.

The fake answers a sync with no open run on any version and nothing stranded, and does not ping the endpoint: `failNext(1, 422)` plays a ping that failed. `wake()` fences from the last report the fake recorded for the workflow ID and takes the definition from that report. Each call sends a new invocation ID, so two calls are two wakes, not one wake sent again. It fails the test when no report was recorded for the workflow ID or the last one shows no run. A dead run, which posts no report, is woken with `deliver()` from an `inspect` answer.

| The SDK's own tests | What |
|---|---|
| Unit | Signature vectors, including the published Standard Webhooks one, an empty key and a key that does not decode; one test per endpoint status; the wait projection; the start-event rules; the ledger |
| Contract | The redelivery matrix replayed through `invoke()`: a second, identical invocation of each kind (a platform start, a dispatched start, an answer, a signal, and a wake for a due timer, for a failed run and for a dead run), delivered while the first is still executing, after it suspended, after it completed (its report recorded, its report lost, the platform unreachable) and after it failed: 42 cells, and in none may the second copy execute a node. On `InMemoryPersistence` and SQLite `DatabasePersistence` |
| Failures | One test per row of section 7, with a real `SIGKILL` of a separate worker process for rows 7 and 18 |
| Concurrency | Many separate PHP processes delivering the same start at once on SQLite |
| Boundary | A state value, a message and an approval text planted in a workflow never appear in any request body |
| Protocol | A JSON Schema per message in `resources/protocol/1/`, checked against everything the SDK emits |

## Appendix D. Implementation order

| # | Milestone | Ends with |
|---|---|---|
| 0 | Raise the constraint to `^4.1.4` (raised to `^4.1.8` with milestone 2); copy neuron-ai's Rector guard for named arguments; write the JSON Schemas and the signature vectors | CI green on the lowest dependencies |
| 1 | Registry, the build step, the ledger, the runner with `start` and `wake`, the report, `FakeDispatcher` and the recording half of `FakePlatform` | The redelivery matrix and the concurrency test pass on SQLite |
| 2 | Local path: `run()`, `dispatch()`, announcements and observes through the dispatcher, `submitInputs()`, `signal()`, `recover()`, and the start input of an agent (rule 2 of 9.3) | The local calls of example (a) run against the fakes |
| 3 | Signature and `handle()`, `inspect`, `abandon`, `ping`, `sync()`, `FakePlatform::deliver()` and `wake()` | Example (a) runs against the fakes; a script plays the platform through a timer, a retry and a killed worker |
| 4 | `events()` with its wrapper | A streamed chat turn with an approval |
| 5 | A small platform simulator; every row of section 7 | Example (a) end to end over real HTTP |
| 6 | Laravel bridge | Example (b) on a real queue, including `kill -9` |
| 7 | Symfony bridge | Example (c) on Messenger, on Symfony 6.4 and current |
| 8 | A "with Neuron Cloud" guide per framework, with the migration table | Protocol 1 frozen and tagged |

**Status on 2026-10-10.** Milestones 0 to 3 are done; milestone 2 also brought the start input of an agent forward from milestone 4. Next is milestone 4, `events()`.

## Appendix E. How this design was checked

The design went through six rounds run by independent agents working from the code, with a rewrite after the fourth and corrections after the fifth and the sixth. The first four milestones were then built and reviewed the same way.

| Round | What | Result |
|---|---|---|
| Research | Seven readers mapped the engine's contract for an outside driver (by running scripts against it), the agent contract, the Laravel and Symfony guides, protocol prior art and PHP packaging conventions | Corrections to the readiness document; the finding that the guides' queue settings contradict a platform-driven flow |
| Panel | Four complete proposals from different starting points, scored by three judges, merged into one | A first design, built around asking the platform before every start |
| Review | Seven reviewers attacked it. One built the runtime against the real engine and ran it, with a real killed worker and a real aborted connection. A second agent tried to refute the 60 serious findings of the other six | 7 critical and 62 major findings. Of the 60 challenged, 44 were confirmed, 16 partly, none refuted. The announce, run, report cycle held; the rules against a repeated start did not |
| Revision | The ledger design was prototyped on three backends, attacked with about 108,000 interleavings and with real processes racing for one claim on SQLite, MySQL, Postgres and Redis, and compared with the first design | Every case where the first design ran a start twice ran it once. The remaining critical findings came from undoing a claim, releasing retained completions, the platform replacing a start it believed lost, and three gaps closed here: an announcement that can neither be posted nor queued, a wake accepted and never heard of again, and a wake to a run that is running without a lease |
| Final check | This document was written with those three mechanisms removed. One agent then built sections 9.1 to 9.8 as written and ran them on two backends; two more checked the document against itself, against Neuron 4.1.5 and as its reader | No second execution in the redelivery matrix, the crash and kill scripts, or 1,240 processes delivering the same start at once on SQLite. One remote case did run a start twice (a copy held up for more than an hour between its use-by check and its claim); the claim now refuses an expired start, and that fix was run. The other corrections are in the text |
| Check of the rewrite | Three readers went over this document after those corrections: one against the findings of the final check, one for contradictions between sections, one against the Neuron 4.1.5 source and the integration guides, with scripts run against the engine for single facts. A second agent tried to refute each finding | 42 findings, several reported by two readers, none critical: 40 held in whole or in part and are applied, 2 were refuted. No path was found where following the text runs a start twice. The serious ones: question 6 put the re-send of an unclaimed start outside v1 while the rules specified it; rule 7 of appendix B closed a start that, in two cases reproduced on the engine, had a live run; the abandon answer stated a cause it cannot know; and `submitInputs()` was said to behave as Neuron's, while it is stricter |
| Build of milestones 0 and 1 | One agent built the core from this document. Five wrote its test suites independently, from the document and not from the code: the 42-cell redelivery matrix on two backends, real processes racing for one start and being killed, a unit test class per source class, the runner's failure paths, and a store that fails at every one of its operations. Three more reviewed the source: one against this document line by line, one trying to break the guarantees with scripts, one against the house standards. A second agent tried to refute each finding, the fixes were applied, and an independent agent checked that undoing each fix fails a test | 1,200 tests pass, with PHPStan at level 5 and a PHP 8.1 lint. No case was found, in any suite or attack, where a start ran twice or a segment ran under a stale fence. About 40 findings held and are fixed in the code or in this text. The ones that changed behaviour: a hook that throws while a start is prepared is now retried instead of rejected for good; an observe no longer follows a report the platform recorded, and one that cannot read the store reports that and ends, so nothing retries without limit; a wake, an answer or a signal without an attempt is refused before anything runs; the class name of an anonymous exception no longer carries a file path; the payload of an answer or a signal travels serialized. One finding was in Neuron itself (row 4 of section 7): neuron-ai 4.1.8 fixed it and the package now requires that release |
| Build of milestone 2 | The same way: one agent built the local path over the committed core; three wrote its suites from this document (segments run in the calling process, answers, signals and `recover()`, start input and whole flows with real processes); three reviewed and attacked the source; a second agent tried to refute each finding; the fixes were applied and an independent agent checked that undoing each one fails a test | 3,748 tests pass on PHP 8.4 and 8.5. No case was found where a start, an answer or a signal ran twice or reached another wait, and with a dispatcher set no local call made an HTTP request. What changed after the review: an answer or a signal payload the wait itself rejects is refused in the request, where it used to be queued and refused by a worker; a list of messages that holds something else is refused before a run exists, where it used to leave a thread stuck on a failed run; a copy of a prepared start can no longer run a second time; the `configure()` callback can no longer replace the store or the lease; a process that lacks the version of a run says so before it asks its own translator |
| Build of milestone 3 | The same way, with an attacker in place of the breaker: one agent built the endpoint and App Sync; three wrote the suites (every request at the endpoint, `inspect`, `abandon` and `ping`, App Sync and a platform simulator that drives example (a) through real worker processes, one of them killed); a conformance reviewer, a quality reviewer and an attacker working with and without each key went over the source; a second agent tried to refute each finding; the fixes were applied and an independent agent checked that undoing each one fails a test and read every edit of this document against the code | 6,524 tests pass on PHP 8.4 and 8.5. No request without a valid signature did anything: every one was answered the same `401`, with nothing queued, read or built. No case was found where a workflow ran twice. What changed after the review: an abandon can free a run whose records no longer load, where it answered `503` for ever; `sync()` checks the shape of the platform's answer, where a malformed one surfaced as a PHP error after the sync was applied; `sync()` refuses to publish an empty list, an endpoint that is not `https` and a lease under 60 seconds; a signed body over 64 KB is refused without being decoded; an event name or a timer the protocol cannot carry no longer breaks every report of its run. One finding is a limit of the design and is stated in question 12: the signing key acts through whichever registered definition a message names |

Five limits of the checking are worth knowing.

- **Streaming and the bridges are not built.** Every part of section 9 is implemented and tested except `events()` (9.6). The bridges of section 10 are still text, and so is everything that needs a real platform: the endpoint and `sync()` ran against the fake and against scripted HTTP answers, never over real HTTP.
- **The tests ran on PHP 8.4 and 8.5, not on 8.1.** The local 8.1 build lacks the extensions PHPUnit needs, so on 8.1 the code is proven by lint, by PHPStan set to 8.1 and by a small in-memory script. CI runs the full matrix.
- **The bridges ran against the first design.** They were run over a stub of its core, on Laravel 12 with the database queue and on Symfony with the Doctrine transport. The rows this revision changed have not been run, and Redis queues, SQS, Horizon and Octane rest on reading the source.
- **Concurrency was not run on every backend.** The built code ran its multi-process tests on SQLite. The prototype's ledger also ran on MySQL, Postgres and Redis; nothing ran on the Eloquent backend.
- **The platform does not exist.** Appendix B was checked by reading and by small simulators written from it, not against an implementation.
