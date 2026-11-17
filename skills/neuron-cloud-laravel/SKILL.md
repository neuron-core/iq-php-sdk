---
name: neuron-cloud-laravel
description: Connect a Laravel application's Neuron AI agents to Neuron Cloud with the neuron-core/neuron-cloud-laravel package, one stage at a time — install it with NEURON_CLOUD_API_KEY so the container binds the NeuronCloud singleton, trace every agent and workflow the container builds with NEURON_CLOUD_TRACING (tracer(), CloudListener), send neuron:evaluate results with CloudOutput in evaluation.php, and run evaluators on platform datasets with dataset() and CloudDataset. Orchestration is the advanced stage, wired only on request — durable runs, platform timers, retries and scheduled starts, runs that survive a crash, a deploy or a wait of days, through config/neuron-cloud.php definitions, the neuron-cloud queues, POST /neuron-cloud, neuron-cloud:sync, RunInvocation, ObserveRun, InvocationSettled and NEURON_CLOUD_SIGNING_KEY. Use it whenever the host app is Laravel and the user mentions Neuron Cloud, traces, evaluation results or datasets on Neuron Cloud, or durable, orchestrated agents and workflows.
---

# Neuron Cloud in Laravel

`neuron-core/neuron-cloud-laravel` is the Laravel bridge of the Neuron Cloud SDK. Installed with an API key, it binds the SDK's `NeuronCloud` in the container. From there each capability is one more step: tracing is one environment variable, the evaluation output one line of `evaluation.php`, and a dataset of the platform one line of an evaluator. Orchestration (durable runs that the platform's timers and retries drive) is the advanced stage, for the developers who ask for it.

It stands on two other skills. **neuron-laravel-integration** lays the Laravel foundations of Neuron: agents built by the container, `neuron:evaluate`, the evaluators, the tests. **neuron-cloud** says what the SDK is, the same stages in plain PHP, and what reaches the platform. This skill only adds what is Laravel-shaped about the cloud.

## Which Stage a Request Gets

| Stage | What the developer gets | What you wire |
|---|---|---|
| **0. The service** | `NeuronCloud` in the container, injectable anywhere | The package and `NEURON_CLOUD_API_KEY` |
| **1. Observability** | A trace of every run of their agents and workflows | `NEURON_CLOUD_TRACING=true`, once the developer has said what content may leave |
| **2. Evaluation** | The results of `neuron:evaluate` on the platform, and evaluators that run on datasets it keeps | One line of `evaluation.php`; `dataset()` in an evaluator |
| **3. Orchestration**, advanced | Runs that survive a crash, a deploy or a wait of days; timers, retries and scheduled starts the platform drives | Definitions, the store, two queues, the endpoint, the sync, controllers: [references/orchestration.md](references/orchestration.md) |

- **Stage 0 comes first, when it is missing.** It needs the API key and nothing else.
- **A request that names no stage** ("add Neuron Cloud", "connect this app to Neuron Cloud") gets stages 0 and 1, then stops. End the answer by naming evaluation as the next step: the results of `neuron:evaluate` sent to the platform, and evaluators run on the datasets it keeps.
- **A request that names a stage gets stage 0 and that stage.** Tracing, monitoring or observability is stage 1. Evaluation results, an evaluation output, a dataset or evaluations reported from CI is stage 2. Each stage stands on stage 0 alone: a request for evaluation does not turn tracing on. Say in one line which earlier stage is not wired yet, and leave it unwired.
- **Orchestration is wired only when the developer asks for it**: durable runs, timers, retries, scheduled starts, runs that survive a crash, a deploy or a wait of days, runs started or answered through `$cloud->workflow()`, or one of its pieces by name. Read [references/orchestration.md](references/orchestration.md) then, and not before.
- **The signing key, the store binding, the queues and their workers, the endpoint and `neuron-cloud:sync` belong to orchestration.** Never add them for stages 0 to 2, and never present them as requirements of those stages.
- **A developer who asks for everything gets every stage**, in this order.

