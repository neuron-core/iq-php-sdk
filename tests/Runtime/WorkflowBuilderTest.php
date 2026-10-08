<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use Closure;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\WorkflowBuilder;
use NeuronCore\Cloud\Tests\Stub\Units\SelfConfiguredWorkflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function func_get_args;
use function time;

/**
 * The build step, on a class that declares a store, a lease and a retention of its own.
 */
class WorkflowBuilderTest extends TestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const RUN_ID = '1-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    protected const REGISTERED_LEASE = 120;

    protected InMemoryPersistence $sdkStore;

    protected InMemoryPersistence $classStore;

    /** The run as the SDK's store held it while its node was running. */
    protected ?WorkflowRunSnapshot $inSdkStoreWhileRunning = null;

    /** The run as the store the class declares held it while its node was running. */
    protected ?WorkflowRunSnapshot $inClassStoreWhileRunning = null;

    protected function setUp(): void
    {
        $this->sdkStore = new InMemoryPersistence();
        $this->classStore = new InMemoryPersistence();
    }

    public function test_a_run_is_kept_in_the_store_of_the_sdk_not_in_the_one_the_class_declares(): void
    {
        $this->build($this->selfConfigured(...))->run(ExecutionRequest::start(runId: self::RUN_ID));

        self::assertSame(self::RUN_ID, $this->inSdkStoreWhileRunning?->runId);
        self::assertNull($this->inClassStoreWhileRunning);
    }

    public function test_a_run_holds_the_lease_of_its_registration_not_the_one_the_class_declares(): void
    {
        $startedAt = time();

        $this->build($this->selfConfigured(...))->run();

        $leaseExpiresAt = (int) $this->inSdkStoreWhileRunning?->leaseExpiresAt;
        self::assertGreaterThanOrEqual($startedAt + self::REGISTERED_LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + self::REGISTERED_LEASE, $leaseExpiresAt);
    }

    public function test_a_completed_run_is_gone_although_the_class_retains_its_completion(): void
    {
        $this->build($this->selfConfigured(...))->run();

        self::assertNotNull($this->inSdkStoreWhileRunning);
        self::assertNull($this->runIn($this->sdkStore));
    }

    public function test_imposing_puts_a_workflow_that_was_given_another_store_back_on_the_store_of_the_sdk(): void
    {
        $workflow = $this->build($this->selfConfigured(...))->setPersistence($this->classStore);

        $this->impose($workflow)->run(ExecutionRequest::start(runId: self::RUN_ID));

        self::assertSame(self::RUN_ID, $this->inSdkStoreWhileRunning?->runId);
        self::assertNull($this->inClassStoreWhileRunning);
    }

    public function test_imposing_puts_a_workflow_that_was_given_no_lease_back_on_the_lease_of_its_registration(): void
    {
        $workflow = $this->build($this->selfConfigured(...))->setLeaseTimeout(null);
        $startedAt = time();

        $this->impose($workflow)->run();

        $leaseExpiresAt = (int) $this->inSdkStoreWhileRunning?->leaseExpiresAt;
        self::assertGreaterThanOrEqual($startedAt + self::REGISTERED_LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + self::REGISTERED_LEASE, $leaseExpiresAt);
    }

    public function test_imposing_drops_the_retention_a_workflow_was_given_for_its_completion(): void
    {
        $workflow = $this->build($this->selfConfigured(...))->retainCompletionUntilAcknowledged();

        $this->impose($workflow)->run();

        self::assertNotNull($this->inSdkStoreWhileRunning);
        self::assertNull($this->runIn($this->sdkStore));
    }

    public function test_left_to_itself_the_class_runs_on_what_it_declares(): void
    {
        $startedAt = time();

        $this->selfConfigured()->for(self::WORKFLOW_ID)->run();

        self::assertNull($this->inSdkStoreWhileRunning);
        self::assertGreaterThanOrEqual(
            $startedAt + SelfConfiguredWorkflow::LEASE,
            (int) $this->inClassStoreWhileRunning?->leaseExpiresAt,
        );
        self::assertSame(WorkflowStatus::Completed, $this->runIn($this->classStore)?->status);
    }

    public function test_the_factory_receives_the_workflow_id_and_the_run_id(): void
    {
        $received = [];

        $this->build(static function () use (&$received): Workflow {
            $received = func_get_args();

            return new Workflow();
        });

        self::assertSame([self::WORKFLOW_ID, self::RUN_ID], $received);
    }

    public function test_the_factory_receives_no_run_id_when_the_workflow_is_built_only_to_read(): void
    {
        $received = [];

        $this->build(static function () use (&$received): Workflow {
            $received = func_get_args();

            return new Workflow();
        }, runId: null);

        self::assertSame([self::WORKFLOW_ID, null], $received);
    }

    public function test_a_factory_that_declares_no_parameter_gives_a_workflow_bound_to_the_workflow_id(): void
    {
        $workflow = $this->build(static fn (): Workflow => new Workflow());

        self::assertSame(self::WORKFLOW_ID, $workflow->getWorkflowId());
    }

    public function test_the_workflow_the_factory_returns_is_left_unbound(): void
    {
        $shared = new Workflow();

        $this->build(static fn (): Workflow => $shared);

        self::assertNull($shared->getWorkflowId());
    }

    public function test_every_build_asks_the_persistence_factory_for_a_store(): void
    {
        $asked = 0;
        $builder = new WorkflowBuilder(function () use (&$asked): PersistenceInterface {
            $asked++;

            return $this->sdkStore;
        });
        $definition = $this->definition(static fn (): Workflow => new Workflow());

        $builder->build($definition, self::WORKFLOW_ID, self::RUN_ID);
        $builder->build($definition, self::WORKFLOW_ID, null);

        self::assertSame(2, $asked);
    }

    public function test_the_exception_of_a_factory_surfaces_from_the_build(): void
    {
        $failure = new RuntimeException('The container cannot build the workflow.');

        $this->expectExceptionObject($failure);

        $this->build(static fn (): Workflow => throw $failure);
    }

    /**
     * A workflow as the SDK builds it for one run, registered with a lease of its own.
     *
     * @param Closure(string, ?string): Workflow $factory
     */
    protected function build(Closure $factory, ?string $runId = self::RUN_ID): Workflow
    {
        $builder = new WorkflowBuilder(fn (): PersistenceInterface => $this->sdkStore);

        return $builder->build($this->definition($factory), self::WORKFLOW_ID, $runId);
    }

    /**
     * What the SDK decides, imposed again on a workflow that was adjusted after its build.
     */
    protected function impose(Workflow $workflow): Workflow
    {
        $builder = new WorkflowBuilder(fn (): PersistenceInterface => $this->sdkStore);

        return $builder->impose($workflow, $this->definition(static fn (): Workflow => $workflow));
    }

    /**
     * @param Closure(string, ?string): Workflow $factory
     */
    protected function definition(Closure $factory): Definition
    {
        return new Definition('self-configured', 1, $factory, self::REGISTERED_LEASE);
    }

    /**
     * A workflow that shows the test, while its node runs, the run each of the two stores holds.
     */
    protected function selfConfigured(): SelfConfiguredWorkflow
    {
        return new SelfConfiguredWorkflow($this->classStore, function (): void {
            $this->inSdkStoreWhileRunning = $this->runIn($this->sdkStore);
            $this->inClassStoreWhileRunning = $this->runIn($this->classStore);
        });
    }

    protected function runIn(PersistenceInterface $store): ?WorkflowRunSnapshot
    {
        return (new WorkflowEngine($store))->inspect(self::WORKFLOW_ID);
    }
}
