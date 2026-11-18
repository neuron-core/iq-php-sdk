<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\Matrix\MatrixWorkflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\InPlace\Stage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 2 of section 9.6 of the design: the state is returned, and the
 * exception of a node thrown, as without the SDK.
 */
class ReturnedStateTest extends InPlaceTestCase
{
    #[DataProvider('kindsThatReturnAState')]
    public function test_the_caller_gets_the_state_of_the_run_as_the_segment_left_it(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $runToContinue = $stage->stored();

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(
            [self::outcomeOf($ending)->value, Stage::WORKFLOW_ID, $kind->continuesARun() ? 2 : 1],
            [$played->returned->getStatus()->value, $played->returned->getWorkflowId(), $played->returned->getExecutionAttempt()],
        );
        self::assertSame($runToContinue['runId'] ?? $played->announce()->runId, $played->returned->getRunId());
    }

    #[DataProvider('kinds')]
    public function test_the_state_of_a_run_that_stopped_at_a_wait_carries_that_wait(string $backend, Kind $kind): void
    {
        $played = $this->stage($backend, $kind, Ending::Waits)->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        $wait = $played->returned->getInterruptRequest();
        self::assertInstanceOf(WaitForEventRequest::class, $wait);
        self::assertSame(MatrixWorkflow::EVENT, $wait->getEventName());
    }

    #[DataProvider('kinds')]
    public function test_the_state_of_a_run_that_stopped_at_a_wait_is_the_run_the_store_holds(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(
            [$stage->stored()['runId'] ?? null, $stage->stored()['status'] ?? null, $stage->stored()['attempt'] ?? null],
            [$played->returned->getRunId(), $played->returned->getStatus()->value, $played->returned->getExecutionAttempt()],
        );
    }

    #[DataProvider('kinds')]
    public function test_the_caller_gets_what_the_last_node_threw(string $backend, Kind $kind): void
    {
        $played = $this->stage($backend, $kind, Ending::Fails)->play();

        self::assertInstanceOf(NodeFailed::class, $played->thrown);
        self::assertSame('The ending node failed on purpose.', $played->thrown->getMessage());
    }

    #[DataProvider('kinds')]
    public function test_a_run_whose_node_threw_is_left_failed_in_the_store(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Fails);

        $stage->play();

        self::assertSame(['failed', $kind->continuesARun() ? 2 : 1], [$stage->stored()['status'] ?? null, $stage->stored()['attempt'] ?? null]);
    }
}