Check where the application stands first, and build on what is there:

```bash
grep -s 'neuron-cloud-laravel' composer.json                                   # stage 0: the package
grep -lsE "^NEURON_CLOUD_API_KEY=[\"']?[^[:space:]#\"']" .env .env.evaluation  # stage 0: the files that hold a key, not the key
grep -sE '^NEURON_CLOUD_TRACING' .env .env.evaluation                          # stage 1
grep -rlsE 'CloudOutput|->dataset\(' evaluation.php app/Neuron/Evaluators      # stage 2
grep -sA4 "'definitions'" config/neuron-cloud.php                              # orchestration: a list that is not empty
```

## Stage 0: The Service

**1. Install.** PHP 8.2+, Laravel 12.41+ or 13. The service provider is discovered.

```bash
composer require neuron-core/neuron-cloud-laravel
```

**2. The API key**, in `.env` and in the environment of every process that runs agents: web, queue workers, scheduler.

```dotenv
# From the dashboard of Neuron Cloud: it authenticates what the application sends
NEURON_CLOUD_API_KEY=
```

The developer pastes the key: never invent one, and never commit it. Add the variable with no value to `.env.example`.

**3. Nothing to bind.** The package binds `NeuronCloud` as a lazy singleton, built from `config/neuron-cloud.php` the first time something asks for it. Inject it wherever it is needed: the constructor or a method of a controller, a job, a command, a listener, an evaluator. Where nothing injects, `app(NeuronCloud::class)` returns the same one.

```php
use NeuronCore\Cloud\NeuronCloud;

public function __construct(protected NeuronCloud $cloud)
{
}
```

- **Never build your own** with `new NeuronCloud(...)`: it ignores the config, `NEURON_CLOUD_PLATFORM_URL` and the `neuron-cloud.http` binding of tests.
- **Without the key it cannot be built.** Whatever asks for it gets the SDK's `CloudException` with the reason `not_configured`, whose message names `NEURON_CLOUD_API_KEY`. Until something asks, an application with no key runs as before.
- **Publish the config only when you need one of its keys**, such as the tracing attributes: `php artisan vendor:publish --tag=neuron-cloud-config`. The environment variables of stages 1 and 2 work without it.
- **The package also registers `POST /neuron-cloud` and `php artisan neuron-cloud:sync`.** They belong to orchestration. Until it is wired, the endpoint refuses what reaches it: `503 unavailable` without the API key, with the `not_configured` exception handed to the application's exception handler, and `401 invalid_signature` without the signing key. The platform calls it once a sync has published its URL, or when an owner types the URL to request a sync from the dashboard.
- **`env()` only inside `config/*.php`**, as everywhere in Laravel: read the keys of the package with `config()`.

## Stage 1: Observability

**Ask the developer first.** Tracing sends what the application works on:

- **The names and IDs of every run**, always: the class, the run ID, and the thread ID of an agent as `gen_ai.conversation.id`. A thread named after its user, as the guide's `user-{id}-{uuid}`, carries the user's ID.
- **The message and the stack trace of every exception**, always.
- **Prompts, completions, tool arguments and tool results**, unless `NEURON_CLOUD_TRACING_CAPTURE_CONTENT=false`.

Ask which they want before turning tracing on, and say why when the agents handle personal, financial, medical or confidential data. Write their answer in `.env`. Never decide it silently: when you cannot ask, set the variable to `false` and say so in your answer. Exception messages cannot be held back: where even those must not leave the application, leave tracing off.

**Say what the switch covers.** It traces every agent and workflow the container builds, not only the one a request names: there is no switch per class. Say so when the developer asked about one agent.

```dotenv
NEURON_CLOUD_TRACING=true
NEURON_CLOUD_TRACING_CAPTURE_CONTENT=true    # the developer's answer; false keeps prompts, completions and tool data in the application
```

