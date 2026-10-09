<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Local\PaidAmountTranslator;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 9.7: submitInputs(), signal() and recover() act on a run only
 * through the definition that started it. In these tests "contract" or
 * "flaky" starts the run, and "lease" is another definition bound to the
 * same workflow ID.
 */
class WrongDefinitionTest extends ContinuationTestCase
{
    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_of_a_run_another_definition_started_throws_wrong_definition_and_queues_nothing(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $this->contract()->start()->run();
        $lease = $this->lease();
        $queued = $this->countQueued();

        $thrown = self::cloudExceptionFrom(fn (): PendingSegment => $this->prepare($continuation, $lease, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(CloudException::class, $thrown::class);
        self::assertSame(Code::WrongDefinition, $thrown->reason);
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_the_translator_is_not_asked_about_a_run_another_definition_started(string $backend): void
    {
        $this->benchOn($backend);
        $this->contract()->start()->run();
        $lease = $this->lease();
        $translator = new PaidAmountTranslator();

        self::cloudExceptionFrom(static fn (): PendingSegment => $lease->submitInputs(['paid' => '100'], $translator));

        self::assertNull($translator->translatedAgainst);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_wrong_definition_is_thrown_before_the_version_the_run_is_pinned_to_is_looked_up(
        string $continuation,
        string $backend,
    ): void {
        $executions = $this->benchOn($backend)->executions;
        $this->managed('contract', static fn (): Workflow => new ContractWorkflow($executions), version: 2)->start()->run();
        $lease = $this->lease();

        $thrown = self::cloudExceptionFrom(fn (): PendingSegment => $this->prepare($continuation, $lease, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(Code::WrongDefinition, $thrown->reason, '"lease" has no version 2: looked up first, it would be unknown_version.');
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_wrong_definition_is_thrown_also_for_a_run_that_is_not_suspended_on_a_wait(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $this->flakyThatFailed();
        $lease = $this->lease();

        $thrown = self::cloudExceptionFrom(fn (): PendingSegment => $this->prepare($continuation, $lease, ContractWorkflow::EVENT, self::BUYER));

        self::assertSame(Code::WrongDefinition, $thrown->reason);
    }

    #[DataProvider('backends')]
    public function test_recover_throws_wrong_definition_for_a_failed_run_another_definition_started_and_executes_nothing(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->flakyThatFailed();
        $other = $this->managed('other', static fn (): Workflow => new CompletingWorkflow($executions));
        $queued = $this->countQueued();

        $thrown = self::cloudExceptionFrom(static fn (): ?WorkflowState => $other->recover());

        self::assertSame(Code::WrongDefinition, $thrown->reason);
        self::assertSame([1, 0], [$executions->count(FlakyNode::class), $executions->count(CompleteNode::class)]);
        self::assertSame([], $this->queuedAfter($queued));
        self::assertSame([WorkflowStatus::Failed, 1], [$this->storedRun()?->status, $this->storedRun()?->executionAttempt]);
    }

    #[DataProvider('backends')]
    public function test_recover_throws_wrong_definition_also_for_a_run_that_needs_no_recovery(string $backend): void
    {
        $this->benchOn($backend);
        $this->contract()->start()->run();
        $lease = $this->lease();

        $thrown = self::cloudExceptionFrom(static fn (): ?WorkflowState => $lease->recover());

        self::assertSame(Code::WrongDefinition, $thrown->reason);
    }

    /**
     * @param string $call the local call that reads the run
     */
    #[DataProvider('callsOnBackends')]
    public function test_a_call_on_a_run_another_definition_started_only_reads_the_store(string $call, string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->flakyThatFailed();
        $written = [];
        $persistence = $bench->persistence;
        $bench->cloud->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(
            $persistence(),
            static function (string $operation, string $partition) use (&$written): void {
                if ($operation !== 'get') {
                    $written[] = [$operation, $partition];
                }
            },
        ));
        $lease = $this->lease();

        self::cloudExceptionFrom(static fn (): mixed => match ($call) {
            self::ANSWER => $lease->submitInputs(self::BUYER),
            self::SIGNAL => $lease->signal(ContractWorkflow::EVENT, self::BUYER),
            default => $lease->recover(),
        });

        self::assertSame([], $written);
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_through_the_definition_that_started_the_run_is_prepared_and_runs(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();
        $this->lease();

        $state = $this->prepare($continuation, $contract, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertSame(self::BUYER, $state->get('buyer'));
        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('backends')]
    public function test_recover_through_the_definition_that_started_the_run_finishes_a_failed_run(string $backend): void
    {
        $this->benchOn($backend);
        $flaky = $this->flakyThatFailed();
        $this->lease();

        $recovered = $flaky->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_run_that_carries_no_tag_is_continued_through_whatever_definition_is_asked(string $continuation, string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->withoutTheSdk(new ContractWorkflow($executions))->run();
        $lease = $this->lease();

        $state = $this->prepare($continuation, $lease, ContractWorkflow::EVENT, self::BUYER)->run();

        self::assertNull($this->storedRun()?->tag);
        self::assertSame(self::BUYER, $state->get('buyer'));
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_a_failed_run_that_carries_no_tag_through_whatever_definition_is_asked(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        self::thrownFrom(fn (): WorkflowState => $this->withoutTheSdk(new FlakyWorkflow($executions))->run());
        $other = $this->managed('other', static fn (): Workflow => new CompletingWorkflow($executions));

        $recovered = $other->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertSame(1, $executions->count(CompleteNode::class), 'Nothing says which definition started a run with no tag.');
    }

    #[DataProvider('backends')]
    public function test_a_start_through_another_definition_is_refused_by_neuron_as_before_and_reads_no_tag(string $backend): void
    {
        $this->benchOn($backend);
        $this->contract()->start()->run();
        $lease = $this->lease();

        $inPlace = self::thrownFrom(static fn (): WorkflowState => $lease->start()->run());
        $dispatched = self::thrownFrom(static fn (): string => $lease->start()->dispatch());

        self::assertInstanceOf(RunInFlightException::class, $inPlace, 'A start never replaces a run.');
        self::assertInstanceOf(RunInFlightException::class, $dispatched);
    }

    /**
     * The rows of a data provider: the three local calls that read the run, each on every backend.
     *
     * @return array<string, array{string, string}>
     */
    public static function callsOnBackends(): array
    {
        $rows = self::continuationsOnBackends();
        foreach (self::backends() as $backendName => [$backend]) {
            $rows["a recover on {$backendName}"] = ['recover', $backend];
        }

        return $rows;
    }

    /**
     * "lease": another definition of the same class as "contract", bound to the same workflow ID. Only its
     * name tells it from the definition that started the run.
     */
    protected function lease(): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->managed('lease', static fn (): Workflow => new ContractWorkflow($executions));
    }

    /**
     * "flaky" was started in a request and its node threw: the run is failed under attempt 1, carries the tag
     * "flaky", and a worker has posted the notices of that segment.
     */
    protected function flakyThatFailed(): ManagedWorkflow
    {
        $executions = $this->bench->executions;
        $flaky = $this->managed('flaky', static fn (): Workflow => new FlakyWorkflow($executions));
        self::assertInstanceOf(NodeFailed::class, self::thrownFrom(static fn (): WorkflowState => $flaky->start()->run()));
        $this->work();

        return $flaky;
    }
}
