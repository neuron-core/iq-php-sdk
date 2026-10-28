<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use Closure;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Evaluation\CloudDataset;
use NeuronCore\Cloud\Evaluation\CloudOutput;
use NeuronCore\Cloud\Observability\CloudListener;
use NeuronCore\Cloud\Observability\TraceExporter;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\Endpoint;
use NeuronCore\Cloud\Runtime\InPlaceDispatcher;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Runtime\Registry;
use NeuronCore\Cloud\Runtime\Runner;
use NeuronCore\Cloud\Runtime\Signature;
use NeuronCore\Cloud\Runtime\Sync;
use NeuronCore\Cloud\Runtime\WorkflowBuilder;

use const CURLOPT_FOLLOWLOCATION;

/**
 * Connects the workflows of an application to Neuron Cloud.
 */
class NeuronCloud
{
    protected HttpClientInterface $httpClient;

    protected Registry $registry;

    /** @var (Closure(): PersistenceInterface)|null */
    protected ?Closure $persistence = null;

    protected ?DispatcherInterface $dispatcher = null;

    protected ?string $endpointUrl = null;

    /** @var array{captureContent: bool, attributes: array<string, mixed>}|null how segments are traced; null: none is */
    protected ?array $tracing = null;

    /**
     * @param string|null $signingKey only the endpoint needs it
     * @param HttpClientInterface|null $httpClient null: a CurlHttpClient that follows no redirect
     */
    public function __construct(
        protected string $apiKey,
        protected ?string $signingKey = null,
        protected string $platformUrl = 'https://cloud.neuron-ai.dev',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient(curlOptions: [CURLOPT_FOLLOWLOCATION => false]);
        $this->registry = new Registry();
    }

    /**
     * Register one version of a workflow or agent class under a name. The
     * highest registered version is the current one.
     *
     * @param Closure(string $workflowId, ?string $runId): Workflow $factory
     * @param int $leaseTimeout seconds; it replaces the lease the class declares
     * @throws CloudException when the name, the version or the lease cannot be used
     */
    public function register(string $name, Closure $factory, int $version = 1, int $leaseTimeout = 600): static
    {
        $this->registry->register(new Definition($name, $version, $factory, $leaseTimeout));

        return $this;
    }

    /**
     * What is registered in this process: every definition, with the lease of
     * each of its versions. It is the list a sync publishes.
     *
     * @return array<string, array<int, int>> name => [version => lease in seconds]; a name made of digits alone is
     *                                        an integer key, as in any PHP array
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->registry->all() as $definition) {
            $definitions[$definition->name][$definition->version] = $definition->leaseTimeout;
        }

        return $definitions;
    }

    /**
     * @param Closure(): PersistenceInterface $factory called for every workflow the SDK builds
     */
    public function setPersistence(Closure $factory): static
    {
        $this->persistence = $factory;

        return $this;
    }

    /**
     * With no dispatcher set, every message runs in place.
     */
    public function setDispatcher(DispatcherInterface $dispatcher): static
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * The URL the platform calls, as it would type it: what sync() publishes
     * when it is given none, and what the endpoint answers when the platform
     * asks for a sync. It is checked when a sync uses it, not here, so that a
     * URL the platform cannot call leaves the rest of the endpoint working.
     */
    public function setEndpointUrl(string $url): static
    {
        $this->endpointUrl = $url;

        return $this;
    }

    /**
     * Trace every segment the SDK executes, and send its spans to the
     * platform when the segment ends: one OpenTelemetry trace per run, with a
     * span for each node, inference, tool call and retrieval. It also turns on
     * the listeners tracer() returns from then on, for the agents and the
     * workflows the application runs itself. Unlike the reports of a run, a
     * trace carries what your application works on: the message and the
     * stack trace of an exception always, and prompts, completions, tool
     * arguments and tool results unless $captureContent is false. It is sent
     * from the process that executed the segment, a web request included, and
     * a trace the platform did not take is logged and dropped. Call it before
     * workflow() and tracer(), like setPersistence().
     *
     * @param bool $captureContent false: no prompt, completion, tool argument or tool result leaves the application
     * @param array<string, mixed> $attributes stamped on every span, such as the environment of the deployment
     */
    public function enableTracing(bool $captureContent = true, array $attributes = []): static
    {
        $this->tracing = ['captureContent' => $captureContent, 'attributes' => $attributes];

        return $this;
    }

