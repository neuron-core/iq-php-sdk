<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Flows;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;

/**
 * FakePlatform::wake(): the wake IQ sends when a timer comes due
 * or a run has failed, fenced with what the application last reported for
 * the workflow ID. The order waits for its payment, then for a timer that
 * is due.
 */
class FakePlatformWakeTest extends EndpointTestCase
{
    protected const NIGHTLY = 'nightly-reconciliation';

    protected const NIGHTLY_WORKFLOW_ID = 'reconciliation:2026-10-07';

    protected const NIGHTLY_RUN_ID = '1-01JB2M8Q3W';

    #[DataProvider('backends')]
    public function test_wake_is_fenced_with_the_run_id_and_the_attempt_of_the_last_report(string $backend): void
    {
        $this->shop($backend);
        $this->orderOnItsTimer();

        $this->platformWakes();

        $wake = $this->lastDelivered();
        self::assertSame(
            ['wake', self::DEFINITION, self::WORKFLOW_ID, self::RUN_ID, 2],
            [$wake['verb'], $wake['definition'], $wake['workflowId'], $wake['runId'], $wake['attempt']],
        );
    }

    #[DataProvider('backends')]
    public function test_the_endpoint_queues_the_wake(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderOnItsTimer();

        $answer = $this->platformWakes();

        self::assertSame([202, []], [$answer->statusCode, self::fieldsOf($answer)]);
        $bench->dispatcher->assertDispatched(Verb::Wake, self::WORKFLOW_ID);
    }

    #[DataProvider('backends')]
    public function test_the_fence_of_the_wake_lets_a_worker_continue_the_run(string $backend): void
    {
        $this->shop($backend);
        $this->orderOnItsTimer();
        $this->platformWakes();

        [$woken] = $this->work();

        self::assertInstanceOf(Report::class, $woken);
        self::assertSame([Outcome::Completed, 3], [$woken->outcome, $woken->executedAttempt]);
    }

    #[DataProvider('backends')]
    public function test_wake_matches_the_schema_of_a_wake(string $backend): void
    {
        $this->shop($backend);
        $this->orderOnItsTimer();

        $this->platformWakes();

        self::assertSame([], ProtocolSchema::violations('wake', $this->lastDelivered()));
    }

    #[DataProvider('backends')]
    public function test_every_wake_carries_an_invocation_id_of_its_own(string $backend): void
    {
        $this->shop($backend);
        $this->orderOnItsTimer();

        $this->platformWakes();
        $first = $this->lastDelivered();
        $this->platformWakes();
        $second = $this->lastDelivered();

        self::assertNotSame($first['invocationId'], $second['invocationId']);
        self::assertSame([$first['runId'], $first['attempt']], [$second['runId'], $second['attempt']], 'Both carry the same fence.');
    }

    #[DataProvider('backends')]
    public function test_wake_does_not_fence_from_the_report_of_another_workflow_id(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start());
        $this->work();
        $this->deliver($this->start(['invocationId' => 'inv_other', 'workflowId' => 'order:124', 'runId' => '1-another-order']));
        $this->work();
        $bench->iq->workflow(self::DEFINITION, 'order:124')->signal(WaitingWorkflow::EVENT)->dispatch();
        $this->work();

        $this->platformWakes();

        $wake = $this->lastDelivered();
        self::assertSame([self::WORKFLOW_ID, self::RUN_ID, 1], [$wake['workflowId'], $wake['runId'], $wake['attempt']]);
    }

    #[DataProvider('backends')]
    public function test_wake_names_the_definition_of_the_last_report(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start());
        $this->deliver($this->start([
            'invocationId' => 'inv_nightly',
            'definition' => self::NIGHTLY,
            'workflowId' => self::NIGHTLY_WORKFLOW_ID,
            'runId' => self::NIGHTLY_RUN_ID,
        ]));
        $this->work();

        $bench->platform->wake($bench->iq, self::NIGHTLY_WORKFLOW_ID);

        $wake = $this->lastDelivered();
        self::assertSame([self::NIGHTLY, self::NIGHTLY_WORKFLOW_ID, self::NIGHTLY_RUN_ID], [$wake['definition'], $wake['workflowId'], $wake['runId']]);
    }

    #[DataProvider('backends')]
    public function test_wake_fences_from_the_last_report_the_platform_recorded_not_from_one_it_lost(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start());
        $this->work();
        $bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->signal(WaitingWorkflow::EVENT)->dispatch();
        // The announcement and the report of the signal are lost: the run moved on and the platform does not know.
        $bench->platform->failNext(2);
        $this->work();

        $this->platformWakes();

        self::assertSame([self::RUN_ID, 1], [$this->lastDelivered()['runId'], $this->lastDelivered()['attempt']]);
    }

    #[DataProvider('backends')]
    public function test_wake_fails_the_test_when_the_last_report_shows_no_run(string $backend): void
    {
        $this->shop($backend);
        $this->orderOnItsTimer();
        $this->platformWakes();
        $this->work();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no report that shows a run to wake for workflow ID 'order:123'");

        $this->platformWakes();
    }

    #[DataProvider('backends')]
    public function test_wake_fails_the_test_when_no_report_was_recorded_for_the_workflow_id(string $backend): void
    {
        $this->shop($backend);
        // Queued, and no worker has taken it: the platform has heard nothing.
        $this->deliver($this->start());

        $this->expectException(AssertionFailedError::class);

        $this->platformWakes();
    }

    #[DataProvider('backends')]
    public function test_a_wake_that_failed_the_test_was_not_delivered(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start());

        try {
            $this->platformWakes();
        } catch (AssertionFailedError) {
            // What the test of an application would see.
        }

        self::assertCount(1, $bench->platform->delivered());
    }

    protected function shop(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->iq
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions))
            ->register(self::NIGHTLY, static fn (): Workflow => new WaitingWorkflow($executions));

        return $bench;
    }

    /**
     * The platform started the order and the application delivered its payment: the last report shows the run
     * suspended on its timer, at attempt 2.
     */
    protected function orderOnItsTimer(): void
    {
        $this->deliver($this->start());
        $this->work();
        $this->bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID)->signal(WaitingWorkflow::EVENT)->dispatch();
        $this->work();
    }

    protected function platformWakes(): HttpResponse
    {
        return $this->bench->platform->wake($this->bench->iq, self::WORKFLOW_ID);
    }

    /**
     * @return array<string, mixed> the last message the platform delivered to the endpoint
     */
    protected function lastDelivered(): array
    {
        $delivered = $this->bench->platform->delivered();

        return $delivered[count($delivered) - 1]['message'];
    }
}
