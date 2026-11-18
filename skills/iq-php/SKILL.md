---
name: iq-php
description: Connect a PHP application's Neuron AI agents and workflows to IQ with the neuron-core/iq-php-sdk package, one stage at a time. First bind the IQ service in the container with the API key, then trace agents and workflows as OpenTelemetry spans (enableTracing(), tracer(), IQListener), then send evaluation results (evaluationOutput(), IQOutput) and run evaluators on datasets the platform keeps (dataset(), IQDataset). Durable orchestration is an advanced step, wired only on request, for runs that survive a crash, a deploy or a wait of days, with timers, retries and scheduled starts kept by the platform (register(), workflow(), handle(), invoke(), sync(), ManagedWorkflow, PendingSegment, DispatcherInterface, Invocation, FakeDispatcher). Use when the user mentions IQ, the IQ SDK, traces, evaluation results or datasets on IQ, durable workflows, IQException, FakePlatform, IQ_API_KEY or IQ_SIGNING_KEY. For Laravel, use iq-laravel.
---

# IQ SDK

`neuron-core/iq-php-sdk` connects the agents and workflows of a PHP application to IQ. A developer adopts it in stages. Each stage is wired on its own, and only when it is wanted:

| Stage | What the developer gets | What you wire | Needs |
|---|---|---|---|
| **0. The service** | One `IQ` that every part of the application can take | The package, and one entry in the container | `IQ_API_KEY` |
| **1. Observability** | Every run of an agent or a workflow as an OpenTelemetry trace: nodes, inferences, tool calls, retrievals | `enableTracing()` on the service, and `$iq->tracer()` subscribed through one small class where agents are built | The API key |
| **2. Evaluation** | The results of `vendor/bin/neuron evaluation` on the platform, and evaluators that run on datasets the platform keeps | An output in `evaluation.php`, `dataset()` in an evaluator | The API key |
| **3. Orchestration** (advanced) | Runs that survive a crash, a deploy or a wait of days. The platform fires their timers, retries them and starts them on a schedule | A store, a queue, an endpoint, a worker, a sync: [references/orchestration.md](references/orchestration.md) | The signing key too |

The agents and workflows stay ordinary Neuron code that knows nothing about the SDK: write them with **neuron-agent** and **neuron-workflow**. This skill is the plain PHP wiring. **In a Laravel application use the iq-laravel skill**: its package binds the service and wires each stage from its config. Any other framework is wired as below, with the service in its container.

## Which Stage a Request Gets

- **Stage 0 comes first, when it is missing.** It needs the API key and nothing else.
- **A request that names no stage** ("add IQ", "connect this app to IQ") gets stages 0 and 1, then stops. End the answer by naming evaluation as the next step: the results of every evaluation run sent to the platform, and evaluators run on the datasets it keeps.
- **A request that names a stage gets stage 0 and that stage.** Tracing, monitoring or observability is stage 1. Evaluation results, an evaluation output, a dataset or evaluations reported from CI is stage 2. Each stage stands on stage 0 alone: a request for evaluation does not turn tracing on. Say in one line which earlier stage is not wired yet, and leave it unwired.
- **Orchestration is wired only when the developer asks for it**: durable runs, timers, retries, scheduled starts, runs that survive a crash, a deploy or a wait of days, runs started or answered through `$iq->workflow()`, or one of its pieces by name. Read [references/orchestration.md](references/orchestration.md) then, and not before.
- **The signing key, the store, the queue, the endpoint, the worker and the sync belong to orchestration.** Never add them for stages 0 to 2, and never present them as requirements of those stages.
- **A developer who asks for everything gets every stage**, in this order.

Check where the application stands first, and build on the service that is there. Never build a second one.

```bash
grep -rlsE 'IQ|neuron-core/iq-php-sdk' composer.json config src app bootstrap bootstrap.php public bin evaluation.php
```

## Stage 0: The Service

```bash
composer require neuron-core/iq-php-sdk
```

`IQ_API_KEY` comes from the dashboard of the IQ project. Put it in the environment of every process that uses the service.

**With a container**, add one entry to its definitions. PHP-DI is the example: it builds classes by name and injects what their constructors ask for.