    /**
     * A listener that traces an agent or a workflow the application runs
     * itself, with chat(), stream(), run() or events(): subscribe it with
     * $workflow->subscribe(ObservabilityEvent::class, $cloud->tracer()). Each
     * run it hears sends its trace when its segment ends, as enableTracing()
     * says, and its spans are named after the class of the agent or the
     * workflow. It takes the settings enableTracing() was given when tracer()
     * is called: one got before enableTracing() was called never sends
     * anything, even once tracing is enabled later, so a line that subscribes
     * it sends nothing where tracing is off. Where the API key may be missing,
     * ask for the service only when tracing is on. One listener keeps the
     * runs it hears apart, so it may serve an agent whose tool runs another
     * agent. A run the SDK executes is traced once, by the SDK, under its
     * definition name, even when the workflow carries this listener too.
     * Every call returns a new listener. It needs the API key only.
     */
    public function tracer(): CloudListener
    {
        return $this->listener();
    }

    /**
     * An output for Neuron's evaluations that sends the results of a run to
     * the platform, with the inputs and the outputs of every item: give it to
     * the "output" list of evaluation.php. It needs the API key only.
     *
     * @param string|null $environment where the evaluation ran, such as "ci"
     */
    public function evaluationOutput(?string $environment = null): CloudOutput
    {
        return new CloudOutput($this->platform(), $environment);
    }

    /**
     * A dataset the platform keeps, for the getDataset() of an evaluator: an
     * evaluator that receives this service from the application's container
     * returns it there. Its items are read when Neuron's runner loads the
     * dataset, not here. It needs the API key only.
     *
     * @param string $slug what the platform names the dataset by
     */
    public function dataset(string $slug): CloudDataset
    {
        return new CloudDataset($this->platform(), $slug);
    }

    /**
     * App Sync, the last step of a deploy: publish every registered definition
     * and version, and the URL of the endpoint. The list is complete and
     * replaces the one the platform holds.
     *
     * @param string|null $endpointUrl null: the URL setEndpointUrl() was given
     * @throws CloudException not_configured, when no persistence is set, and when no endpoint URL is given or set;
     *                        unsuitable_persistence, for an in-memory or file store; invalid_request, when no
     *                        definition is registered, for an endpoint URL that is not https and for a registered
     *                        lease under 60 seconds: in all of these nothing was sent. endpoint_unverified, when the
     *                        platform could not ping the endpoint; unauthorized, invalid_request or
     *                        unsupported_protocol, when it refused the sync for good; report_undelivered, when it gave
     *                        no usable answer: the sync may have been applied
     */
    public function sync(?string $endpointUrl = null): SyncResult
    {
        return $this->syncWith($this->persistence())->publish($endpointUrl ?? $this->endpointUrl());
    }