**Keep the test suite off the platform**, in the same step. Laravel's tests read `.env` (or `.env.testing`) for every variable `phpunit.xml` does not set: with tracing on there, every agent a test takes from the container and runs sends its trace with the developer's key.

```xml
<!-- phpunit.xml, in <php> -->
<env name="NEURON_CLOUD_TRACING" value="false"/>
<env name="NEURON_CLOUD_API_KEY" value="test-key"/>
```

That is all for the agents the container builds: the package gives every agent and workflow the container builds a listener, which takes the SDK's `$cloud->tracer()` at its first run, and each run is sent to the platform as one OpenTelemetry trace when its segment ends.

| Run | Traced | The run's span |
|---|---|---|
| An agent or a workflow the container builds: injected in a controller, a job, a command or an evaluator, or taken from `app()`, then run with `chat()`, `stream()`, `run()` or `events()` | By the package, nothing to write | Named after its class: `invoke_agent SupportAgent` |
| One built with `make()` or `new` | Once it subscribes the tracer (below) | Named after its class |
| An object bound with `instance()` | Only with the listener it got if the container built it before | Named after its class |
| A segment the SDK executes (orchestration) | By the SDK, once | Named after its definition |

An agent the container never sees subscribes the tracer itself, under the setting the package reads:

```php
use App\Neuron\Agents\ReportAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronCore\Cloud\NeuronCloud;

$agent = ReportAgent::make();
if (config('neuron-cloud.tracing.enabled')) {
    // With tracing off the service is never asked for: an application with no key builds the agent as before.
    $agent->subscribe(ObservabilityEvent::class, app(NeuronCloud::class)->tracer());
}
$agent->for("report-{$userId}")->chat(new UserMessage($question));
```

Attributes stamped on every span (a release, a region) need the published config:

```php
// config/neuron-cloud.php: the keys behind the two variables
'tracing' => [
    'enabled' => env('NEURON_CLOUD_TRACING', false),
    'capture_content' => env('NEURON_CLOUD_TRACING_CAPTURE_CONTENT', true),
    'attributes' => ['service.version' => '2026.10'],
],
```

- **A trace is the run.** It has a span for the run and one for each node, inference (`chat <model>`), tool call (`execute_tool <tool>`) and retrieval. The thread is its `gen_ai.conversation.id`, and a run that suspends and resumes adds its spans to the same trace. An agent whose tool runs another agent sends a trace for each, when the inner agent comes from the container too or subscribes the tracer.
- **Set the variables in the environment of the workers too**, and restart them (`php artisan queue:restart`): a worker reads its config once, and the agents your jobs run are traced only where it turns tracing on. With `config:cache`, cache again.
- **Tracing never stops the application.** With tracing on, the container builds every agent and workflow without asking for `NeuronCloud`: the package's listener asks for it at the agent's first run. Where it cannot be built then (no API key, a `definitions` entry the SDK refuses, an `extend()` or a binding that throws: any exception), the agent runs untraced. The first such exception goes to the application's exception handler, once per process: once per queue or Octane worker, once per request under PHP-FPM. Where no traces arrive, look in the log for `not_configured`, which names `NEURON_CLOUD_API_KEY`, or `invalid_request`. Each agent asks once, at its first run, so tracing starts as soon as the key is set: an agent built before it and run after it is traced. An agent whose first run could not build the service stays untraced for its life, its later runs included: a singleton until its worker restarts, a scoped one until its request or job ends. With tracing off, the service is never asked for and no key is needed.
- **The guarded `tracer()` line can stay in every environment.** With tracing off it never asks for the service, so it needs no key. It is not best effort: with tracing on and no key, `app(NeuronCloud::class)` throws `not_configured`. Only the package's own listener catches it.
- **Subscribe the tracer only to what the container did not build.** On an agent the container built, it is a second listener and every run is sent twice.
- **An agent the container built cannot be serialized while tracing is on**: its listener is a closure. A job that holds one fails to dispatch ("Serialization of 'Closure' is not allowed"). Queue the input and take the agent in `handle()`, as the guide's jobs do.
- **Never call `enableTracing()` yourself, and never build `CloudListener` with `new`**: the setting does the first, and the constructor of the second takes a class internal to the SDK.
- **`attributes` are the same for every run.** There is no per-run attribute.
- **It is best effort.** A trace is posted by the process that ran the segment, with 3 seconds at most: in a web request, one call to the platform when the segment ends. A trace the platform did not take is written to PHP's error log and dropped; it never changes the run.
- **A config file published before these keys existed** has no `tracing` key: the defaults of the package apply and the two variables work as they are. Add the key only to set `attributes`.
- **Laravel events are another thing.** The `LaravelEventDispatcher` bridge of **neuron-laravel-integration** ("Observability") forwards Neuron's events to your own listeners and logs; it works beside tracing and needs no change.

