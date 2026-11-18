<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Questions;

use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Flows\RefundTool;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A run is read for the platform and discarded only through the definition
 * that started it: an inspect or an abandon that names another registered
 * definition is answered 400 invalid_request, and nothing is written. In
 * these tests "order-fulfilment" starts the run, and "nightly-reconciliation"
 * is the other definition.
 */
class AnotherDefinitionTest extends QuestionsTestCase
{
    protected const OTHER = 'nightly-reconciliation';

    protected const REFUSED = [400, ['code' => 'invalid_request']];

    #[DataProvider('verbsOnBackends')]
    public function test_a_question_that_names_another_definition_than_the_one_that_started_the_run_is_answered_400_invalid_request(
        string $backend,
        string $verb,
    ): void {
        $this->orderWaitingOn($backend);

        $answer = $this->deliver($this->named(self::OTHER, $verb));

        self::assertSame(self::REFUSED, [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('verbsOnBackends')]
    public function test_a_question_that_names_another_definition_writes_nothing_and_asks_nothing_of_the_ledger(string $backend, string $verb): void
    {
        $this->orderWaitingOn($backend);
        $operations = $this->watchTheStore();

        $this->deliver($this->named(self::OTHER, $verb));

        self::assertSame([['get', self::WORKFLOW_ID], ['get', self::WORKFLOW_ID]], $operations->getArrayCopy(), 'The run was read, once.');
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_names_another_definition_leaves_the_run_and_enters_no_run_id_in_the_ledger(string $backend): void
    {
        $this->orderWaitingOn($backend);
        $held = $this->storedRun();
        $ledger = $this->ledger();

        $answer = $this->deliver(['definition' => self::OTHER] + $this->abandon('1-a-start-still-queued'));

        self::assertSame(400, $answer->statusCode);
        self::assertSame([$held, $ledger], [$this->storedRun(), $this->ledger()]);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_names_a_plain_definition_does_not_discard_the_run_of_an_agent_that_its_guard_protects(string $backend): void
    {
        $this->ordersOn($backend);
        $this->threadWaitingForAnApproval();
        $held = $this->storedRun(self::THREAD_ID);

        $answer = $this->deliver(['workflowId' => self::THREAD_ID] + $this->abandon($held['runId'], $held['attempt']));

        self::assertSame(self::REFUSED, [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([$held, []], [$this->storedRun(self::THREAD_ID), $this->ledger(self::THREAD_ID)], 'The agent keeps its run.');
    }

    #[DataProvider('backends')]
    public function test_the_approval_can_still_be_given_after_an_abandon_that_named_a_plain_definition(string $backend): void
    {
        $executions = $this->ordersOn($backend)->executions;
        $thread = $this->threadWaitingForAnApproval();
        $held = $this->storedRun(self::THREAD_ID);
        $this->deliver(['workflowId' => self::THREAD_ID] + $this->abandon($held['runId'], $held['attempt']));

        $state = $thread->submitInputs([self::CALL_ID => 'approve'], new ApprovalTranslator())->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $executions->count(RefundTool::class));
    }

    #[DataProvider('whatAnApprovalHolds')]
    public function test_the_refusal_of_an_inspect_that_names_another_definition_carries_nothing_of_the_run(string $backend, string $kept): void
    {
        $this->ordersOn($backend);
        $this->threadWaitingForAnApproval();

        $answer = $this->deliver(['workflowId' => self::THREAD_ID] + $this->inspect());

        self::assertSame(self::REFUSED, [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertStringContainsString($kept, $this->approvalAsTheStoreHoldsIt(), 'The approval request holds it.');
        self::assertStringNotContainsString($kept, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_that_names_the_definition_that_started_the_run_shows_the_run(string $backend): void
    {
        $this->orderWaitingOn($backend);

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(
            [200, ['run' => $this->storedRun(), 'claimed' => true, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_names_the_definition_that_started_the_run_discards_the_run(string $backend): void
    {
        $this->orderWaitingOn($backend);

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull($this->storedRun());
    }

    #[DataProvider('backends')]
    public function test_an_inspect_of_a_run_that_carries_no_tag_shows_the_run_whatever_definition_it_names(string $backend): void
    {
        $this->orderStartedOutsideTheSdkOn($backend);

        $answer = $this->deliver(['definition' => self::OTHER] + $this->inspect());

        self::assertSame(
            [200, ['run' => $this->storedRun(), 'claimed' => null, 'snapshotRead' => true]],
            self::answered($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_an_abandon_of_a_run_that_carries_no_tag_discards_the_run_whatever_definition_it_names(string $backend): void
    {
        $this->orderStartedOutsideTheSdkOn($backend);
        $held = $this->storedRun();

        $answer = $this->deliver(['definition' => self::OTHER] + $this->abandon($held['runId'], $held['attempt']));

        self::assertSame(self::DISCARDED, self::answered($answer));
        self::assertNull($this->storedRun());
    }

    #[DataProvider('verbsOnBackends')]
    public function test_a_question_that_names_another_definition_is_answered_as_before_where_no_run_holds_the_workflow_id(
        string $backend,
        string $verb,
    ): void {
        $this->twoDefinitionsOn($backend);

        $answer = $this->deliver($this->named(self::OTHER, $verb));

        self::assertSame(200, $answer->statusCode);
        self::assertNull(self::fieldsOf($answer)['run']);
    }

    /**
     * @return array<string, array<int, mixed>> the backend and the verb of the question
     */
    public static function verbsOnBackends(): array
    {
        return self::onEachBackend(['an inspect' => ['inspect'], 'an abandon' => ['abandon']]);
    }

    /**
     * "order-fulfilment" waits for a payment, and "nightly-reconciliation" completes at once.
     */
    protected function twoDefinitionsOn(string $backend): TestBench
    {
        $bench = $this->ordersOn($backend);
        $executions = $bench->executions;
        $bench->iq->register(self::OTHER, static fn (): Workflow => new CompletingWorkflow($executions));

        return $bench;
    }

    /**
     * The platform started an order and a worker ran its first segment: the run waits for its payment, and
     * carries the tag "order-fulfilment".
     */
    protected function orderWaitingOn(string $backend): void
    {
        $this->twoDefinitionsOn($backend);
        $this->deliver($this->start());
        $this->work();
    }

    /**
     * The same order, started by code that does not use the SDK: its run carries no tag.
     */
    protected function orderStartedOutsideTheSdkOn(string $backend): void
    {
        $bench = $this->twoDefinitionsOn($backend);

        (new WaitingWorkflow($bench->executions))->for(self::WORKFLOW_ID)->setPersistence(($bench->persistence)())->run();
    }

    /**
     * @return array<string, mixed> an inspect or an abandon of the order, sent under the name of a definition
     */
    protected function named(string $definition, string $verb): array
    {
        return ['definition' => $definition] + ($verb === 'inspect' ? $this->inspect(self::RUN_ID) : $this->abandon(self::RUN_ID, attempt: 1));
    }
}