```php
// config/container.php: the definitions of the application's PHP-DI container
use NeuronCore\IQ\IQ;

return [
    // One service for the whole application: the container builds it the first time something asks for it.
    IQ::class => fn (): IQ => new IQ(
        getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
    ),
];
```

**Without a container**, one file builds the service. An entry script that uses the service requires it once and hands it to what needs it.

```php
// bootstrap.php: the service, for an application with no container
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

return new IQ(
    getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
);
```

- **The API key is all the service needs** for stages 0 to 2. `IQ_SIGNING_KEY` comes with orchestration.
- **One service per process, shared.** A class takes `IQ` in its constructor, and the container injects it. A script takes it with `$container->get(IQ::class)` or from `bootstrap.php`. Every later stage takes it from there.
- **Guard the key.** `new IQ(getenv('IQ_API_KEY'))` without the key throws a `TypeError` in a file that declares `strict_types`. Without `strict_types` it builds a service with an empty key, and the platform refuses every request. The `throw` names the variable instead.
- **Read the variables as the application reads its other settings.** The snippets use `getenv()`, which sees the environment of the process. A `.env` file loaded with phpdotenv's `Dotenv::createImmutable()`, or with Symfony's Dotenv, fills `$_ENV` and `$_SERVER` and leaves `getenv()` empty: there the entry throws although the key is in `.env`, and tracing never turns on. In such an application read `$_ENV['IQ_API_KEY'] ?? null`, or the config the application builds from it, and the same for every variable of this skill.
- **Building the service sends nothing.** Nothing reaches the platform until a stage uses it.
- **Another container** holds the same entry as one shared service, built with the key from the environment.

## Stage 1: Observability

Tracing is two things: `enableTracing()` where the service is built, and a listener from `$iq->tracer()` subscribed where the application builds an agent or a workflow, through one small class of the application, `App\Tracing`. Both depend on `IQ_TRACING`, and tracing is best effort: with it off, the service is never asked for and no key is needed anywhere an agent is built; with it on and a service that cannot be built, the agent is built and runs untraced, and the cause is logged once per process.

**Ask the developer first.** Tracing sends what the application works on:

- **The names and IDs of every run**, always: the class, the run ID, and the thread ID of an agent as `gen_ai.conversation.id`. A thread named after its user carries the user's ID.
- **The message and the stack trace of every exception**, always.
- **Prompts, completions, tool arguments and tool results**, unless `captureContent` is `false`.

Ask which they want before you write `enableTracing()`, and say why when the agents handle personal, financial, medical or confidential data. Write their answer as `captureContent`, and say which argument changes it. Never decide it silently: when you cannot ask, write `captureContent: false` and say so in your answer. Exception messages cannot be held back: where even those must not leave the application, leave tracing off.

```php
// config/container.php
use App\Agents\SupportAgent;
use App\Tracing;
use NeuronCore\IQ\IQ;
use Psr\Container\ContainerInterface;

return [
    IQ::class => function (): IQ {
        $iq = new IQ(
            getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
        );

        if (getenv('IQ_TRACING') === 'true') {
            // The developer's answer: false keeps prompts, completions and tool data in the application.
            $iq->enableTracing(captureContent: true);
        }

        return $iq;
    },

    // One entry like this for every agent and workflow the application builds. Tracing::subscribe() calls the
    // closure only when tracing is on, and hands the agent back untraced when the service cannot be built.
    SupportAgent::class => fn (ContainerInterface $c): SupportAgent => Tracing::subscribe(
        SupportAgent::make(),
        fn (): IQ => $c->get(IQ::class),
    ),
];
```

The class is the application's: one file, written once, that every agent and workflow goes through.