When the request named no stage, stop here and name evaluation as the next step.

## Stage 2: Evaluation

The evaluation command, `evaluation.php` and the evaluators are those of **neuron-laravel-integration** ("The evaluation command" and "Evaluation"). This stage adds two things, and each needs the API key alone: no queue, no store, no signing key, no sync.

### Evaluation Output

`CloudOutput` sends the results of `php artisan neuron:evaluate` to the platform. The package binds it in the container, built from its own `NeuronCloud`: list the class in `evaluation.php`.

```php
// evaluation.php, at the project root
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronCore\Cloud\Evaluation\CloudOutput;

return [
    'output' => array_filter([
        ConsoleOutput::class,
        new JsonOutput(storage_path('logs/evaluation.json')),
        // Built by the container once the evaluators have run. Listed only where the API key is set.
        config('neuron-cloud.api_key') ? CloudOutput::class : null,
    ]),
    'cache' => ['path' => storage_path('framework/cache/evaluation')],
];
```

```dotenv
# .env.evaluation, and the variables of the CI job
NEURON_CLOUD_API_KEY=
NEURON_CLOUD_EVALUATION_ENVIRONMENT=ci   # where the evaluation ran: ci, local, a branch
```

- **The key goes in `.env.evaluation`**: `neuron:evaluate --env=evaluation` reads that file, not `.env`.
- **Keep the guard.** `neuron:evaluate` builds every output once its evaluators have run. Without the key the container cannot build this one: the command exits 1 and prints no result at all, the console's included.
- **The container must be the resolver.** `neuron:evaluate` passes it; with `vendor/bin/neuron evaluation`, `evaluation.php` needs the `'resolver' => fn (string $class): object => app($class)` entry of the guide.
- **`NEURON_CLOUD_EVALUATION_ENVIRONMENT`** is sent with the results. `app()->environment()` is `evaluation` on every machine, which says nothing.
- **Inputs and outputs of every dataset item are sent**, with the scores and the failed assertions. It is best effort: a platform that is away is logged, the other outputs still print and the exit code is what the assertions decide.
- **Evaluators keep calling the agent directly**, on in-memory stores, as the guide builds them. Once agents are definitions (orchestration), still never evaluate through `$cloud->workflow()`: every item would be announced to the platform as a real run.
- **Evaluators are traced when `.env.evaluation` turns tracing on.** The agent an evaluator receives is built by the container, so with `NEURON_CLOUD_TRACING=true` there, every item's run sends its trace, under the same content rule as stage 1. Without the key there, its agent runs untraced, as in stage 1.
- **Never build `CloudOutput` with `new`**: its constructor takes a class internal to the SDK.

### Evaluation Datasets

An evaluator runs on a dataset kept on the platform when its `getDataset()` returns `$this->cloud->dataset($slug)`. `neuron:evaluate` builds evaluators through the container, so the evaluator takes the package's `NeuronCloud` in its constructor, beside the agent. It is the evaluator of **neuron-laravel-integration** ("Evaluation") with its JSON file replaced:

