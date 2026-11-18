<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use Closure;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Steps 1 and 3 of the runner: a definition or a version this process lacks,
 * and a factory that cannot build its workflow. Nothing runs, and the
 * invocation may be sent again.
 */
class DefinitionTest extends RunnerTestCase
{
    protected const NEWER_RUN_ID = '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    #[DataProvider('backends')]
    public function test_a_platform_start_of_an_unknown_definition_is_reported_aborted_and_returned(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('retired'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::UnknownDefinition->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame([], $this->announcementsSent(), 'A segment that cannot be built is not announced.');
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::UnknownDefinition->value);
    }

    #[DataProvider('backends')]
    public function test_an_application_start_of_an_unknown_definition_is_reported_then_thrown_with_that_reason(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $thrown = $this->thrownBy($this->start('retired', self::APPLICATION));

        self::assertSame(Code::UnknownDefinition, $thrown->reason);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::UnknownDefinition->value);
    }

    #[DataProvider('backends')]
    public function test_the_recorded_report_of_an_unknown_definition_has_no_snapshot_and_no_observe_follows_it(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('retired'));

        // No registered version can build a workflow to read the store with: the platform asks by itself.
        self::assertFalse($report->snapshotRead);
        self::assertNull($report->run);
        self::assertSame([], $this->queue->dispatched());
    }

    #[DataProvider('backends')]
    public function test_the_report_of_an_unknown_definition_the_platform_did_not_record_leaves_an_observe_that_carries_the_abort(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext();

        $bench->iq->invoke($this->start('retired'));

        self::assertCount(1, $this->queue->dispatched());
        self::assertSame(Verb::Observe, $this->queue->dispatched()[0]->verb);
        self::assertSame(Outcome::Aborted->value, $this->queue->dispatched()[0]->body['outcome']);
        self::assertSame(Code::UnknownDefinition->value, $this->queue->dispatched()[0]->body['code']);
    }

    #[DataProvider('backends')]
    public function test_the_observe_an_unknown_definition_leaves_reports_the_abort_without_a_snapshot_and_ends(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->platform->failNext();
        $bench->iq->invoke($this->start('retired'));

        $delivered = $this->queue->runAll($bench->iq);

        self::assertCount(1, $delivered);
        self::assertInstanceOf(Report::class, $delivered[0]);
        self::assertSame(Outcome::Aborted, $delivered[0]->outcome);
        self::assertSame(Code::UnknownDefinition->value, $delivered[0]->code);
        self::assertFalse($delivered[0]->snapshotRead);
        self::assertNull($delivered[0]->run);
        self::assertNull($delivered[0]->claimed);
        self::assertSame([], $this->queue->runAll($bench->iq), 'The observe is not tried again: the platform recorded its report.');
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::UnknownDefinition->value);
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_of_an_unknown_version_is_reported_aborted_and_returned(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('greeting', fields: ['runId' => self::NEWER_RUN_ID]));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::UnknownVersion->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame([], $this->announcementsSent(), 'A segment that cannot be built is not announced.');
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::UnknownVersion->value);
    }

    #[DataProvider('backends')]
    public function test_an_application_start_of_an_unknown_version_is_reported_then_thrown_with_that_reason(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $thrown = $this->thrownBy($this->start('greeting', self::APPLICATION, ['runId' => self::NEWER_RUN_ID]));

        self::assertSame(Code::UnknownVersion, $thrown->reason);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::UnknownVersion->value);
    }

    #[DataProvider('backends')]
    public function test_a_wake_for_a_run_of_a_version_this_worker_lacks_is_aborted_and_leaves_the_run_as_it_was(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->registerOrder($bench->iq, $bench);
        $started = $this->deployedWithVersionTwo($bench)->invoke($this->start('order', fields: ['runId' => self::NEWER_RUN_ID]));

        $report = $bench->iq->invoke($this->wake('order', $started));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::UnknownVersion->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertTrue($report->snapshotRead, 'The versions of a name share one store: the current one reads it.');
        self::assertSame($started->run, $report->run);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_whose_factory_throws_is_aborted_as_not_executed_with_the_exception_class(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $report = $bench->iq->invoke($this->start('unbuildable'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertSame(FactoryFailed::class, $report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertSame([], $this->announcementsSent(), 'A segment that cannot be built is not announced.');
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('backends')]
    public function test_the_report_of_a_platform_start_whose_factory_throws_holds_what_the_factory_threw(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $report = $bench->iq->invoke($this->start('unbuildable'));

        self::assertInstanceOf(FactoryFailed::class, $report->exception);
        self::assertSame('The container could not build the workflow.', $report->exception->getMessage());
        self::assertArrayNotHasKey('exception', $this->reportsSent()[0]);
    }

    #[DataProvider('backends')]
    public function test_the_report_of_an_abort_that_nothing_threw_holds_no_exception(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $report = $bench->iq->invoke($this->start('unregistered'));

        self::assertSame([Outcome::Aborted, Code::UnknownDefinition->value], [$report->outcome, $report->code]);
        self::assertNull($report->exception);
    }

    #[DataProvider('backends')]
    public function test_an_application_start_whose_factory_throws_is_reported_then_thrown_as_not_executed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $thrown = $this->thrownBy($this->start('unbuildable', self::APPLICATION));

        self::assertSame(Code::NotExecuted, $thrown->reason);
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('backends')]
    public function test_the_exception_thrown_for_an_abort_carries_the_throwable_that_caused_it_as_its_previous(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $thrown = $this->thrownBy($this->start('unbuildable', self::APPLICATION));

        self::assertInstanceOf(FactoryFailed::class, $thrown->getPrevious());
        self::assertSame('The container could not build the workflow.', $thrown->getPrevious()->getMessage());
    }

    #[DataProvider('backends')]
    public function test_the_exception_thrown_for_an_abort_names_the_definition_the_workflow_id_and_the_run_id(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $thrown = $this->thrownBy($this->start('unbuildable', self::APPLICATION));

        self::assertStringContainsString("'unbuildable'", $thrown->getMessage());
        self::assertStringContainsString("'" . self::WORKFLOW_ID . "'", $thrown->getMessage());
        self::assertStringContainsString("'" . self::RUN_ID . "'", $thrown->getMessage());
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_factory_throws_claims_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $bench->iq->register('unbuildable', $this->failingFactory());

        $report = $bench->iq->invoke($this->start('unbuildable'));

        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
    }

    /**
     * @return Closure(): Workflow
     */
    protected function failingFactory(): Closure
    {
        return static fn (): Workflow => throw new FactoryFailed('The container could not build the workflow.');
    }

    /**
     * "order" waits for an event that only a signal or an answer delivers.
     */
    protected function registerOrder(IQ $iq, TestBench $bench, int $version = 1): void
    {
        $executions = $bench->executions;

        $iq->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions), $version);
    }

    /**
     * A worker of the same application that already runs the next deploy: it knows version 2 of "order".
     */
    protected function deployedWithVersionTwo(TestBench $bench): IQ
    {
        $iq = (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($bench->persistence)
            ->setDispatcher($this->queue);

        $this->registerOrder($iq, $bench);
        $this->registerOrder($iq, $bench, 2);

        return $iq;
    }
}