```php
// src/Tracing.php: where every agent and workflow the application builds gets its tracer
namespace App;

use Closure;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronCore\IQ\IQ;
use Throwable;

final class Tracing
{
    protected static bool $failureLogged = false;

    /**
     * @template T of WorkflowInterface
     * @param T $workflow an agent or a workflow
     * @param Closure(): IQ $iq called only when tracing is on
     * @return T the same object, with its tracer when the service could be built
     */
    public static function subscribe(WorkflowInterface $workflow, Closure $iq): WorkflowInterface
    {
        if (getenv('IQ_TRACING') !== 'true') {
            return $workflow;   // tracing off: the service is never asked for, and no key is needed
        }

        try {
            $workflow->subscribe(ObservabilityEvent::class, $iq()->tracer());
        } catch (Throwable $e) {
            // Best effort: the agent runs untraced, and the first failure of the process is logged.
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                while ($e->getPrevious() !== null) {
                    $e = $e->getPrevious();   // the cause, under any exception a container wrapped it in
                }
                error_log('IQ: agents run untraced, the service could not be built. ' . $e::class . ': ' . $e->getMessage());
            }
        }

        return $workflow;
    }
}
```

**Without a container**, the same `if` goes in `bootstrap.php`, and an entry script that builds agents hands `Tracing::subscribe()` a closure that requires it:

```php
// bootstrap.php
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

$iq = new IQ(
    getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
);

if (getenv('IQ_TRACING') === 'true') {
    $iq->enableTracing(captureContent: true);   // the developer's answer, as above
}

return $iq;
```

```php
// public/index.php, or any entry script that builds agents
use App\Agents\SupportAgent;
use App\Tracing;
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/../vendor/autoload.php';

// The service of bootstrap.php, built the first time a traced agent asks for it, then shared
$iq = function (): IQ {
    static $service;
    return $service ??= require __DIR__ . '/../bootstrap.php';
};

// Where the application builds the agent, with the $iq the entry script hands it
$agent = Tracing::subscribe(SupportAgent::make(), $iq);
```

Set `IQ_TRACING=true`, with the key, in the environment of every process that runs agents: web, CLI, workers.

**Keep the test suite off the platform**, in the same step. With `IQ_TRACING=true` in the environment the tests run in, or in a `.env` their bootstrap loads, every agent a test takes from the container sends its trace with the developer's key:

```xml
<!-- phpunit.xml, in <php>: force="true" wins over what the shell exports -->
<env name="IQ_TRACING" value="false" force="true"/>
<env name="IQ_API_KEY" value="test-key" force="true"/>
```

With tracing off `Tracing::subscribe()` never asks for the service. The test key lets the code that takes it be built, an evaluator on a dataset for one, and nothing is sent.