    /**
     * The endpoint: answer one request of the platform. A start and a wake are
     * handed to the dispatcher; an inspect, an abandon and a ping are answered
     * here, and so is a sync the platform asks for: with the body sync() would
     * post, or with a 409 where sync() would throw before it sent anything. It
     * never throws.
     *
     * @param array<array-key, mixed> $headers name => value, as getallheaders() gives them, or name => list of
     *                                         values, as a framework does; names in any case
     */
    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        return $this->endpoint()->handle($method, $headers, $body);
    }

    /**
     * The body of a queue job: execute one segment and report it, or post one
     * of the SDK's own notices to the platform.
     *
     * @throws CloudException when the message was not dealt with: the reason tells the worker what to do. For an
     *                        observe the platform did not record, the exception carries the report that was built
     */
    public function invoke(Invocation $invocation): Report
    {
        return $this->runner()->invoke($invocation);
    }

    /**
     * What the application's own code starts, answers and recovers: a
     * registered workflow bound to one workflow ID. It uses the persistence
     * and the dispatcher that are set when it is called: call setPersistence()
     * and setDispatcher() first.
     *
     * @throws CloudException when the name is not registered, the workflow ID cannot be used or no persistence is set
     */
    public function workflow(string $name, string $workflowId): ManagedWorkflow
    {
        return new ManagedWorkflow(
            $name,
            $workflowId,
            $this->registry,
            $this->builder($this->persistence()),
            $this->dispatcher(),
        );
    }

    /**
     * @throws CloudException
     */
    protected function runner(): Runner
    {
        $persistence = $this->persistence();

        return new Runner(
            $this->registry,
            $this->builder($persistence),
            new Ledger($persistence),
            $this->platform(),
            $this->dispatcher(),
        );
    }

    protected function endpoint(): Endpoint
    {
        // The store is asked for when a message needs it, not before: a ping and every refusal are answered without one.
        $persistence = fn (): PersistenceInterface => ($this->persistence())();

        return new Endpoint(
            new Signature($this->signingKey),
            $this->registry,
            $this->builder($persistence),
            new Ledger($persistence),
            $this->dispatcher(),
            // Built only when the platform asks for a sync, from what is set then.
            fn (): array => $this->syncWith($this->persistence())->manifest($this->endpointUrl()),
        );
    }

    /**
     * @param Closure(): PersistenceInterface $persistence
     */
    protected function syncWith(Closure $persistence): Sync
    {
        return new Sync($this->registry, $persistence, $this->platform());
    }

    protected function platform(): PlatformClient
    {
        return new PlatformClient($this->httpClient, $this->platformUrl, $this->apiKey);
    }

    /**
     * @param Closure(): PersistenceInterface $persistence
     */
    protected function builder(Closure $persistence): WorkflowBuilder
    {
        if ($this->tracing === null) {
            return new WorkflowBuilder($persistence);
        }

        // Spans are named after the definition: it is the name the platform knows the workflow by.
        return new WorkflowBuilder($persistence, fn (Definition $definition): CloudListener => $this->listener($definition->name));
    }

    /**
     * A listener with the settings enableTracing() was given, read now; with
     * none, a listener that handles no event.
     *
     * @param string|null $definition the definition of a workflow the SDK executes: spans are named after it, and
     *                                every other listener leaves the runs this one hears to it; null: a listener of
     *                                the application, whose spans are named after the class
     */
    protected function listener(?string $definition = null): CloudListener
    {
        if ($this->tracing === null) {
            return new CloudListener(null);
        }

        return new CloudListener(
            new TraceExporter($this->platform()),
            $definition,
            metadata: $this->tracing['attributes'],
            captureContent: $this->tracing['captureContent'],
            claimsRuns: $definition !== null,
        );
    }

    /**
     * @return Closure(): PersistenceInterface
     * @throws CloudException not_configured
     */
    protected function persistence(): Closure
    {
        return $this->persistence ?? throw new CloudException(
            'No persistence is set: call setPersistence() with a factory of the store your workflows use.',
            Code::NotConfigured,
        );
    }

    /**
     * @throws CloudException not_configured
     */
    protected function endpointUrl(): string
    {
        return $this->endpointUrl ?? throw new CloudException(
            'No endpoint URL is set: give sync() the URL the platform calls, or call setEndpointUrl().',
            Code::NotConfigured,
        );
    }

    protected function dispatcher(): DispatcherInterface
    {
        return $this->dispatcher ?? new InPlaceDispatcher($this);
    }
}