```php
namespace App\Neuron\Evaluators;

use App\Neuron\Agents\SupportAgent;
use App\Neuron\Tools\LookupOrder;
use App\Neuron\Tools\RefundOrder;
use Illuminate\Support\Str;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronCore\Cloud\NeuronCloud;

class RefundRequestEvaluator extends BaseEvaluator
{
    public function __construct(protected SupportAgent $agent, protected NeuronCloud $cloud)
    {
        parent::__construct();

        $this->agent->setMessageStore(new InMemoryMessageStore())->setPersistence(new InMemoryPersistence());
    }

    public function cacheDependencies(): array
    {
        return [SupportAgent::class, LookupOrder::class, RefundOrder::class];
    }

    public function getDataset(): DatasetInterface
    {
        return $this->cloud->dataset('refund-requests');   // the slug of the dataset on the platform
    }

    public function run(array $datasetItem): mixed
    {
        return $this->agent->for("user-{$datasetItem['input']['customer']}-".Str::uuid())
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

- **An item is an array with the `input`, the `expected_output` and the `metadata` stored on the platform**, each whatever JSON was stored there, null when the item has no expected output or metadata, and the `dataset_item_id` the platform uses to compare the item from one evaluation to the next; only the active items are sent. Ask the developer what an item holds, or read one, before writing `run()` and `evaluate()`, and read the keys you need: never spread the item into named arguments. The customers and orders an item names must exist in the evaluation database, as for a JSON dataset.
- **Nothing to bind.** The container injects the package's singleton, built from `config/neuron-cloud.php`: its API key, its platform URL and, in tests, the `neuron-cloud.http` binding. Never build a `NeuronCloud` in an evaluator.
- **The key goes in `.env.evaluation`**, as for the output. Without it the container cannot build the evaluator: `neuron:evaluate` reports it as an evaluator that could not run, with the message that names `NEURON_CLOUD_API_KEY`, runs the others and exits 1. No guard is needed, and none would help: without the platform the evaluator has nothing to run on.
- **The items are read once per evaluator**, by the command's own process, before `--concurrency` forks: the child hooks of the command need nothing more. With tracing off the children never call the platform; with tracing on each child posts the traces of the items it runs.
- **A dataset that cannot be read stops its evaluator** in the same way: an unknown slug, a refused key, no answer within 15 seconds. An empty dataset runs nothing, and nothing fails.
- **With `vendor/bin/neuron evaluation`**, the `resolver` entry of the guide's `evaluation.php` builds the evaluator through the container too.
- **`--cache` keys an item by its content and its `dataset_item_id`**: an item changed on the platform or imported again runs again. Keep `cacheDependencies()` for the files `run()` depends on.

## Testing

Tests of stages 1 and 2 keep the suite away from the platform with the two `phpunit.xml` lines of stage 1, and give the package the SDK's `FakePlatform` where a test needs the platform. Orchestration has its own tests: [references/orchestration.md](references/orchestration.md) ("Testing").

- **Turn tracing off in `phpunit.xml`.** Otherwise, with `NEURON_CLOUD_TRACING=true` in the `.env` of local development, every agent a test takes from the container and runs sends its trace to the platform with the developer's key.
- **A test key** lets the code that injects `NeuronCloud` run. With tracing off nothing is sent.
- **Bind `FakePlatform` as `neuron-cloud.http`, and set the config, before `NeuronCloud` is first resolved**: the package builds the service once, with what is there then. `FakePlatform` answers as the platform does. `assertTraced()` and `assertEvaluated()` look for the traces and the evaluation results it took in. It answers the read of a dataset with the items `keepDataset()` gave it, and 404 to a slug it was not given. What it refused for `failNext()` is not counted.

A test of tracing turns it on in the config, takes the agent from the container and fakes its model:

```php
use App\Neuron\Agents\SupportAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\Cloud\Testing\FakePlatform;

