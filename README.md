# Neuron Cloud SDK

Connect the agents and workflows of a [Neuron AI](https://github.com/neuron-core/neuron-ai) application to Neuron Cloud. Your agent and workflow classes stay ordinary Neuron code: they know nothing about the SDK.

The SDK gives three things. Adopt them in this order, each when you want it:

| Stage | What you get | What reaches Neuron Cloud |
|---|---|---|
| [Tracing](#2-tracing) | Every run of an agent or a workflow as an OpenTelemetry trace: its nodes, inferences, tool calls and retrievals | The names of nodes, tools and models, token counts, timings, the thread ID of an agent, and the message and the stack trace of every exception. Prompts, completions, tool arguments and tool results too, unless you turn content capture off |
| [Evaluations](#3-evaluations) | The results of `vendor/bin/neuron evaluation` on the platform, and evaluators that run on datasets the platform keeps | The input, the output, the scores and the failed assertions of every dataset item. Reading a dataset sends nothing but its slug |
| [Orchestration](#4-orchestration), for advanced use | Runs that survive a crash, a deploy or a wait of days: Neuron Cloud fires the timers your workflows wait on, retries a run after a failure or a crashed worker, and starts runs on a schedule | Coordination data only: definition names, workflow and run IDs, statuses, wake-up times and the class of an exception. Workflow state, inputs, prompts, answers and results stay in your application; with tracing on, the trace of each run carries what tracing sends |

All three use one service, built with your API key ([section 1](#1-the-service)). Nothing reaches the platform until a stage sends it.

## Requirements

- PHP 8.1 or later
- `neuron-core/neuron-ai` 4.1.9 or later
- A Neuron Cloud API key

Tracing and evaluations need nothing more. Orchestration adds a signing key, a shared store, a durable queue and a public HTTPS URL: [section 4](#4-orchestration) lists them.

## Start With One Prompt

```
You are going to set up Neuron AI, a PHP framework for building agentic applications,
in this project, and you must follow three steps in order.

- First: install the package: check composer.json, if neuron-core/neuron-ai is
already on the version 4.x. If it is run `composer update neuron-core/neuron-ai` to get the latest
versionm if it's on 3.x or lower stop the installation process and suggest to update Neuron before moving forward;

- Second: install the skills for the coding agent that ship
with the package by running `npx --yes skills add ./vendor/neuron-core/cloud-sdk/skills -y` from
the project root. Add this command to `post-update-cmd` in the composer.json file, so they
stay updated whenever the package is updated through Composer;

- Third: work out what kind of app this is. Look at composer.json
and the project structure to see whether it is an application based on Laravel, Symfony, or legacy PHP,
then activate the matching skill: neuron-cloud-php, neuron-cloud-laravel, or neuron-cloud-symfony.
If Neuron is already part of the app go through the skill's foundations checklist
item by item. Report what differs from the recommended setup.
```

## Installation

```bash
composer require neuron-core/cloud-sdk
```

Using Laravel? Install `neuron-core/neuron-cloud-laravel` instead: it binds the service from a config file, traces every agent and workflow the container builds once `NEURON_CLOUD_TRACING=true`, binds the evaluation output, and for orchestration runs the queue jobs, registers the endpoint and adds the deploy command.

Coding agents get two skills with the package, `neuron-cloud-php` and `neuron-cloud-laravel`, which walk the same stages:

```bash
npx skills add ./vendor/neuron-core/cloud-sdk/skills -y
```

## 1. The service

Every stage uses one `NeuronCloud`, built with the API key of your Neuron Cloud project. Bind it once in your application's container, as a shared service, and take it from there wherever a later stage needs it. With PHP-DI:

```php
// config/container.php: the definitions of the application's PHP-DI container
use NeuronCore\Cloud\NeuronCloud;

return [
    // One service for the whole application, built the first time something asks for it
    NeuronCloud::class => fn (): NeuronCloud => new NeuronCloud(
        getenv('NEURON_CLOUD_API_KEY') ?: throw new RuntimeException('Set NEURON_CLOUD_API_KEY: the API key of the Neuron Cloud project.'),
    ),
];
```

Without a container, one bootstrap file builds it, and an entry script that uses it requires it once:

```php
// bootstrap.php
use NeuronCore\Cloud\NeuronCloud;

require_once __DIR__ . '/vendor/autoload.php';

return new NeuronCloud(
    getenv('NEURON_CLOUD_API_KEY') ?: throw new RuntimeException('Set NEURON_CLOUD_API_KEY: the API key of the Neuron Cloud project.'),
);
```

- **The API key is all it needs** for tracing and evaluations. Building the service sends nothing.
- **One service per process, shared.** Every later stage takes it from the container or from `bootstrap.php`. A `NeuronCloud` built inside a class or per request does not have the settings of the shared one, such as `enableTracing()`.
- **The `throw` names the missing variable.** `getenv()` gives the constructor `false` for an unset variable: a `TypeError` in a file that declares `strict_types`, and without it a service with an empty key, whose every request the platform refuses.
- **Read the key as your application reads its other settings.** The snippets use `getenv()`, which sees the environment of the process.

## 2. Tracing

Tracing is two things: `enableTracing()` where the service is built, and a listener from `$cloud->tracer()` subscribed to each agent or workflow your application runs with `chat()`, `stream()`, `run()` or `events()`, through one small class of your application. It is best effort: with tracing off the service is never asked for, and with tracing on and a service that cannot be built, the agent runs untraced and the cause is logged. In the container of section 1:

```php
// config/container.php
use App\Agents\SupportAgent;
use App\Tracing;
use NeuronCore\Cloud\NeuronCloud;
use Psr\Container\ContainerInterface;

return [
    NeuronCloud::class => function (): NeuronCloud {
        $cloud = new NeuronCloud(
            getenv('NEURON_CLOUD_API_KEY') ?: throw new RuntimeException('Set NEURON_CLOUD_API_KEY: the API key of the Neuron Cloud project.'),
        );

        if (getenv('NEURON_CLOUD_TRACING') === 'true') {
            // false keeps prompts, completions, tool arguments and tool results in your application
            $cloud->enableTracing(captureContent: false);
        }

        return $cloud;
    },

    // One entry like this for every agent and workflow: the closure is called only where tracing is on
    SupportAgent::class => fn (ContainerInterface $container): SupportAgent => Tracing::subscribe(
        SupportAgent::make(),
        fn (): NeuronCloud => $container->get(NeuronCloud::class),
    ),
];
```

`App\Tracing` is your application's own class, written once, and every agent and workflow goes through it:

```php
// src/Tracing.php: where every agent and workflow your application builds gets its tracer
namespace App;

use Closure;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronCore\Cloud\NeuronCloud;
use Throwable;

final class Tracing
{
    protected static bool $failureLogged = false;

    /**
     * @template T of WorkflowInterface
     * @param T $workflow an agent or a workflow
     * @param Closure(): NeuronCloud $cloud called only when tracing is on
     * @return T the same object, with its tracer when the service could be built
     */
    public static function subscribe(WorkflowInterface $workflow, Closure $cloud): WorkflowInterface
    {
        if (getenv('NEURON_CLOUD_TRACING') !== 'true') {
            return $workflow;   // tracing off: the service is never asked for, and no key is needed
        }

        try {
            $workflow->subscribe(ObservabilityEvent::class, $cloud()->tracer());
        } catch (Throwable $e) {
            // Best effort: the agent runs untraced, and the first failure of the process is logged.
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                while ($e->getPrevious() !== null) {
                    $e = $e->getPrevious();   // the cause, under any exception a container wrapped it in
                }
                error_log('Neuron Cloud: agents run untraced, the service could not be built. ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $workflow;
    }
}
```

```php
// Wherever the application runs the agent: each run is sent as one trace when it ends
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\UserMessage;

$container->get(SupportAgent::class)->for($threadId)->chat(new UserMessage($prompt));
```

Without a container, the same `if` goes in `bootstrap.php`, and an entry script that builds agents hands `Tracing::subscribe()` a closure that requires it:

```php
// bootstrap.php
use NeuronCore\Cloud\NeuronCloud;

require_once __DIR__ . '/vendor/autoload.php';

$cloud = new NeuronCloud(
    getenv('NEURON_CLOUD_API_KEY') ?: throw new RuntimeException('Set NEURON_CLOUD_API_KEY: the API key of the Neuron Cloud project.'),
);

if (getenv('NEURON_CLOUD_TRACING') === 'true') {
    $cloud->enableTracing(captureContent: false);
}

return $cloud;
```

```php
// public/index.php, or any entry script that builds agents
use App\Agents\SupportAgent;
use App\Tracing;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCore\Cloud\NeuronCloud;

require_once __DIR__ . '/../vendor/autoload.php';

// The service of bootstrap.php, built the first time a traced agent asks for it, then shared
$cloud = function (): NeuronCloud {
    static $service;
    return $service ??= require __DIR__ . '/../bootstrap.php';
};

$agent = Tracing::subscribe(SupportAgent::make(), $cloud);
$agent->for($threadId)->chat(new UserMessage($prompt));
```

Set `NEURON_CLOUD_TRACING=true`, with the key, in the environment of every process that runs agents: web requests, CLI scripts, workers. With tracing off, `Tracing::subscribe()` never asks for the service, so a machine, a CI job or a test suite without the key builds its agents and sends nothing. With tracing on and a service that cannot be built, for want of the key or for anything else its entry throws, the agent is built and runs untraced, and the first failure of the process goes to `error_log()` with its cause: `RuntimeException: Set NEURON_CLOUD_API_KEY: …`. Once per process is once per request under PHP-FPM, and once per worker in a long-lived one. What asks for the service itself, `$container->get(NeuronCloud::class)`, `bootstrap.php` or an evaluator that takes `NeuronCloud`, still throws.

`Tracing::subscribe()` is a static method, not a closure the entries share, so that PHP-DI can compile the container: with `enableCompilation()` it refuses a closure that imports a variable, with `use` or as an arrow function that captures one. The closure each entry hands the method captures only the entry's own parameter, and compiles; and since the method takes a closure, not the container, an application with no container calls it too. PHP-DI hands on what a factory definition throws as it is, compiled or not. At an injected property or an array entry it may wrap it in a `DependencyException` that keeps it as `previous`, as the entry is defined and the container compiled or not: the class logs the innermost exception, so the line names the cause either way.

What a trace holds, and when it is sent:

- **A trace is one run.** Its spans follow the OpenTelemetry conventions for generative AI: `invoke_agent SupportAgent` or `invoke_workflow <class>`, named after the short name of the class, then a span for each node, `chat <model>` for each inference, `execute_tool <tool>` for each tool call and `retrieval` for RAG. The thread ID of an agent is `gen_ai.conversation.id`.
- **Always**: the names of nodes, tools, models and the provider, token counts, timings, and for every exception its class, its message and its stack trace. **With content capture**, which is on unless `enableTracing(captureContent: false)`: the messages given to and returned by the model, and the arguments and the result of every tool call, each cut at 4,000 characters. Choose before you turn tracing on. Exception messages are sent either way: where even those must not leave your application, leave tracing off.
- **`enableTracing(attributes: ['deployment.environment' => 'production'])`** stamps the same attributes on every span of the process. `OTEL_SERVICE_NAME` names the service of every trace.
- **It is best effort.** The process that ran the agent posts the trace when the run ends or is interrupted, with a 3-second timeout: in a web request, that is one call to the platform before `chat()` returns, or when the stream ends. A trace the platform did not take is logged with `error_log()` and dropped. It never changes the run or what the caller receives.

How the listener behaves:

- **It takes the settings of `enableTracing()`** as they are when `tracer()` is called. Each run it hears is sent as one trace when its segment ends.
- **It sends nothing while tracing is off.** A listener got before `enableTracing()` was called handles no event, so a line that subscribes it sends nothing where tracing is off. The line still asks for the service, which needs the key: where the key may be missing, ask for the service only when `NEURON_CLOUD_TRACING=true`, as `Tracing::subscribe()` does. The listener needs the API key and nothing else: no store, no queue, no registered definition.
- **One listener may serve several agents.** It keeps each run apart and sends the trace of a run when that run ends, without touching the others: an agent whose tool runs another agent, a node that runs an agent, two streams read in turns. Every call to `tracer()` returns a new listener, and a copy made with `for()` keeps the listener of the agent it copies.
- **Only an agent that carries a listener is traced.** One built with `make()` or `new` outside the container, in a script or an evaluator, goes through `Tracing::subscribe()` itself.
- **A registered definition is traced once, by the SDK.** Once `enableTracing()` was called, every segment the SDK executes for [orchestration](#4-orchestration), through `$cloud->workflow()` or in a worker, is traced under its definition name, such as `invoke_workflow order-fulfilment`, even when the factory of the definition, or a `configure()` callback, gave the workflow a `tracer()` listener too: that listener leaves the run to the SDK and sends nothing for it.

With `neuron-core/neuron-cloud-laravel`, `NEURON_CLOUD_TRACING=true` and the API key are all tracing needs: the bridge gives every agent and workflow its container builds a listener, which takes `tracer()` at its first run. An agent built with its static `make()` or with `new` subscribes `app(NeuronCloud::class)->tracer()` itself under `if (config('neuron-cloud.tracing.enabled'))`, and that hand-written line is not best effort: with tracing on and no key, it throws `not_configured`. One bound with `instance()` is handed out as it was bound, with only the listeners it had then. The bridge's own tracing is best effort, as `Tracing::subscribe()` is, and never stops the application: the container builds every agent and workflow without asking for `NeuronCloud`, and where `NeuronCloud` cannot be built at the first run, for want of the API key, for a config the SDK refuses or for any other exception, the agent or the workflow works untraced and the exception goes to the application's exception handler once per process. The bridge's README has the details.

## 3. Evaluations

Two pieces, each wired on its own: the results of every evaluation run, and evaluators that run on a dataset the platform keeps. Both need the API key and nothing else.

### The evaluation output

`evaluationOutput()` returns an output driver for Neuron's evaluations. List it in `evaluation.php`, the file `vendor/bin/neuron evaluation` reads from the project root, and make the application's container the `resolver`:

```php
// evaluation.php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\Cloud\NeuronCloud;

$container = require __DIR__ . '/bootstrap/container.php';   // however your application builds its container

return [
    'output' => array_filter([
        ConsoleOutput::class,
        // Listed only where the key is set: a machine without it still evaluates
        getenv('NEURON_CLOUD_API_KEY') ? $container->get(NeuronCloud::class)->evaluationOutput(environment: 'ci') : null,
    ]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => $container->get($class),
];
```

- **One message per evaluator** is sent when all evaluators are done: its class, its summary, and for every dataset item the input, the output, the scores and the failed assertions. Inputs and outputs do leave your application: that is the point of this output.
- **`environment`** names where the evaluation ran, such as `ci`, or is null.
- **It is best effort too.** A message the platform did not take is logged, the other outputs still run, and the exit code of the command is what the assertions decide.
- **Never build `CloudOutput` with `new`**: get it from `evaluationOutput()`.

### Datasets

An evaluator can run on a dataset kept on Neuron Cloud instead of a JSON file. It receives `NeuronCloud` in its constructor, beside the agent it evaluates, and returns one of its datasets from `getDataset()`:

```php
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\UniqueIdGenerator;
use NeuronCore\Cloud\NeuronCloud;

class RefundEvaluator extends BaseEvaluator
{
    public function __construct(protected SupportAgent $agent, protected NeuronCloud $cloud)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return $this->cloud->dataset('refund-requests');   // the slug of the dataset on the platform
    }

    public function run(array $datasetItem): mixed
    {
        return $this->agent->for(UniqueIdGenerator::generateId('eval_'))   // a thread of its own for every item
            ->chat(new UserMessage($datasetItem['input']['question']))
            ->getMessage()
            ->getContent();
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
- **Another container**: the `resolver` of the output's `evaluation.php` above, `fn (string $class): object => $container->get($class)`. The container must build classes by name, as PHP-DI does, and hold the `NeuronCloud` of section 1.
- **No container**: `evaluation.php` takes the service from `bootstrap.php` and gives it to the evaluators that need it:

```php
// evaluation.php
use App\Agents\SupportAgent;
use App\Evaluators\RefundEvaluator;
use NeuronAI\Evaluation\Output\ConsoleOutput;

$cloud = getenv('NEURON_CLOUD_API_KEY') ? (require __DIR__ . '/bootstrap.php') : null;

return [
    'output' => array_filter([ConsoleOutput::class, $cloud?->evaluationOutput(environment: 'ci')]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => match ($class) {
        RefundEvaluator::class => new RefundEvaluator(
            SupportAgent::make(),
            $cloud ?? throw new RuntimeException('Set NEURON_CLOUD_API_KEY: RefundEvaluator runs on a dataset of Neuron Cloud.'),
        ),
        default => new $class(),
    },
];
```

What to expect:

- **A dataset needs the API key and nothing else**: no store, no queue, no signing key.
- **Each item runs on a copy of the agent**, bound to a thread of its own with `for()`. The agent the container injects carries the listener of its entry, so the runs of an evaluation are traced where the environment of the evaluation sets `NEURON_CLOUD_TRACING=true`. The agent the resolver without a container builds is not, unless the resolver passes it through `Tracing::subscribe()`.
- **The items are read once per evaluator**, when Neuron loads the dataset: before the first item runs, and before `--concurrency` forks. Building the evaluator reads nothing.
- **A dataset that cannot be read stops its evaluator.** For an unknown slug, a refused API key, or no answer within 15 seconds, `load()` throws a `CloudException`. Neuron's command then reports that evaluator as one that could not run, runs the others, and exits with 1. A missing API key has the same effect one step earlier, when the evaluator is built: the container throws, the bridge's included, and so does the resolver above.
- **Only the active items of a dataset are sent.** A dataset with none runs nothing.
- **`--cache` keys an item by its content.** An item you change on the platform runs again; an unchanged one is served from the cache.

## 4. Orchestration

For advanced use, and only when you want it: tracing and evaluations need none of this. Your application keeps running every workflow in its own processes and keeps every run in its own store. Neuron Cloud keeps a list of your runs and a clock: it fires the timers your workflows wait on, retries a run after a failure or a crashed worker, and starts runs on a schedule. For these runs only coordination data reaches it: definition names, workflow and run IDs, statuses, wake-up times and the class of an exception. A trace of each segment reaches it only with tracing on (section 2).

Orchestration needs, beside the API key:

- The signing key of your Neuron Cloud project, `NEURON_CLOUD_SIGNING_KEY`
- A workflow store every process shares: Neuron's `DatabasePersistence`, `EloquentPersistence` or `RedisPersistence`
- A durable queue with at least one worker
- A public HTTPS URL the platform can call

### How it works

A run executes in **segments**: from a start, an answer or a wake-up, to the next wait or to the end. Before a segment the SDK tells the platform "this run is starting"; after it, "this is the run now". When a timer or a retry comes due, the platform calls one signed URL of your application, the SDK puts that call on your queue, and a worker continues the run.

You wire five pieces:

| Piece | What it does |
|---|---|
| The service | `NeuronCloud`, built once per process from your API key and signing key |
| The dispatcher | Hands a message of the SDK to your queue |
| The endpoint | The URL the platform calls |
| The worker | Takes one message off the queue and gives it to the SDK |
| The sync | Publishes your workflows and the endpoint URL at the end of every deploy, or when the platform asks for it |

### The service

The service of section 1 grows: the signing key, the store, the dispatcher, the endpoint URL and the definitions. One bootstrap file, required by the endpoint, the worker, the deploy script and your application:

```php
// bootstrap.php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronCore\Cloud\NeuronCloud;

require_once __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(getenv('DATABASE_DSN'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$redis = new Redis();
$redis->connect('127.0.0.1');

$cloud = new NeuronCloud(
    apiKey: getenv('NEURON_CLOUD_API_KEY') ?: throw new RuntimeException('Set NEURON_CLOUD_API_KEY: the API key of the Neuron Cloud project.'),
    signingKey: getenv('NEURON_CLOUD_SIGNING_KEY') ?: null,   // only the endpoint needs it
);

if (getenv('NEURON_CLOUD_TRACING') === 'true') {
    $cloud->enableTracing(captureContent: false);   // before any workflow() or tracer()
}

return $cloud
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->setEndpointUrl('https://shop.example.com/neuron-cloud.php')
    ->register('order-fulfilment', fn () => new OrderWorkflow())
    ->register('support-agent', fn () => new SupportAgent());
```

In a container, the entry of section 1 makes the same calls. Without one, an entry script that requires this file and builds traced agents hands `Tracing::subscribe()` the service it holds, `fn (): NeuronCloud => $cloud`: the closure of section 2 would require the file a second time, with a second connection to the database and to Redis.

- **`register()`** gives a workflow or an agent the name the platform knows it by. The factory builds the class from nothing but the workflow ID: a worker has no request and no logged-in user.
- **`setPersistence()`** takes a closure that returns the store. `DatabasePersistence` needs Neuron's `workflow_store` table.
- **`setEndpointUrl()`** names the URL the platform calls: what the sync publishes.
- **The lease**, `register(..., leaseTimeout: 600)` by default, must be longer than your slowest node.

The `evaluation.php` of [section 3](#datasets) requires this file too, and with it opens the database and Redis. Where evaluations run without them, a CI job for one, its `$cloud` line builds a service of its own with the API key alone, which is all evaluations need, and the rest of the file stays as it is:

```php
// evaluation.php, where evaluations run without the database or Redis
use NeuronCore\Cloud\NeuronCloud;

$cloud = getenv('NEURON_CLOUD_API_KEY') ? new NeuronCloud(getenv('NEURON_CLOUD_API_KEY')) : null;
```

### The dispatcher

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

### The endpoint

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

### The worker

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

### The sync

At the end of every deploy, after the workers have restarted on the new code:

```php
// bin/sync.php
$cloud = require __DIR__ . '/../bootstrap.php';

$cloud->sync();
```

It publishes every registered definition and the endpoint URL of `setEndpointUrl()`; a URL given to `sync()` is published instead. The platform must be able to reach that URL over HTTPS.

The platform can also ask your endpoint for the same list, when someone requests a sync from its dashboard: the endpoint answers with exactly what `sync()` would post. Where `sync()` would refuse to publish (no endpoint URL set, an in-memory or file store, nothing registered), the endpoint refuses too, with `409 sync_refused`, and does not say why: run the sync from the deploy to see the reason.

### Starting and answering runs

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

## Testing

`FakePlatform` stands in for Neuron Cloud: give it to the service as its HTTP client. It takes traces and evaluation results in, as the platform does, and serves the datasets you give it. Neuron's `FakeAIProvider` answers for the model.

**Keep the test suite off the platform.** With `NEURON_CLOUD_TRACING=true` in the environment the tests run in, every agent a test takes from the container of section 2 sends its trace with the developer's key:

```xml
<!-- phpunit.xml, in <php>: force="true" wins over what the shell exports -->
<env name="NEURON_CLOUD_TRACING" value="false" force="true"/>
<env name="NEURON_CLOUD_API_KEY" value="test-key" force="true"/>
```

With tracing off `Tracing::subscribe()` never asks for the service. The test key lets the code that takes it be built, an evaluator on a dataset for one, and building it sends nothing. A test of tracing that takes its agent from the container sets the variable itself, as `Tracing::subscribe()` reads it, with `putenv('NEURON_CLOUD_TRACING=true')`, and puts it back in `tearDown()`, as the skill's [`testing.md`](skills/neuron-cloud-php/references/testing.md) does. The tests below build their agents and their service themselves, and read no variable.

```php
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakePlatform;

$platform = new FakePlatform();
$cloud = (new NeuronCloud('test-key', httpClient: $platform))->enableTracing();

SupportAgent::make()
    ->setAiProvider(new FakeAIProvider(new AssistantMessage('Your refund is on its way.')))
    ->subscribe(ObservabilityEvent::class, $cloud->tracer())
    ->for('thread-1')
    ->chat(new UserMessage('Where is my refund?'));

$platform->assertTraced('invoke_agent SupportAgent');
```

An evaluator runs on the items the fake keeps, and the results its output sends reach the fake:

```php
use App\Agents\SupportAgent;
use App\Evaluators\RefundEvaluator;
use DateTimeImmutable;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakePlatform;

$platform = (new FakePlatform())->keepDataset('refund-requests', [
    ['input' => ['question' => 'Can I get a refund?'], 'expected_output' => ['answer' => 'refund']],
]);
$cloud = new NeuronCloud('test-key', httpClient: $platform);
$agent = SupportAgent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('Your refund is on its way.')));

$startedAt = new DateTimeImmutable();
$results = (new EvaluatorRunner())->run(new RefundEvaluator($agent, $cloud));   // reads the dataset from the fake
$finishedAt = new DateTimeImmutable();

// What Neuron's evaluation command hands the outputs of evaluation.php
$cloud->evaluationOutput(environment: 'test')->output(new EvaluationReport(
    [new EvaluatorReport(RefundEvaluator::class, $results, $startedAt, $finishedAt)],
    $startedAt,
    $finishedAt,
));

$platform->assertEvaluated(RefundEvaluator::class);
```

- **`assertTraced()`** takes the name of the parentless span of a trace: `invoke_agent <name>` for an agent, `invoke_workflow <name>` for a workflow. The name is the short class name for a run your application runs with `tracer()`, as in `invoke_agent SupportAgent`, and the definition name for a run the SDK executes, as in `invoke_workflow order-fulfilment` or `invoke_agent support-agent`.
- **`assertEvaluated()`** takes the class of the evaluator.
- **`keepDataset()`** answers the read of its slug with the items, in order, each as the platform answers it: `['input' => ..., 'expected_output' => ...]`, the expected output null where you give none, and no other key. A slug it was not given is answered 404, and `load()` throws, as against the platform.
- **`failNext()` comes first.** `$platform->failNext(1, 503)` plays a platform that is away for the next request, and an assertion counts no request it refused.

For orchestration, `FakeDispatcher` stands in for your queue:

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

`$platform->wake($cloud, 'order:123')` sends the endpoint the platform's wake of that run, signed, and `$platform->deliver($cloud, [...])` any other message. `assertAnnounced()`, `assertReported()` and `assertSynced()` read what the platform recorded, and `$queue->assertDispatched()` reads the queue.

## Further reading

- [`SDK_DESIGN.md`](SDK_DESIGN.md): the whole design, the public API, the guarantees you must keep, and what happens when things go wrong.
- [`PLATFORM_PROTOCOL.md`](PLATFORM_PROTOCOL.md): the protocol between the SDK and the platform.
- [`skills/`](skills): the skills for coding agents.

## License

MIT. See [LICENSE](LICENSE).
