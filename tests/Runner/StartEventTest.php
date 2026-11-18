<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;
use NeuronCore\IQ\Tests\Stub\Runner\HookedWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\HookFailed;
use NeuronCore\IQ\Tests\Stub\Runner\MisroutedWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\UnstorableStart;
use NeuronCore\IQ\Tests\Stub\Runner\UnstorableStartNode;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function base64_encode;
use function serialize;

/**
 * Steps 2 and 3 of the runner for a start: its use-by time and its start
 * event are checked before anything is announced, claimed or ignited.
 */
class StartEventTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_start_whose_event_no_node_handles_is_rejected_as_invalid_start(string $backend): void
    {
        $bench = $this->benchWithMisrouted($backend);

        $report = $bench->iq->invoke($this->start('misrouted'));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::InvalidStart->value, $report->code);
        self::assertNull($report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertTrue($report->snapshotRead);
        self::assertNull($report->run, 'A bad start event must never create a run.');
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Rejected, Code::InvalidStart->value);
    }

    #[DataProvider('backends')]
    public function test_an_invalid_start_claims_nothing(string $backend): void
    {
        $bench = $this->benchWithMisrouted($backend);

        $report = $bench->iq->invoke($this->start('misrouted'));

        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
    }

    #[DataProvider('backends')]
    public function test_an_invalid_start_is_not_announced(string $backend): void
    {
        $bench = $this->benchWithMisrouted($backend);

        $bench->iq->invoke($this->start('misrouted'));

        self::assertSame([], $this->announcementsSent());
        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('backends')]
    public function test_an_application_start_that_carries_an_event_no_node_handles_is_rejected_and_not_thrown(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('greeting', self::APPLICATION, ['event' => $this->carried(new PaymentSettled())]));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::InvalidStart->value, $report->code);
        self::assertNull($report->claimed);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_event_neuron_cannot_store_with_a_run_is_rejected_as_invalid_start(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register(
            'unstorable',
            static fn (): Workflow => (new Workflow())->addNode(new UnstorableStartNode())->setStartEvent(new UnstorableStart()),
        );

        $report = $bench->iq->invoke($this->start('unstorable'));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::InvalidStart->value, $report->code);
        self::assertNull($report->errorClass);
        self::assertNull($report->claimed);
        self::assertSame([], $this->announcementsSent());
    }

    #[DataProvider('hooksOnEveryBackend')]
    public function test_a_platform_start_whose_hook_throws_is_aborted_as_not_executed_with_the_exception_class(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook, failures: 1);

        $report = $bench->iq->invoke($this->start('hooked'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertSame(HookFailed::class, $report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame([], $this->announcementsSent(), 'A segment that cannot be built is not announced.');
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('hooksOnEveryBackend')]
    public function test_an_application_start_whose_hook_throws_is_reported_then_thrown_as_not_executed(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook, failures: 1);

        $thrown = $this->thrownBy($this->start('hooked', self::APPLICATION));

        self::assertSame(Code::NotExecuted, $thrown->reason);
        self::assertInstanceOf(HookFailed::class, $thrown->getPrevious());
        self::assertNull($this->ledgerRecord());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('hooksOnEveryBackend')]
    public function test_a_start_a_hook_aborted_runs_once_when_it_is_sent_again(string $backend, string $hook): void
    {
        $bench = $this->benchWithHooked($backend, $hook, failures: 1);
        $bench->iq->invoke($this->start('hooked'));

        $sentAgain = $bench->iq->invoke($this->start('hooked'));
        $copy = $bench->iq->invoke($this->start('hooked'));

        self::assertSame(Outcome::Completed, $sentAgain->outcome);
        self::assertSame(Code::AlreadyClaimed->value, $copy->code);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_store_exception_thrown_by_a_hook_while_the_graph_is_built_is_aborted_and_not_rejected(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $storeIsDown = static function (string $called): void {
            if ($called === 'resources') {
                throw new PersistenceException('The store a hook reads is down.');
            }
        };
        $bench->iq->register('hooked', static fn (): HookedWorkflow => new HookedWorkflow($executions, $storeIsDown));

        $report = $bench->iq->invoke($this->start('hooked'));

        self::assertSame(Outcome::Aborted, $report->outcome, 'Neuron\'s own exception classes are not a broken rule when a hook throws them.');
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertSame(PersistenceException::class, $report->errorClass);
        self::assertNull($this->ledgerRecord());
    }

    #[DataProvider('backends')]
    public function test_a_start_ignites_its_run_with_the_event_it_carries_instead_of_the_workflow_own(string $backend): void
    {
        $bench = $this->benchWithMisrouted($backend);

        $report = $bench->iq->invoke($this->start('misrouted', self::APPLICATION, ['event' => $this->carried(new StartEvent())]));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_start_that_carries_no_use_by_time_is_rejected_as_expired(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('greeting', fields: ['expiresAt' => null]));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::Expired->value, $report->code);
        self::assertSame([], $this->announcementsSent(), 'It is refused at the first check, before it is announced.');
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
    }

    /**
     * "misrouted" starts with an event of its own that none of its nodes handles.
     */
    protected function benchWithMisrouted(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->iq->register('misrouted', static fn (): MisroutedWorkflow => new MisroutedWorkflow($executions));

        return $bench;
    }

    /**
     * "hooked" completes in one segment, once the named hook of its workflow has thrown $failures times.
     */
    protected function benchWithHooked(string $backend, string $hook, int $failures): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $failing = static function (string $called) use ($hook, &$failures): void {
            if ($called === $hook && $failures-- > 0) {
                throw new HookFailed("The {$hook}() hook reached a service that is down.");
            }
        };

        $bench->iq->register('hooked', static fn (): HookedWorkflow => new HookedWorkflow($executions, $failing));

        return $bench;
    }

    /**
     * @return iterable<string, array{string, string}> a backend, and the hook of the workflow that throws
     */
    public static function hooksOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            foreach (['startEvent', 'resources', 'nodes'] as $hook) {
                yield "{$hook}() on {$store}" => [$backend, $hook];
            }
        }
    }

    /**
     * A start event as a dispatched start carries it in the queue.
     */
    protected function carried(Event $event): string
    {
        return base64_encode(serialize($event));
    }
}