public function test_a_conversation_is_sent_as_one_trace(): void
{
    $platform = new FakePlatform();
    $this->app->instance('neuron-cloud.http', $platform);
    config(['neuron-cloud.api_key' => 'test-key', 'neuron-cloud.tracing.enabled' => true]);

    $this->app->make(SupportAgent::class)
        ->setAiProvider(new FakeAIProvider(new AssistantMessage('Your order ships tomorrow.')))
        ->for('thread-1')
        ->chat(new UserMessage('When does my order ship?'));

    $platform->assertTraced('invoke_agent SupportAgent');
}
```

- **`assertTraced()` takes the name of the run's span**, the one with no parent: `invoke_agent SupportAgent` for an agent, `invoke_workflow OrderWorkflow` for a workflow, after the short class name. A segment the SDK executes is named after its definition. It checks that the run was traced, not what its spans hold: the fake gives no reader for the spans. The trace is sent when the run ends, inside `chat()`, or when a stream is read to its end.
- **The agent is faked as in the guide**: `$this->app->make(SupportAgent::class)->setAiProvider(new FakeAIProvider(...))`, then `$this->app->instance(SupportAgent::class, ...)`. With tracing on, it keeps the listener the container gave it. An object built with `new` and bound with `instance()` gets none: Laravel hands out an instance without running the package's hook.
- **Without the key, a test of tracing runs its agents untraced**, and `not_configured` goes to the exception handler at the first run: `assertTraced()` then says the fake took in none.

An evaluator on a dataset of the platform runs on the items the fake keeps. Neuron's `EvaluatorRunner` runs it as `neuron:evaluate` does:

```php
use App\Neuron\Agents\SupportAgent;
use App\Neuron\Evaluators\RefundRequestEvaluator;
use DateTimeImmutable;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\Cloud\Evaluation\CloudOutput;
use NeuronCore\Cloud\Testing\FakePlatform;

