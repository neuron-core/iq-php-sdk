<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Starts;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\Flows\HookedAgent;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Stub\Runner\HookedWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\HookFailed;
use NeuronCore\IQ\Tests\Support\Flows\StartTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * A hook of the workflow that throws while its start event or its graph is
 * built breaks no rule of section 9.3 of the design: start() throws it as it
 * is, and a worker that meets it aborts the start so that it can run later.
 */
class StartHookTest extends StartTestCase
{
    /** What the hooks of "hooked" throw while it is set; null: they throw nothing. */
    protected ?Throwable $failure = null;

    /** The hook of "hooked" that throws: startEvent, resources or nodes. */
    protected string $failingHook;

    #[DataProvider('hooksOnEveryBackend')]
    public function test_what_a_hook_throws_is_thrown_from_start_as_it_is(string $backend, string $hook): void
    {
        $this->benchWithHooked($backend, $hook);
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");

        try {
            $this->hooked()->start();
            self::fail("start() swallowed what the {$hook}() hook threw.");
        } catch (HookFailed $thrown) {
            self::assertSame($this->failure, $thrown);
        }
    }

    #[DataProvider('hooksOnEveryBackend')]
    public function test_a_start_whose_hook_throws_announces_nothing_and_touches_no_store(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook);
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");

        $this->letHookFail(fn (): PendingSegment => $this->hooked()->start());

