# Neuron IQ Platform PHP SDK

Connect the agents and workflows of a [Neuron AI](https://github.com/neuron-core/neuron-ai) application to IQ. Your agent and workflow classes stay ordinary Neuron code: they know nothing about the SDK.

The SDK gives three things. Adopt them in this order, each when you want it:

| Stage | What you get | What reaches IQ |
|---|---|---|
| [Tracing](#2-tracing) | Every run of an agent or a workflow as an OpenTelemetry trace: its nodes, inferences, tool calls and retrievals | The names of nodes, tools and models, token counts, timings, the thread ID of an agent, and the message and the stack trace of every exception. Prompts, completions, tool arguments and tool results too, unless you turn content capture off |
| [Evaluations](#3-evaluations) | The results of `vendor/bin/neuron evaluation` on the platform, and evaluators that run on datasets the platform keeps | The input, the output, the scores and the failed assertions of every dataset item. Reading a dataset sends nothing but its slug |
| [Orchestration](#4-orchestration), for advanced use | Runs that survive a crash, a deploy or a wait of days: IQ fires the timers your workflows wait on, retries a run after a failure or a crashed worker, and starts runs on a schedule | Coordination data only: definition names, workflow and run IDs, statuses, wake-up times and the class of an exception. Workflow state, inputs, prompts, answers and results stay in your application; with tracing on, the trace of each run carries what tracing sends |

All three use one service, built with your API key ([section 1](#1-the-service)). Nothing reaches the platform until a stage sends it.

## Requirements

- PHP 8.1 or later
- `neuron-core/neuron-ai` 4.1.26 or later
- An IQ API key

Tracing and evaluations need nothing more. Orchestration adds a signing key, a shared store, a durable queue and a public HTTPS URL: [section 4](#4-orchestration) lists them.

## Start With One Prompt

```
You are going to set up the integration with the IQ platform in this project, and you must follow three steps in order.

- First: install the package: check composer.json, if neuron-core/neuron-ai is
already on the version 4.x. If it is run `composer update neuron-core/neuron-ai` to get the latest
versionm if it's on 3.x or lower stop the installation process and suggest to update Neuron before moving forward;

- Second: install the skills for the coding agent that ship
with the package by running `npx --yes skills add ./vendor/neuron-core/iq-php-sdk/skills -y` from
the project root. Add this command to `post-update-cmd` in the composer.json file, so they
stay updated whenever the package is updated through Composer;

- Third: work out what kind of app this is. Look at composer.json
and the project structure to see whether it is an application based on Laravel, Symfony, or legacy PHP,
then activate the matching skill: iq-php, iq-laravel, or iq-symfony.
Enable tracing first, then ask if you should go ahead with Evaluation + Dataset, and Orchestration.
```

## Installation

```bash
composer require neuron-core/iq-php-sdk
```

Using Laravel? Install `neuron-core/iq-laravel` instead: it binds the service from a config file, traces every agent and workflow the container builds once `IQ_TRACING=true`, binds the evaluation output, and for orchestration runs the queue jobs, registers the endpoint and adds the deploy command.

Coding agents get two skills with the package, `iq-php` and `iq-laravel`, which walk the same stages:

```bash
npx skills add ./vendor/neuron-core/iq-php-sdk/skills -y
```

## 1. The service

Every stage uses one `IQ`, built with the API key of your IQ project. Bind it once in your application's container, as a shared service, and take it from there wherever a later stage needs it. With PHP-DI:

```php
// config/container.php: the definitions of the application's PHP-DI container
use NeuronCore\IQ\IQ;

return [
    // One service for the whole application, built the first time something asks for it
    IQ::class => fn (): IQ => new IQ(
        getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
    ),
];
```

Without a container, one bootstrap file builds it, and an entry script that uses it requires it once:

```php
// bootstrap.php
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

return new IQ(
    getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
);
```

- **The API key is all it needs** for tracing and evaluations. Building the service sends nothing.
- **One service per process, shared.** Every later stage takes it from the container or from `bootstrap.php`. An `IQ` built inside a class or per request does not have the settings of the shared one, such as `enableTracing()`.
- **The `throw` names the missing variable.** `getenv()` gives the constructor `false` for an unset variable: a `TypeError` in a file that declares `strict_types`, and without it a service with an empty key, whose every request the platform refuses.
- **Read the key as your application reads its other settings.** The snippets use `getenv()`, which sees the environment of the process.

## 2. Tracing

Tracing is two things: `enableTracing()` where the service is built, and a listener from `$iq->tracer()` subscribed to each agent or workflow your application runs with `chat()`, `stream()`, `run()` or `events()`, through one small class of your application. It is best effort: with tracing off the service is never asked for, and with tracing on and a service that cannot be built, the agent runs untraced and the cause is logged. In the container of section 1:

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
            // false keeps prompts, completions, tool arguments and tool results in your application
            $iq->enableTracing(captureContent: false);
        }

        return $iq;
    },

    // One entry like this for every agent and workflow: the closure is called only where tracing is on
    SupportAgent::class => fn (ContainerInterface $container): SupportAgent => Tracing::subscribe(
        SupportAgent::make(),
        fn (): IQ => $container->get(IQ::class),
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

```php
// Wherever the application runs the agent: each run is sent as one trace when it ends
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\UserMessage;

$container->get(SupportAgent::class)->for($threadId)->chat(new UserMessage($prompt));
```

Without a container, the same `if` goes in `bootstrap.php`, and an entry script that builds agents hands `Tracing::subscribe()` a closure that requires it:

```php
// bootstrap.php
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

$iq = new IQ(
    getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
);

if (getenv('IQ_TRACING') === 'true') {
    $iq->enableTracing(captureContent: false);
}

return $iq;
```

```php
// public/index.php, or any entry script that builds agents
use App\Agents\SupportAgent;
use App\Tracing;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/../vendor/autoload.php';

// The service of bootstrap.php, built the first time a traced agent asks for it, then shared
$iq = function (): IQ {
    static $service;
    return $service ??= require __DIR__ . '/../bootstrap.php';
};

$agent = Tracing::subscribe(SupportAgent::make(), $iq);
$agent->for($threadId)->chat(new UserMessage($prompt));
```

Set `IQ_TRACING=true`, with the key, in the environment of every process that runs agents: web requests, CLI scripts, workers. With tracing off, `Tracing::subscribe()` never asks for the service, so a machine, a CI job or a test suite without the key builds its agents and sends nothing. With tracing on and a service that cannot be built, for want of the key or for anything else its entry throws, the agent is built and runs untraced, and the first failure of the process goes to `error_log()` with its cause: `RuntimeException: Set IQ_API_KEY: …`. Once per process is once per request under PHP-FPM, and once per worker in a long-lived one. What asks for the service itself, `$container->get(IQ::class)`, `bootstrap.php` or an evaluator that takes `IQ`, still throws.

`Tracing::subscribe()` is a static method, not a closure the entries share, so that PHP-DI can compile the container: with `enableCompilation()` it refuses a closure that imports a variable, with `use` or as an arrow function that captures one. The closure each entry hands the method captures only the entry's own parameter, and compiles; and since the method takes a closure, not the container, an application with no container calls it too. PHP-DI hands on what a factory definition throws as it is, compiled or not. At an injected property or an array entry it may wrap it in a `DependencyException` that keeps it as `previous`, as the entry is defined and the container compiled or not: the class logs the innermost exception, so the line names the cause either way.

What a trace holds, and when it is sent:

- **A trace is one run.** Its spans follow the OpenTelemetry conventions for generative AI: `invoke_agent SupportAgent` or `invoke_workflow <class>`, named after the short name of the class, then a span for each node, `chat <model>` for each inference, `execute_tool <tool>` for each tool call and `retrieval` for RAG. The thread ID of an agent is `gen_ai.conversation.id`.
- **Always**: the names of nodes, tools, models and the provider, token counts, timings, and for every exception its class, its message and its stack trace. **With content capture**, which is on unless `enableTracing(captureContent: false)`: the messages given to and returned by the model, and the arguments and the result of every tool call, each cut at 4,000 characters. Choose before you turn tracing on. Exception messages are sent either way: where even those must not leave your application, leave tracing off.
- **`enableTracing(attributes: ['deployment.environment' => 'production'])`** stamps the same attributes on every span of the process. `OTEL_SERVICE_NAME` names the service of every trace.
- **It is best effort.** The process that ran the agent posts the trace when the run ends or is interrupted, with a 3-second timeout: in a web request, that is one call to the platform before `chat()` returns, or when the stream ends. A trace the platform did not take is logged with `error_log()` and dropped. It never changes the run or what the caller receives.

How the listener behaves:

- **It takes the settings of `enableTracing()`** as they are when `tracer()` is called. Each run it hears is sent as one trace when its segment ends.
- **It sends nothing while tracing is off.** A listener got before `enableTracing()` was called handles no event, so a line that subscribes it sends nothing where tracing is off. The line still asks for the service, which needs the key: where the key may be missing, ask for the service only when `IQ_TRACING=true`, as `Tracing::subscribe()` does. The listener needs the API key and nothing else: no store, no queue, no registered definition.
- **One listener may serve several agents.** It keeps each run apart and sends the trace of a run when that run ends, without touching the others: an agent whose tool runs another agent, a node that runs an agent, two streams read in turns. Every call to `tracer()` returns a new listener, and a copy made with `for()` keeps the listener of the agent it copies.
- **Only an agent that carries a listener is traced.** One built with `make()` or `new` outside the container, in a script or an evaluator, goes through `Tracing::subscribe()` itself.
- **A registered definition is traced once, by the SDK.** Once `enableTracing()` was called, every segment the SDK executes for [orchestration](#4-orchestration), through `$iq->workflow()` or in a worker, is traced under its definition name, such as `invoke_workflow order-fulfilment`, even when the factory of the definition, or a `configure()` callback, gave the workflow a `tracer()` listener too: that listener leaves the run to the SDK and sends nothing for it.

With `neuron-core/iq-laravel`, `IQ_TRACING=true` and the API key are all tracing needs: the bridge gives every agent and workflow its container builds a listener, which takes `tracer()` at its first run. An agent built with its static `make()` or with `new` subscribes `app(IQ::class)->tracer()` itself under `if (config('iq.tracing.enabled'))`, and that hand-written line is not best effort: with tracing on and no key, it throws `not_configured`. One bound with `instance()` is handed out as it was bound, with only the listeners it had then. The bridge's own tracing is best effort, as `Tracing::subscribe()` is, and never stops the application: the container builds every agent and workflow without asking for `IQ`, and where `IQ` cannot be built at the first run, for want of the API key, for a config the SDK refuses or for any other exception, the agent or the workflow works untraced and the exception goes to the application's exception handler once per process. The bridge's README has the details.

## 3. Evaluations

Two pieces, each wired on its own: the results of every evaluation run, and evaluators that run on a dataset the platform keeps. Both need the API key and nothing else.

### The evaluation output

`evaluationOutput()` returns an output driver for Neuron's evaluations. List it in `evaluation.php`, the file `vendor/bin/neuron evaluation` reads from the project root, and make the application's container the `resolver`:

```php
// evaluation.php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronCore\IQ\IQ;

$container = require __DIR__ . '/bootstrap/container.php';   // however your application builds its container

return [
    'output' => array_filter([
        ConsoleOutput::class,
        // Listed only where the key is set: a machine without it still evaluates
        getenv('IQ_API_KEY') ? $container->get(IQ::class)->evaluationOutput(environment: getenv('CI') ? 'ci' : 'local') : null,
    ]),
    // Builds every evaluator, and every output listed by its class name
    'resolver' => fn (string $class): object => $container->get($class),
];
```

- **One message per evaluator** is sent when all evaluators are done: its class, its summary, and for every dataset item the input, the output, the scores and the failed assertions. Inputs and outputs do leave your application: that is the point of this output.
- **`environment`** names where the evaluation ran, such as `ci`, or is null.
- **It is best effort too.** A message the platform did not take is logged, the other outputs still run, and the exit code of the command is what the assertions decide.
- **Never build `IQOutput` with `new`**: get it from `evaluationOutput()`.
- **The branch, the commit and the page of the build** are sent with every evaluator. GitHub Actions and GitLab CI need nothing, and elsewhere out of CI they come from git. Any other CI sets `NEURON_EVALUATION_BRANCH`, `NEURON_EVALUATION_BASE_BRANCH`, `NEURON_EVALUATION_COMMIT`, `NEURON_EVALUATION_CI_URL` and `NEURON_EVALUATION_SUITE_ID`. A pull request is compared with the branch it targets, and any other run with its own branch.

### Failing a build on what is new

`vendor/bin/neuron evaluation` fails on any failed item, so a suite with a known failure stays red. `vendor/bin/iq-gate` asks the platform instead, which remembers the earlier runs of the branch, and fails the build only when something new broke:

```yaml
# GitHub Actions
- name: Evaluations
  env:
    IQ_API_KEY: ${{ secrets.IQ_API_KEY }}
  run: |
    vendor/bin/neuron evaluation evaluators || [ $? -eq 1 ]
    vendor/bin/iq-gate
```

The first line tolerates exit code 1, a failed item, and nothing else: a crash of the command stops the build there. Both commands need the API key, and on GitLab both must run in the same job.

- **It needs `IQ_API_KEY`**, and `IQ_PLATFORM_URL` for a platform at another address.
- **Exit code 0** means nothing new broke, **1** that something did or an evaluator could not run, and **2** that the platform gave no answer or the results of some evaluators did not arrive. Any exit code but 0 fails the step, so a build cannot pass with no results.
- **Turn on re-runs.** Judges disagree with themselves, so an item that fails for the first time can be noise. With `rerunFailed` on the runner in `evaluation.php`, an item that passed a re-run is reported and does not fail the build. An item nobody re-ran counts.
- **A run with nothing to compare passes**, such as the first run of a branch with no pull request, or a run answered from the cache. The report says why.
- **A pull request is compared with the branch it targets**, so re-running a build or pushing again does not turn a regression into a known failure. A build on `push` alone names no target: it compares with the last run of its branch, so a failure counts as new once.
- **`NEURON_EVALUATION_SUITE_ID` must be different for every build**, as the suite of a build is what the gate reads.
- **In a matrix, give each job a suite of its own and run the gate in it**: `NEURON_EVALUATION_SUITE_ID: ${{ github.run_id }}-${{ github.run_attempt }}-${{ matrix.php }}`. All jobs of a run share its ID otherwise, and a job whose results never arrived goes unnoticed.
- **Keep local runs apart from CI**, or a run on your machine becomes the baseline of the next pull request: `evaluationOutput(environment: getenv('CI') ? 'ci' : 'local')`.
- **An evaluator that stops testing fails the build**: one that has no items or makes no assertions where its baseline had verdicts counts as one that could not run.

### Datasets

An evaluator can run on a dataset kept on IQ instead of a JSON file. It receives `IQ` in its constructor, beside the agent it evaluates, and returns one of its datasets from `getDataset()`:

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

Each item is an array with the `input`, the `expected_output` and the `metadata` you stored on the platform, each any JSON value and the last two null when the item has none, and the `dataset_item_id` the platform uses to compare the item from one evaluation to the next. Read the keys you need: do not spread the item into named arguments.

Neuron's evaluation command builds every evaluator through a resolver. Make that resolver the container that holds your `IQ`:

- **Laravel, with `neuron-core/iq-laravel`**: there is nothing to wire. The container holds `IQ` as a singleton, and the `neuron:evaluate` command of Neuron's Laravel guide builds evaluators through it. For `vendor/bin/neuron evaluation`, add `'resolver' => fn (string $class): object => app($class)` to `evaluation.php`. Put `IQ_API_KEY` in the environment the evaluation reads: `.env.evaluation` when the command runs with `--env=evaluation`.
- **Another container**: the `resolver` of the output's `evaluation.php` above, `fn (string $class): object => $container->get($class)`. The container must build classes by name, as PHP-DI does, and hold the `IQ` of section 1.
- **No container**: `evaluation.php` takes the service from `bootstrap.php` and gives it to the evaluators that need it:

```php
// evaluation.php
use App\Agents\SupportAgent;
use App\Evaluators\RefundEvaluator;
use NeuronAI\Evaluation\Output\ConsoleOutput;

$iq = getenv('IQ_API_KEY') ? (require __DIR__ . '/bootstrap.php') : null;

return [
    'output' => array_filter([ConsoleOutput::class, $iq?->evaluationOutput(environment: 'ci')]),
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

What to expect:

- **A dataset needs the API key and nothing else**: no store, no queue, no signing key.
- **Each item runs on a copy of the agent**, bound to a thread of its own with `for()`. The agent the container injects carries the listener of its entry, so the runs of an evaluation are traced where the environment of the evaluation sets `IQ_TRACING=true`. The agent the resolver without a container builds is not, unless the resolver passes it through `Tracing::subscribe()`.
- **The items are read once per evaluator**, when Neuron loads the dataset: before the first item runs, and before `--concurrency` forks. Building the evaluator reads nothing.
- **A dataset that cannot be read stops its evaluator.** For an unknown slug, a refused API key, or no answer within 15 seconds, `load()` throws an `IQException`. Neuron's command then reports that evaluator as one that could not run, runs the others, and exits with 1. A missing API key has the same effect one step earlier, when the evaluator is built: the container throws, the bridge's included, and so does the resolver above.
- **Only the active items of a dataset are sent.** A dataset with none runs nothing.
- **Keep each item's `dataset_item_id`.** The platform ties every result to its item by that key, which is how it follows an edited item from one run to the next. A dataset class of your own that reshapes the items must carry the key along: a run on a platform dataset whose results come back with none gets a warning on its page.
- **`--cache` keys an item by its content and its `dataset_item_id`.** An item you change on the platform runs again; an unchanged one is served from the cache. An item imported again gets a new ID, so it runs again too.

### Sending a dataset file to the platform

A dataset you keep in a JSON file of the application goes to IQ in one command, so that your team curates it there and your evaluators read it with `dataset()`:

```bash
IQ_API_KEY=... vendor/bin/iq-dataset refund-requests tests/datasets/refunds.json --name="Refund requests"
```

It prints what the platform did, such as `refund-requests: created, 12 added, 0 updated, 0 unchanged`. In code, `$iq->uploadDataset('refund-requests', new JsonDataset($path), 'Refund requests')` does the same and returns those counts.

- **The file holds items in the shape of a dataset item**: a JSON array of objects, each with an `input` and, when it has them, an `expected_output` and `metadata`. The platform refuses a file with any other key and names the item that holds it.
- **The platform creates the dataset when it has none** under that slug, named after `--name` or after the slug. A slug holds lowercase letters, digits and single hyphens.
- **An item is matched by its input.** An item of the file whose input the dataset has replaces the expected output and the metadata of that item, and keeps its history of results; any other is added; an item of the dataset that the file lacks stays. Sending the same file again changes nothing. An item someone archived on the platform takes the file's changes and stays archived.
- **A file of more than 1,000 items goes in several requests.** One the platform refuses stops the rest, and the ones before it stay written.
- **It exits 0** when the platform took the items, **1** when it refused them or gave no answer, and **2** when it had nothing to send: no API key, no file, or a file that is not a JSON array. It reads the platform URL from `IQ_PLATFORM_URL` when it is set.

## 4. Orchestration

For advanced use, and only when you want it: tracing and evaluations need none of this. Your application keeps running every workflow in its own processes and keeps every run in its own store. IQ keeps a list of your runs and a clock: it fires the timers your workflows wait on, retries a run after a failure or a crashed worker, and starts runs on a schedule. For these runs only coordination data reaches it: definition names, workflow and run IDs, statuses, wake-up times and the class of an exception. A trace of each segment reaches it only with tracing on (section 2).

Orchestration needs, beside the API key:

- The signing key of your IQ project, `IQ_SIGNING_KEY`
- A workflow store every process shares: Neuron's `DatabasePersistence`, `EloquentPersistence` or `RedisPersistence`
- A durable queue with at least one worker
- A public HTTPS URL the platform can call

### How it works

A run executes in **segments**: from a start, an answer or a wake-up, to the next wait or to the end. Before a segment the SDK tells the platform "this run is starting"; after it, "this is the run now". When a timer or a retry comes due, the platform calls one signed URL of your application, the SDK puts that call on your queue, and a worker continues the run.

You wire five pieces:

| Piece | What it does |
|---|---|
| The service | `IQ`, built once per process from your API key and signing key |
| The dispatcher | Hands a message of the SDK to your queue |
| The endpoint | The URL the platform calls |
| The worker | Takes one message off the queue and gives it to the SDK |
| The sync | Publishes your workflows and the endpoint URL at the end of every deploy, or when the platform asks for it |

### The service

The service of section 1 grows: the signing key, the store, the dispatcher, the endpoint URL and the definitions. One bootstrap file, required by the endpoint, the worker, the deploy script and your application:

```php
// bootstrap.php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronCore\IQ\IQ;

require_once __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(getenv('DATABASE_DSN'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$redis = new Redis();
$redis->connect('127.0.0.1');

$iq = new IQ(
    apiKey: getenv('IQ_API_KEY') ?: throw new RuntimeException('Set IQ_API_KEY: the API key of the IQ project.'),
    signingKey: getenv('IQ_SIGNING_KEY') ?: null,   // only the endpoint needs it
);

if (getenv('IQ_TRACING') === 'true') {
    $iq->enableTracing(captureContent: false);   // before any workflow() or tracer()
}

return $iq
    ->setPersistence(fn () => new DatabasePersistence($pdo))
    ->setDispatcher(new RedisDispatcher($redis))
    ->setEndpointUrl('https://shop.example.com/iq.php')
    ->register('order-fulfilment', fn () => new OrderWorkflow())
    ->register('support-agent', fn () => new SupportAgent());
```

In a container, the entry of section 1 makes the same calls. Without one, an entry script that requires this file and builds traced agents hands `Tracing::subscribe()` the service it holds, `fn (): IQ => $iq`: the closure of section 2 would require the file a second time, with a second connection to the database and to Redis.

- **`register()`** gives a workflow or an agent the name the platform knows it by. The factory builds the class from nothing but the workflow ID: a worker has no request and no logged-in user.
- **`setPersistence()`** takes a closure that returns the store. `DatabasePersistence` needs Neuron's `workflow_store` table.
- **`setEndpointUrl()`** names the URL the platform calls: what the sync publishes.
- **The lease**, `register(..., leaseTimeout: 600)` by default, must be longer than your slowest node.

The `evaluation.php` of [section 3](#datasets) requires this file too, and with it opens the database and Redis. Where evaluations run without them, a CI job for one, its `$iq` line builds a service of its own with the API key alone, which is all evaluations need, and the rest of the file stays as it is:

```php
// evaluation.php, where evaluations run without the database or Redis
use NeuronCore\IQ\IQ;

$iq = getenv('IQ_API_KEY') ? new IQ(getenv('IQ_API_KEY')) : null;
```

### The dispatcher

The SDK never decides how your queue works. It hands each message to a dispatcher of yours:

```php
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

The queue must be durable and as trusted as your code: a queued message can hold serialized PHP.

### The endpoint

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

The SDK verifies the signature of every request and answers it by itself. Keep the route bare: no authentication, CSRF check or rate limiter in front of it.

### The worker

```php
// bin/worker.php
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;

$iq = require __DIR__ . '/../bootstrap.php';
$redis = new Redis();
$redis->connect('127.0.0.1');

while ([, $job] = $redis->blPop(['iq'], 0)) {
    $invocation = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

    try {
        $iq->invoke($invocation);
    } catch (IQException $e) {
        // Refused for good, or a message of yours past its use-by time: keep it for a person.
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;

        $redis->rPush($final || $spent ? 'iq:failed' : 'iq:later', $job);
    }
}
```

`invoke()` executes one segment, or posts one notice to the platform. It returns a `Report` for every message it dealt with, a failed segment included, and throws only when the message should be tried again later or kept aside. Never retry a segment yourself: the platform retries a failed run with a new message.

### The sync

At the end of every deploy, after the workers have restarted on the new code:

```php
// bin/sync.php
$iq = require __DIR__ . '/../bootstrap.php';

$iq->sync();
```

It publishes every registered definition and the endpoint URL of `setEndpointUrl()`; a URL given to `sync()` is published instead. The platform must be able to reach that URL over HTTPS.

The platform can also ask your endpoint for the same list, when someone requests a sync from its dashboard: the endpoint answers with exactly what `sync()` would post. Where `sync()` would refuse to publish (no endpoint URL set, an in-memory or file store, nothing registered), the endpoint refuses too, with `409 sync_refused`, and does not say why: run the sync from the deploy to see the reason.

### Starting and answering runs

Every start and every answer goes through `$iq->workflow()`. A workflow you run directly is never announced: the platform would not fire its timers or retry it.

```php
$order = $iq->workflow('order-fulfilment', "order:{$orderId}");

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
$thread = $iq->workflow('support-agent', $threadId);
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

`FakePlatform` stands in for IQ: give it to the service as its HTTP client. It takes traces and evaluation results in, as the platform does, and serves the datasets you give it. Neuron's `FakeAIProvider` answers for the model.

**Keep the test suite off the platform.** With `IQ_TRACING=true` in the environment the tests run in, every agent a test takes from the container of section 2 sends its trace with the developer's key:

```xml
<!-- phpunit.xml, in <php>: force="true" wins over what the shell exports -->
<env name="IQ_TRACING" value="false" force="true"/>
<env name="IQ_API_KEY" value="test-key" force="true"/>
```

With tracing off `Tracing::subscribe()` never asks for the service. The test key lets the code that takes it be built, an evaluator on a dataset for one, and building it sends nothing. A test of tracing that takes its agent from the container sets the variable itself, as `Tracing::subscribe()` reads it, with `putenv('IQ_TRACING=true')`, and puts it back in `tearDown()`, as the skill's [`testing.md`](skills/iq-php/references/testing.md) does. The tests below build their agents and their service themselves, and read no variable.

```php
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Testing\FakePlatform;

$platform = new FakePlatform();
$iq = (new IQ('test-key', httpClient: $platform))->enableTracing();

SupportAgent::make()
    ->setAiProvider(new FakeAIProvider(new AssistantMessage('Your refund is on its way.')))
    ->subscribe(ObservabilityEvent::class, $iq->tracer())
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
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Testing\FakePlatform;

$platform = (new FakePlatform())->keepDataset('refund-requests', [
    ['input' => ['question' => 'Can I get a refund?'], 'expected_output' => ['answer' => 'refund']],
]);
$iq = new IQ('test-key', httpClient: $platform);
$agent = SupportAgent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('Your refund is on its way.')));

$startedAt = new DateTimeImmutable();
$results = (new EvaluatorRunner())->run(new RefundEvaluator($agent, $iq));   // reads the dataset from the fake
$finishedAt = new DateTimeImmutable();

// What Neuron's evaluation command hands the outputs of evaluation.php
$iq->evaluationOutput(environment: 'test')->output(new EvaluationReport(
    [new EvaluatorReport(RefundEvaluator::class, $results, $startedAt, $finishedAt)],
    $startedAt,
    $finishedAt,
));

$platform->assertEvaluated(RefundEvaluator::class);
```

- **`assertTraced()`** takes the name of the parentless span of a trace: `invoke_agent <name>` for an agent, `invoke_workflow <name>` for a workflow. The name is the short class name for a run your application runs with `tracer()`, as in `invoke_agent SupportAgent`, and the definition name for a run the SDK executes, as in `invoke_workflow order-fulfilment` or `invoke_agent support-agent`.
- **`assertEvaluated()`** takes the class of the evaluator.
- **`keepDataset()`** answers the read of its slug with the items, in order, each as the platform answers it: `['dataset_item_id' => ..., 'input' => ..., 'expected_output' => ..., 'metadata' => ...]`, the ID the position of the item from 1 and the expected output and the metadata null where you give none, and no other key. A slug it was not given is answered 404, and `load()` throws, as against the platform.
- **`failNext()` comes first.** `$platform->failNext(1, 503)` plays a platform that is away for the next request, and an assertion counts no request it refused.

For orchestration, `FakeDispatcher` stands in for your queue:

```php
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Testing\FakeDispatcher;
use NeuronCore\IQ\Testing\FakePlatform;

$platform = new FakePlatform();
$queue = new FakeDispatcher();
$store = new InMemoryPersistence();

$iq = (new IQ('test-key', $platform->signingKey, httpClient: $platform))
    ->setPersistence(fn () => $store)
    ->setDispatcher($queue)
    ->register('order-fulfilment', fn () => new OrderWorkflow());

$iq->workflow('order-fulfilment', 'order:123')->start(new OrderPlaced('123'))->dispatch();
$queue->runAll($iq);   // what a worker does

$platform->assertReported('order:123', Outcome::Suspended);
```

`$platform->wake($iq, 'order:123')` sends the endpoint the platform's wake of that run, signed, and `$platform->deliver($iq, [...])` any other message. `assertAnnounced()`, `assertReported()` and `assertSynced()` read what the platform recorded, and `$queue->assertDispatched()` reads the queue.

## Further reading

- [`SDK_DESIGN.md`](SDK_DESIGN.md): the whole design, the public API, the guarantees you must keep, and what happens when things go wrong.
- [`PLATFORM_PROTOCOL.md`](PLATFORM_PROTOCOL.md): the protocol between the SDK and the platform.
- [`skills/`](skills): the skills for coding agents.

## License

MIT. See [LICENSE](LICENSE).