public function test_the_refund_evaluator_passes_on_its_dataset(): void
{
    $platform = (new FakePlatform())->keepDataset('refund-requests', [
        ['input' => ['customer' => 1, 'question' => 'Can I get a refund for order 123?'], 'expected_output' => ['answer' => 'refunded']],
    ]);
    $this->app->instance('neuron-cloud.http', $platform);
    config(['neuron-cloud.api_key' => 'test-key']);
    $this->app->instance(SupportAgent::class, $this->app->make(SupportAgent::class)
        ->setAiProvider(new FakeAIProvider(new AssistantMessage('Order 123 was refunded.'))));

    $now = new DateTimeImmutable();
    $results = (new EvaluatorRunner())->run($this->app->make(RefundRequestEvaluator::class));
    $this->assertSame(1, $results->getPassedCount());

    // What the evaluation command hands the output once the evaluators have run
    $this->app->make(CloudOutput::class)->output(new EvaluationReport(
        [new EvaluatorReport(RefundRequestEvaluator::class, $results, $now, $now)],
        $now,
        $now,
    ));
    $platform->assertEvaluated(RefundRequestEvaluator::class);
}
```

- **`keepDataset()` takes the items as the platform holds them**, each `['input' => ..., 'expected_output' => ..., 'metadata' => ...]`, and the evaluator reads them in that order. The fake adds the `dataset_item_id` the platform sends, the position of the item from 1 unless you give one.
- **A slug the fake does not keep** is answered 404, as the platform answers it: `load()` throws a `CloudException`, and the runner lets it through. So does a read after `$platform->failNext(1, 503)`: the platform away when the dataset is read.
- **`FakeAIProvider` answers in order**, one message per inference: give it one answer per dataset item.
- **`assertEvaluated()` takes the class of the evaluator**, as the runner names it. Whether the items passed is the runner's to say: `getPassedCount()`, as above.
- **`Http::fake()` sees nothing of this**: the SDK and the providers use Neuron's HTTP client.

## Next: Orchestration

Stop after the stage the request named. Read [references/orchestration.md](references/orchestration.md) only when the developer asks for durable runs, timers, retries, scheduled starts, or runs that survive a crash, a deploy or a wait of days. It wires the definitions in `config/neuron-cloud.php`, the store binding, the two queues and their workers, the endpoint, the error mapping, the deploy with `neuron-cloud:sync`, the agent's hooks, the controllers that go through `$cloud->workflow()`, and `InvocationSettled`. Stages 0 to 2 stay as they are.

## Pitfalls

- `new NeuronCloud(...)` in application code or an evaluator → it ignores the config, `NEURON_CLOUD_PLATFORM_URL` and the `neuron-cloud.http` binding of tests. Inject the package's.
- `NEURON_CLOUD_TRACING=true` where `NEURON_CLOUD_API_KEY` is not set → the agents work and nothing is traced; the log holds one `not_configured` per process, and a hand-written `app(NeuronCloud::class)->tracer()` throws it. Set the key in the same environment.
- `NEURON_CLOUD_TRACING=true` in the web environment only → the agents your jobs run are not traced. Set it for the workers and restart them.
- Tracing turned on with sensitive prompts and no decision about content → they reach the platform. Ask the developer; exception messages are sent either way.
- `$cloud->tracer()` subscribed to an agent the container built → every run is sent twice. Subscribe it only to agents built with `make()` or `new`.
- A job whose constructor takes a container-built agent → with tracing on it fails to dispatch: the agent holds a closure. Take the agent in `handle()`.
- `app(NeuronCloud::class)->tracer()` with no `config('neuron-cloud.tracing.enabled')` guard → it throws `not_configured` on every machine without the key, tracing off or not. Keep the guard.
- `enableTracing()` or a `CloudListener` wired by hand in a provider → done twice, or done where a worker never sees it. Set `NEURON_CLOUD_TRACING`.
- `NEURON_CLOUD_TRACING=true` in `.env` and nothing in `phpunit.xml` → the test suite sends traces with the developer's key. Set it to `false` there.
- `CloudOutput::class` unguarded in `evaluation.php` → on a machine without the API key the evaluators run, then the command fails and the whole report is lost. Keep the `config('neuron-cloud.api_key')` guard.
- Evaluating through `$cloud->workflow()` → the platform lists every dataset item as a run.
- `Http::fake()` to fake the platform → the real one is called. Bind `FakePlatform` as `neuron-cloud.http`.
- A signing key, definitions, a store binding, queue workers or `neuron-cloud:sync` added for tracing or evaluation → work the developer did not ask for, and none of it is needed. They belong to orchestration.

## Related

- **neuron-cloud** — what the SDK is, the same stages in plain PHP, what reaches the platform, the rules of a run.
- **neuron-laravel-integration** — the Laravel foundations this skill builds on: layout, provider, tables, agent, controllers, `neuron:evaluate`, tests.
- **neuron-workflow** and **neuron-agent** — writing the workflows and agents themselves.
- **neuron-streaming** and **neuron-frontend-integration** — adapters, channels, AG-UI and Vercel clients.
- **neuron-tool-approval** — approvals, decision maps and the approval UI.
- **neuron-evaluation** — evaluators, datasets and output drivers.
- **neuron-monitoring** — Neuron's events and listeners.
- [references/orchestration.md](references/orchestration.md) — the advanced stage: definitions, store, queues, endpoint, deploy, controllers, tests.
- [references/invocation-settled.md](references/invocation-settled.md) — the complete listener of `InvocationSettled` and what its reports can tell.
- `vendor/neuron-core/neuron-cloud-laravel/README.md` — the queues in depth (SQS, key rotation, replaying failed jobs) and every case of `InvocationSettled`.