        self::assertSame([], $this->queue->dispatched(), 'Nothing was handed to the queue.');
        self::assertSame([], $bench->platform->received(), 'Nothing was sent to the platform.');
        self::assertSame([], $this->storeOperations);
    }

    #[DataProvider('hooksOfTheGraphOnEveryBackend')]
    public function test_a_hook_of_the_graph_that_throws_is_thrown_from_a_start_that_is_given_its_event(string $backend, string $hook): void
    {
        $this->benchWithHooked($backend, $hook);
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");

        try {
            $this->hooked()->start(new StartEvent());
            self::fail("start() swallowed what the {$hook}() hook threw.");
        } catch (HookFailed $thrown) {
            self::assertSame($this->failure, $thrown);
        }
    }

    #[DataProvider('backends')]
    public function test_a_plain_workflow_exception_thrown_by_a_hook_of_the_graph_counts_as_a_refusal_of_the_event(string $backend): void
    {
        $this->benchWithHooked($backend, 'nodes');
        $this->failure = new WorkflowException('The node catalogue is being rebuilt.');

        $refusal = self::thrownBy(fn (): PendingSegment => $this->hooked()->start());

        self::assertInstanceOf(IQException::class, $refusal);
        self::assertSame(Code::InvalidStart, $refusal->reason);
        self::assertSame($this->failure, $refusal->getPrevious());
    }

    #[DataProvider('backends')]
    public function test_a_store_exception_thrown_by_a_hook_of_the_graph_is_thrown_from_start_as_it_is(string $backend): void
    {
        $this->benchWithHooked($backend, 'resources');
        $this->failure = new PersistenceException('The catalogue database is down.');

        try {
            $this->hooked()->start();
            self::fail('start() swallowed what the resources() hook threw.');
        } catch (PersistenceException $thrown) {
            self::assertSame($this->failure, $thrown);
        }
    }

    #[DataProvider('backends')]
    public function test_what_the_factory_of_the_definition_throws_is_thrown_from_start_as_it_is(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $failure = new FactoryFailed('The factory reached a service that is down.');
        $bench->iq->register('unbuildable', static fn (): Workflow => throw $failure);
        $unbuildable = $bench->iq->workflow('unbuildable', self::WORKFLOW_ID);

        try {
            $unbuildable->start();
            self::fail('start() swallowed what the factory threw.');
        } catch (FactoryFailed $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    #[DataProvider('hooksOfTheGraphOnEveryBackend')]
    public function test_a_worker_whose_hook_throws_is_thrown_a_dispatched_start_back_as_not_executed(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook);
        $this->hooked()->start()->dispatch();
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");

        [$thrown] = $this->queue->runAll($bench->iq);

        self::assertInstanceOf(IQException::class, $thrown);
        self::assertSame(Code::NotExecuted, $thrown->reason);
        self::assertSame($this->failure, $thrown->getPrevious());
    }

    #[DataProvider('hooksOfTheGraphOnEveryBackend')]
    public function test_a_worker_whose_hook_throws_reports_a_dispatched_start_as_aborted_with_the_exception_class(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook);
        $runId = $this->hooked()->start()->dispatch();
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");

        $this->queue->runAll($bench->iq);

        [$report] = $this->reportsSent();
        self::assertSame(
            [Outcome::Aborted->value, Code::NotExecuted->value, HookFailed::class, null, null],
            [$report['outcome'], $report['code'], $report['errorClass'], $report['claimed'], $report['run']],
        );
        self::assertFalse($this->isClaimed($runId), 'A start that was aborted before its claim may run later.');
    }

    #[DataProvider('hooksOfTheGraphOnEveryBackend')]
    public function test_a_dispatched_start_a_hook_aborted_stays_queued_and_runs_once_the_hook_works_again(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook);
        $this->hooked()->start()->dispatch();
        $this->failure = new HookFailed("The {$hook}() hook reached a service that is down.");
        $this->queue->runAll($bench->iq);

        $this->failure = null;
        $report = $this->workOnce();

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    /**
     * The event of a dispatched start is built in the request and travels
     * with it, so the worker has no reason to build it again.
     */
    #[DataProvider('backends')]
    public function test_a_worker_runs_a_dispatched_start_without_calling_the_start_event_hook_again(string $backend): void
    {
        $bench = $this->benchWithHooked($backend, 'startEvent');
        $this->hooked()->start()->dispatch();
        $this->failure = new HookFailed('The startEvent() hook reached a service that is down.');

        $report = $this->workOnce();

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_what_the_start_event_hook_of_an_agent_throws_is_thrown_from_a_start_with_a_message_as_it_is(string $backend): void
    {
        $this->benchWithHookedAgent($backend);
        $this->failure = new HookFailed('The startEvent() hook reached a service that is down.');

        try {
            $this->hooked()->start(new UserMessage('Refund order 123.'));
            self::fail('start() swallowed what the startEvent() hook threw.');
        } catch (HookFailed $thrown) {
            self::assertSame($this->failure, $thrown);
        }
    }

    /**
     * A worker compares the class of the event a turn carries with the one
     * of the agent's own event, so it does call the hook.
     */
    #[DataProvider('backends')]
    public function test_a_worker_whose_start_event_hook_throws_aborts_a_dispatched_turn_which_runs_once_the_hook_works_again(string $backend): void
    {
        $bench = $this->benchWithHookedAgent($backend);
        $this->hooked()->start(new UserMessage('Refund order 123.'))->dispatch();
        $this->failure = new HookFailed('The startEvent() hook reached a service that is down.');

        [$thrown] = $this->queue->runAll($bench->iq);
        $this->failure = null;
        $report = $this->workOnce();

        self::assertInstanceOf(IQException::class, $thrown);
        self::assertSame(Code::NotExecuted, $thrown->reason);
        self::assertInstanceOf(HookFailed::class, $thrown->getPrevious());
        self::assertSame(Outcome::Completed, $report->outcome);
    }

    /**
     * @return iterable<string, array{string, string}> a backend, and the hook of the workflow that throws
     */
    public static function hooksOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            foreach (['startEvent', 'resources', 'nodes'] as $hook) {
                yield "{$hook}(), on {$store}" => [$backend, $hook];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}> a backend, and a hook Neuron calls to build the graph
     */
    public static function hooksOfTheGraphOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            foreach (['resources', 'nodes'] as $hook) {
                yield "{$hook}(), on {$store}" => [$backend, $hook];
            }
        }
    }

    /**
     * "hooked" completes in one segment; while the test holds a failure, the named hook of its workflow throws it.
     */
    protected function benchWithHooked(string $backend, string $hook): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $this->failingHook = $hook;

        $bench->iq->register('hooked', fn (): Workflow => new HookedWorkflow($executions, function (string $called): void {
            if ($called === $this->failingHook && $this->failure instanceof Throwable) {
                throw $this->failure;
            }
        }));

        return $bench;
    }

    /**
     * "hooked" is an agent; while the test holds a failure, its startEvent() hook throws it.
     */
    protected function benchWithHookedAgent(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));

        $bench->iq->register('hooked', fn (): Agent => (new HookedAgent(function (): void {
            if ($this->failure instanceof Throwable) {
                throw $this->failure;
            }
        }))->setAiProvider($provider));

        return $bench;
    }

    protected function hooked(): ManagedWorkflow
    {
        return $this->bench->iq->workflow('hooked', self::WORKFLOW_ID);
    }

    /**
     * @param Closure(): mixed $call
     */
    protected function letHookFail(Closure $call): void
    {
        try {
            $call();
            self::fail('The hook did not throw.');
        } catch (HookFailed) {
        }
    }
}
