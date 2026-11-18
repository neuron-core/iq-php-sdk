# Testing with IQ

`NeuronCore\IQ\Testing` ships two fakes:

| Fake | What it stands in for | Used by |
|---|---|---|
| `FakePlatform` | IQ, as the HTTP client of the service. It answers as the platform does, checks what it took in, keeps the datasets a test gives it, and plays the platform's side of the endpoint | Every stage |
| `FakeDispatcher` | The queue. It keeps what it is handed and runs it when the test says so | Orchestration only |

The model is faked with `FakeAIProvider` (**neuron-test**): the SDK never touches the provider.

## The Service in a Test

A test builds the service on `FakePlatform`, so nothing reaches the network. With a container, it replaces the entry of stage 0 before anything asks for the service:

```php
$container->set(IQ::class, (new IQ('test-key', httpClient: $platform))->enableTracing());
```

- **Tracing stays off unless the test turns it on.** With the `phpunit.xml` lines of stage 1 (`IQ_TRACING` forced off, a test key), `Tracing::subscribe()` never asks for the service, and the container's own entry builds one that sends nothing.
- **A test of tracing turns on the variable too.** `Tracing::subscribe()` reads `IQ_TRACING`, not the service: the test sets it as the class reads it, `putenv('IQ_TRACING=true')` (or `$_ENV` where the application reads `$_ENV`), before it takes the agent, and puts it back in `tearDown()`. Without it the agent carries no listener, and nothing reaches the fake.
- **A service the test cannot build fails no build.** With tracing on, `Tracing::subscribe()` catches what the service's entry throws: the agent runs untraced, `assertTraced()` fails with "it took in none", and the cause is the `error_log()` line in the test output. That line comes once per run of the suite: the flag of the class is static.
- **`FakePlatform` answers as the platform does.** `assertTraced()` and `assertEvaluated()` look for the traces and the evaluation results it took in. It answers the read of a dataset with the items `keepDataset()` gave it, and 404 to a slug it was not given. What it refused for `failNext()` is not counted.
- **A fake of the HTTP layer of a framework sees nothing**: the SDK uses Neuron's HTTP client. Give the fake to the service.

## Tracing

The test takes the agent from the container, with its listener, and fakes its model:

```php
use App\Agents\SupportAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Testing\FakePlatform;

protected function tearDown(): void
{
    putenv('IQ_TRACING=false');   // off again for the tests that follow, as phpunit.xml sets it
    parent::tearDown();
}

public function test_a_conversation_is_sent_as_one_trace(): void
{
    putenv('IQ_TRACING=true');    // Tracing::subscribe() reads it: without it the agent gets no listener
    $platform = new FakePlatform();
    $container = require __DIR__ . '/../bootstrap/container.php';
    $container->set(IQ::class, (new IQ('test-key', httpClient: $platform))->enableTracing());

    $container->get(SupportAgent::class)
        ->setAiProvider(new FakeAIProvider(new AssistantMessage('Your order ships tomorrow.')))
        ->for('thread-1')
        ->chat(new UserMessage('When does my order ship?'));

    $platform->assertTraced('invoke_agent SupportAgent');
}
```

- **`assertTraced()` takes the name of the run's span**, the one with no parent: `invoke_agent SupportAgent` for an agent, `invoke_workflow OrderWorkflow` for a workflow, after the short class name. A run the SDK executes is named after its definition (Orchestration, below).
- **It checks that the run was traced, not what its spans hold.** The fake gives no reader for the spans.
- **The trace is sent when the run ends**, inside `chat()`, or when a stream is read to its end.

## Evaluation

An evaluator on a dataset of the platform runs in a test on the items the fake keeps. Neuron's `EvaluatorRunner` runs it as the evaluation command does:

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

public function test_the_refund_evaluator_passes_on_its_dataset(): void
{
    $platform = (new FakePlatform())->keepDataset('refund-requests', [
        ['input' => ['question' => 'Can I get a refund for order 123?'], 'expected_output' => ['answer' => 'refunded']],
    ]);
    $iq = new IQ('test-key', httpClient: $platform);
    $agent = SupportAgent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('Order 123 was refunded.')));

    $now = new DateTimeImmutable();
    $results = (new EvaluatorRunner())->run(new RefundEvaluator($agent, $iq));
    $this->assertSame(1, $results->getPassedCount());

    // What the evaluation command hands the output once the evaluators have run
    $iq->evaluationOutput(environment: 'test')->output(new EvaluationReport(
        [new EvaluatorReport(RefundEvaluator::class, $results, $now, $now)],
        $now,
        $now,
    ));
    $platform->assertEvaluated(RefundEvaluator::class);
}
```

- **`keepDataset()` takes the items as the platform holds them**, each `['input' => ..., 'expected_output' => ..., 'metadata' => ...]`, and the evaluator reads them in that order. The fake adds the `dataset_item_id` the platform sends, the position of the item from 1 unless you give one.
- **A slug the fake does not keep** is answered 404, as the platform answers it: `load()` throws an `IQException`, and the runner lets it through. So does a read after `$platform->failNext(1, 503)`: the platform away when the dataset is read.
- **`FakeAIProvider` answers in order**, one message per inference: give it one answer per dataset item.
- **`assertEvaluated()` takes the class of the evaluator**, as the runner names it. Whether the items passed is the runner's to say: `getPassedCount()`, as above.

## Orchestration

A test of orchestration gives the service the fake platform, its signing key, a store and the fake queue:

```php
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Testing\FakeDispatcher;
use NeuronCore\IQ\Testing\FakePlatform;
use NeuronCore\IQ\Verb;

$platform = new FakePlatform();
$queue = new FakeDispatcher();
$store = new InMemoryPersistence();

$iq = (new IQ('test-key', $platform->signingKey, httpClient: $platform))
    ->setPersistence(fn () => $store)
    ->setDispatcher($queue)
    ->register('order-fulfilment', fn () => new OrderWorkflow());

$iq->workflow('order-fulfilment', 'order:123')->start(new OrderPlaced('123'))->dispatch();
$queue->assertDispatched(Verb::Start, 'order:123');

$queue->runAll($iq);                    // the worker: it runs the segment and reports it
$platform->assertReported('order:123', Outcome::Suspended);

$platform->wake($iq, 'order:123');      // the platform's wake-up, signed, through handle()
$queue->runAll($iq);                    // ...which a worker then executes
```

- One `InMemoryPersistence` shared through the closure is enough for a test; a test that calls `sync()` needs a real store, such as `DatabasePersistence` on SQLite.
- `runAll()` returns what every `invoke()` returned (a `Report`) or threw (an `IQException`), and keeps queued what a worker would try again. A segment run in the test itself with `run()` or `events()` queues its two notices: `runAll()` posts them.
- `$platform->failNext(2, 503)` makes the next two requests fail; `$platform->deliver($iq, [...])` sends the endpoint any message, signed.
- `$platform->assertAnnounced()`, `assertReported()` and `assertSynced()` read what the platform recorded; `$queue->assertDispatched()` reads the queue.
- With `enableTracing()` on this service, each segment sends its trace to the fake too, named after its definition: `$platform->assertTraced('invoke_workflow order-fulfilment')`.

## Related

- [SKILL.md](../SKILL.md) — the service, observability, evaluation.
- [orchestration.md](orchestration.md) — what the orchestration test exercises.
- **neuron-test** — `FakeAIProvider` and the other fakes of Neuron.
- **neuron-evaluation** — evaluators, the runner, output drivers.
