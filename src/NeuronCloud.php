<?php

declare(strict_types=1);

namespace NeuronCore\Cloud;

use Closure;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\InPlaceDispatcher;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Runtime\Registry;
use NeuronCore\Cloud\Runtime\Runner;
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
     * The body of a queue job: execute one segment and report it, or post one
     * of the SDK's own notices to the platform.
     *
     * @throws CloudException when the message was not dealt with: the reason tells the worker what to do
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
            new WorkflowBuilder($this->persistence()),
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
            new WorkflowBuilder($persistence),
            new Ledger($persistence),
            new PlatformClient($this->httpClient, $this->platformUrl, $this->apiKey),
            $this->dispatcher(),
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

    protected function dispatcher(): DispatcherInterface
    {
        return $this->dispatcher ?? new InPlaceDispatcher($this);
    }
}