- **A trace is one run.** Every `chat()`, `stream()` or `run()` of an agent or a workflow that carries the listener sends its spans when it ends or is interrupted. Spans follow the OpenTelemetry conventions for generative AI: `invoke_agent SupportAgent` or `invoke_workflow <class>`, named after the short class name, then a span per node, `chat <model>` per inference, `execute_tool <tool>` per tool call, `retrieval` for RAG. For an agent, the thread ID is `gen_ai.conversation.id`. The trace ID comes from the run ID, so a run resumed after an interruption adds to the same trace, when the agent that resumes it carries a listener too.
- **The listener is silent until `enableTracing()`.** `tracer()` reads the settings of `enableTracing()` when it is called. Before that, or on a service with tracing off, it returns a listener that handles no event. Call `enableTracing()` where the service is built, before any `tracer()`.
- **One listener may serve several agents.** It keeps each run apart: an agent whose tool runs another agent, a workflow node that runs an agent, two streams read in turns. A copy made with `$agent->for($threadId)` keeps the listener of the agent it copies. Each `tracer()` call returns a new listener, and one per agent works the same.
- **Only agents that carry a listener are traced.** An agent built with `make()` or `new` outside the container, in a script or an evaluator, goes through `Tracing::subscribe()` too. `tracer()` needs the API key only: no store, no queue, no registered definition.
- **A run the SDK executes is traced by the SDK.** Once orchestration registers an agent, every run of it that goes through `$iq->workflow()` is traced once, by the SDK, under its definition name. The listener of the container's agent then leaves that run alone: nothing to remove.
- **It is best effort.** The trace is posted by the process that ran the agent, with a 3 second timeout: in a web request, one call to the platform before `chat()` returns, or when the stream ends. A trace the platform did not take is logged with `error_log()` and dropped. It never changes the run or what the caller receives.
- **`enableTracing(attributes: ['deployment.environment' => 'production'])`** stamps the same attributes on every span of the process: the environment, a release. There is no per-run attribute. `OTEL_SERVICE_NAME` names the service of every trace.
- **Tracing never stops an agent.** With tracing off, `Tracing::subscribe()` hands the agent back as it is and never calls the closure: a machine, a CI job or a test suite without the key builds its agents and sends nothing. With tracing on, a service that cannot be built, for want of the key or for anything else its entry throws, leaves the agent untraced: it is built and runs, and the first failure of the process goes to `error_log()` with its cause, `RuntimeException: Set IQ_API_KEY: …`. Every agent built after it asks again and logs nothing. Once per process is once per request under PHP-FPM, once per worker in a long-lived one, once per run of a test suite.
- **Only `Tracing::subscribe()` catches.** What asks for the service itself still throws without the key: `$c->get(IQ::class)`, `bootstrap.php`, an evaluator that takes `IQ` (stage 2). An entry that takes the service as a parameter, `fn (IQ $iq)`, makes the container build it for every agent, tracing off or not, and fails the agent without the key.
- **The logged line names the cause.** PHP-DI hands on what a factory definition throws as it is, compiled or not. At an injected property or an array entry it may wrap it in a `DependencyException` that keeps it as `previous`, as the entry is defined and the container compiled or not. The class logs the innermost exception, so the line names the variable either way.
- **A static method, because PHP-DI compiles it.** With `enableCompilation()`, PHP-DI copies each definition's code into a class, and refuses a closure that imports a variable, with `use` or as an arrow function that captures one: a closure shared by the agents' entries cannot be compiled. The closure an entry hands `Tracing::subscribe()` captures only `$c`, the parameter of the entry, and compiles. Taking a closure, not the container, the method serves an application with no container too.
- **The variable decides, not the service alone.** A service with tracing on gives no listener to an agent while `Tracing::subscribe()` reads `IQ_TRACING` as off. A test of tracing sets the variable too, and binds the service on `FakePlatform`: [references/testing.md](references/testing.md).
- Neuron's own events, custom listeners and logging: **neuron-monitoring**.

When the request named no stage, stop here and name evaluation as the next step.

## Stage 2: Evaluation

Two pieces, each wired when the developer asks for it: the results of every evaluation run, and evaluators that run on a dataset the platform keeps. Both need the API key only.

### The evaluation output

`evaluationOutput()` returns an output driver for Neuron's evaluations. List it in `evaluation.php`, the file `vendor/bin/neuron evaluation` reads from the project root. Its `resolver` is the application's container:

```php
// evaluation.php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\IQ\IQ;

$container = require __DIR__ . '/bootstrap/container.php';   // however the application builds its container

return [
    'output' => array_filter([
        ConsoleOutput::class,
        // Sent only where the key is set: a laptop without it still evaluates.
        getenv('IQ_API_KEY') ? $container->get(IQ::class)->evaluationOutput(environment: getenv('IQ_EVALUATION_ENVIRONMENT') ?: null) : null,
    ]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => $container->get($class),
];
```

- **`evaluationOutput()` returns an `IQOutput`**, built with the API key, the platform URL and the HTTP client of the service it comes from.
- **Only the API key is needed**: no store, no queue, no registered definition.
- **Keep the guard.** Without the key, the entry of stage 0 throws, and so would `evaluation.php`: the command would end with no result at all.
- **One message per evaluator** is sent when all evaluators are done: its class, its summary, and for every dataset item the input, the output, the scores and the failed assertions. Inputs and outputs do leave the application: that is the point of this output.
- **`environment`** names where the evaluation ran (`ci`, `local`, a branch), or is null.
- **It is best effort too.** A platform that is away is logged. The other outputs still run, and the exit code of the command is what the assertions decide.
- **Never build `IQOutput` with `new`**: its constructor takes a class internal to the SDK.

Evaluators, assertions and the other output drivers: **neuron-evaluation**.

### Datasets

`dataset()` returns a dataset of Neuron's evaluations whose items IQ keeps, an `IQDataset`. An evaluator returns it from `getDataset()` in place of a `JsonDataset`, with the service the container injects:

```php
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\UniqueIdGenerator;
use NeuronCore\IQ\IQ;

class RefundEvaluator extends BaseEvaluator
{
    public function __construct(protected SupportAgent $agent, protected IQ $iq)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return $this->iq->dataset('refund-requests');   // the slug of the dataset on the platform
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

The `resolver` above builds it: the container injects the agent, with its listener where stage 1 is wired and tracing is on, and the service. **Without a container**, `evaluation.php` takes the service from `bootstrap.php` and hands it over:

```php
// evaluation.php
use App\Agents\SupportAgent;
use App\Evaluators\RefundEvaluator;
use NeuronAI\Evaluation\Output\ConsoleOutput;

$iq = getenv('IQ_API_KEY') ? (require __DIR__ . '/bootstrap.php') : null;

return [
    'output' => array_filter([ConsoleOutput::class, $iq?->evaluationOutput(environment: getenv('IQ_EVALUATION_ENVIRONMENT') ?: null)]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => match ($class) {
        RefundEvaluator::class => new RefundEvaluator(
            SupportAgent::make(),
            $iq ?? throw new RuntimeException('Set IQ_API_KEY: RefundEvaluator runs on a dataset of IQ.'),
        ),
        default => new $class(),
    },
];
```

- **An item is an array with the `input`, the `expected_output` and the `metadata` stored on the platform**, each whatever JSON was stored there, null when the item has no expected output or metadata, and the `dataset_item_id` the platform uses to compare the item from one evaluation to the next. Only the active items of the dataset are sent. Ask the developer what an item holds, or read one, before writing `run()` and `evaluate()`, and read the keys you need: never spread the item into named arguments.
- **Only the API key is needed**: no store, no queue, no registered definition.
- **Each item runs on a copy of the agent**, bound to a new thread with `for()`. `chat()` on the injected agent itself would run every item in one conversation, or find no thread at all, and `setThreadId()` cannot move a bound agent to another thread. An agent whose class keeps conversations in a database gets in-memory stores on the copy, `->setMessageStore(new InMemoryMessageStore())->setPersistence(new InMemoryPersistence())`, so an evaluation never writes to the conversation tables.
- **Evaluation runs are traced where stage 1 is wired and the evaluation environment sets `IQ_TRACING=true`**: the agent the container injects then carries its listener. Without the agent entry of stage 1 the container builds a bare agent and the variable changes nothing; the agent the no-container resolver builds has no listener either, unless the resolver passes it through `Tracing::subscribe()`.
- **The items are read once per evaluator**, when Neuron's runner loads the dataset: after `setUp()`, before the first item runs, in the process of the command, before `--concurrency` forks. With tracing off the children never call the platform; with a traced agent each child posts the traces of the items it runs. `dataset()` itself reads nothing.
- **A dataset that cannot be read stops its evaluator.** An unknown slug, a refused key, no answer within 15 seconds, or an answer that is not a dataset makes `load()` throw an `IQException`. The command reports that evaluator as one that could not run, runs the others and exits with 1. A missing key stops it the same way, when it is built: the entry of stage 0 throws, and so does the resolver without a container.
- **An empty dataset runs nothing**, and nothing fails.
- **`--cache` keys an item by its content and its `dataset_item_id`**: an item changed on the platform or imported again runs again, an unchanged one is served from the cache.
- **Never build `IQDataset` with `new`**: its constructor takes a class internal to the SDK.

## Next: Orchestration

Stop after the stage the request named. Read [references/orchestration.md](references/orchestration.md) only when the developer asks for durable runs, timers, retries, scheduled starts, or runs that survive a crash, a deploy or a wait of days. It adds the signing key, a store, a queue, an endpoint, a worker and a sync to the service of stage 0, and changes how the application starts and answers the runs of a registered workflow.

## Testing

A test builds the service on the SDK's `FakePlatform`, which answers as the platform does. `$platform->assertTraced('invoke_agent SupportAgent')` checks that a run was traced, `keepDataset($slug, $items)` gives an evaluator the items of a dataset, and `assertEvaluated(RefundEvaluator::class)` checks that the evaluation output sent an evaluator's results. A test of tracing also sets `IQ_TRACING=true`, since `Tracing::subscribe()` reads it. [references/testing.md](references/testing.md) shows a traced agent, an evaluator on a dataset, and the `FakeDispatcher` of orchestration.

## Pitfalls

- `new IQ(getenv('IQ_API_KEY'))` with no guard → a `TypeError` where the file declares `strict_types`, and a service whose every request is refused where it does not. Throw a message that names the variable.
- An `IQ` built inside a class or per request → its settings differ from the container's, and tracing may be off in it. Take the shared one.
- A queue, a store, an endpoint, a worker or a sync wired for "add IQ" → orchestration nobody asked for, with a public URL and a process to keep alive. Stop at stage 1.
- `enableTracing()` with default arguments on sensitive data, with no question asked → prompts and tool results reach the platform. Ask the developer; exception messages are sent either way.
- `enableTracing()` called after `tracer()` → that listener was built with tracing off and sends nothing. Call it where the service is built.
- An agent's entry that takes `IQ` as a parameter → the service is built for every agent, tracing off or not, and without the key the agent is not built. Go through `Tracing::subscribe()`.
- An agent's entry that subscribes `$c->get(IQ::class)->tracer()` itself → not best effort: without the key the agent is not built, with tracing on even under the `IQ_TRACING` check, and with tracing off too where there is no check. Go through `Tracing::subscribe()`.
- `IQ_TRACING=true` where `IQ_API_KEY` is not set → every agent runs untraced, and one `error_log()` line per process says why. Set the key in the same environment, and read the error log once tracing is on.
- A `Tracing::subscribe()` that catches `IQException` only → the `RuntimeException` of the entry, the missing key, still stops the agent. Catch `Throwable`.
- A closure shared by the agents' entries, `use ($traced)` or an arrow function that captures it → with `enableCompilation()`, PHP-DI's `build()` throws "Cannot compile closures which import variables using the `use` keyword". Call the static method.
- `IQ_TRACING=true` in the web environment only → agents run by a CLI script or a worker send nothing. Set it wherever agents run.
- `IQ_TRACING=true` where the tests run, and nothing in `phpunit.xml` → the test suite sends traces with the developer's key. Force it to `false` there.
- `getenv()` in an application that loads `.env` with `Dotenv::createImmutable()` → the key is in `.env`, and the entry still throws; tracing never turns on. Read `$_ENV`, as the application does.
- An agent built with `make()` outside the container → not traced. Pass it through `Tracing::subscribe()`, or take it from the container.
- A test of tracing that turns tracing on in the service only → `Tracing::subscribe()` reads the variable as off, the agent carries no listener, and `assertTraced()` fails. Set `IQ_TRACING=true` in the test.
- `new IQListener(...)` → its constructor takes a class internal to the SDK. Use `$iq->tracer()`.
- `SupportAgent::make()->chat(...)` or `$this->agent->chat(...)` in an evaluator → no thread ID; on an agent already bound (`make('support')`, a class that declares `workflowId()`), every item runs in one conversation and sees the items before it, and nothing fails; `setThreadId()` per item throws on the second. Use `$this->agent->for($threadId)`.
- An evaluator that takes `IQ` in its constructor and no `resolver` in `evaluation.php` → "requires constructor arguments: build it with a resolver". Make the container the resolver.
- An evaluator that catches the `IQException` of its dataset and returns an empty list → it runs nothing, and the command passes. Let it throw.

## Related

- **iq-laravel** — the same stages in a Laravel application, through the `neuron-core/iq-laravel` package.
- [references/orchestration.md](references/orchestration.md) — stage 3: durable runs, the store, the queue, the endpoint, the worker, the sync.
- [references/testing.md](references/testing.md) — `FakePlatform` for traces, evaluation results and datasets (`assertTraced()`, `assertEvaluated()`, `keepDataset()`); `FakeDispatcher` for orchestration.
- **neuron-agent** and **neuron-workflow** — agents, providers, tools, chat history, nodes and waits.
- **neuron-evaluation** — evaluators, datasets, assertions, output drivers.
- **neuron-monitoring** — Neuron's events and listeners.
- **neuron-test** — `FakeAIProvider` and the other fakes.
