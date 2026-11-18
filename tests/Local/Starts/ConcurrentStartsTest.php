<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Starts;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\IQ\Tests\Support\Flows\ApplicationCall;
use NeuronCore\IQ\Tests\Support\Flows\LocalProcessBench;
use NeuronCore\IQ\Tests\Support\Flows\LocalProcessTestCase;
use NeuronCore\IQ\Verb;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function ksort;
use function range;

/**
 * Starts of one workflow ID that separate processes make at the same moment:
 * requests that run the first segment themselves, and a worker that runs a
 * start a request dispatched. One of them ignites the run; the others are
 * told that a run holds the workflow ID.
 */
class ConcurrentStartsTest extends LocalProcessTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const REQUESTS = 16;

    protected const ROUNDS = 3;

    public function test_of_many_requests_that_start_one_workflow_id_at_once_one_gets_the_state_and_the_others_a_run_in_flight_refusal(): void
    {
        // Every round is another workflow ID in the same store, so the requests race on a store that is not empty too.
        foreach (range(1, self::ROUNDS) as $round) {
            $results = $this->requestsStartAtOnce("order:{$round}");

            self::assertSame(
                ['returned: ' . WorkflowStatus::Suspended->value => 1, 'thrown: ' . RefusalReason::RunInFlight->value => self::REQUESTS - 1],
                $this->tally($results),
            );
        }
    }

    public function test_of_many_requests_that_start_one_workflow_id_at_once_one_executes_the_first_node(): void
    {
        $results = $this->requestsStartAtOnce(self::WORKFLOW_ID);

        [$winner] = array_values(array_filter(array_column($results, 'returned')));
        self::assertSame(1, $this->bench->executions->count(AwaitPaymentNode::class));
        self::assertSame(["placed:{$winner['orderId']}"], $this->bench->transcript->lines(), 'The run was ignited with the event of the request that got the state.');
    }

    public function test_the_requests_that_lose_the_start_are_refused_with_the_run_of_the_one_that_won(): void
    {
        $results = $this->requestsStartAtOnce(self::WORKFLOW_ID);

        [$winner] = array_values(array_filter(array_column($results, 'returned')));
        $holders = array_values(array_unique(array_column(array_column($results, 'thrown'), 'holder')));
        self::assertSame([$winner['runId']], $holders);
        self::assertSame($winner['runId'], $this->bench->run(self::WORKFLOW_ID)?->runId);
    }

    public function test_the_requests_that_start_at_once_call_no_platform_and_leave_their_two_notices_in_the_queue(): void
    {
        $this->requestsStartAtOnce(self::WORKFLOW_ID);

        self::assertSame([], $this->bench->platform->announcements());
        self::assertSame([], $this->bench->platform->reports());
        self::assertSame(
            [Verb::Announce->value => self::REQUESTS, Verb::Observe->value => self::REQUESTS],
            array_count_values(array_map(static fn (Invocation $notice): string => $notice->verb->value, $this->bench->queue->take())),
        );
    }

    public function test_the_platform_hears_of_every_request_that_started_at_once_and_of_the_one_run_they_left(): void
    {
        $this->requestsStartAtOnce(self::WORKFLOW_ID);

        $this->bench->work();

        $reports = $this->bench->platform->reports();
        self::assertCount(self::REQUESTS, $this->bench->platform->announcements());
        self::assertSame(
            [Outcome::Refused->value => self::REQUESTS - 1, Outcome::Suspended->value => 1],
            $this->sorted(array_count_values(array_column($reports, 'outcome'))),
        );
        self::assertSame(
            [$this->bench->run(self::WORKFLOW_ID)?->runId],
            array_values(array_unique(array_column(array_column($reports, 'run'), 'runId'))),
            'Every report shows the run that holds the workflow ID.',
        );
    }

    public function test_a_request_that_starts_a_workflow_id_while_a_worker_is_inside_a_dispatched_start_of_it_is_refused_as_run_in_flight(): void
    {
        $queued = $this->dispatchAStart('from the queue');
        $worker = $this->bench->process(ApplicationCall::invoke($queued));
        $worker->act();
        $this->bench->awaitExecutions(AwaitPaymentNode::class, 1);

        $request = $this->bench->process(ApplicationCall::startAndRun(LocalProcessBench::GATED_ORDER, self::WORKFLOW_ID, 'from the request'));
        $request->act();
        $refused = $request->result();
        $this->bench->gate->open();

        self::assertSame('thrown: ' . RefusalReason::RunInFlight->value, $this->ending($refused));
        self::assertSame($queued->runId, $refused['thrown']['holder']);
        self::assertSame('reported: ' . Outcome::Suspended->value, $this->ending($worker->result()));
        self::assertSame(['placed:from the queue'], $this->bench->transcript->lines());
    }

    public function test_a_worker_that_gets_a_dispatched_start_while_a_request_is_inside_a_start_of_the_same_workflow_id_reports_it_refused(): void
    {
        $queued = $this->dispatchAStart('from the queue');
        $request = $this->bench->process(ApplicationCall::startAndRun(LocalProcessBench::GATED_ORDER, self::WORKFLOW_ID, 'from the request'));
        $request->act();
        $this->bench->awaitExecutions(AwaitPaymentNode::class, 1);

        $worker = $this->bench->process(ApplicationCall::invoke($queued));
        $worker->act();
        $refused = $worker->result();
        $this->bench->gate->open();

        self::assertSame('reported: ' . Outcome::Refused->value . ': ' . RefusalReason::RunInFlight->value, $this->ending($refused));
        self::assertTrue($refused['reported']['claimed'], 'The start that lost can never run later.');
        self::assertSame('returned: ' . WorkflowStatus::Suspended->value, $this->ending($request->result()));
        self::assertSame(['placed:from the request'], $this->bench->transcript->lines());
    }

    public function test_a_request_and_a_worker_that_start_one_workflow_id_at_once_ignite_one_run(): void
    {
        foreach (range(1, self::ROUNDS * 2) as $round) {
            $workflowId = "order:{$round}";
            $queued = $this->dispatchAStart('from the queue', LocalProcessBench::ORDER, $workflowId);

            $results = $this->bench->atOnce(
                ApplicationCall::invoke($queued),
                ApplicationCall::startAndRun(LocalProcessBench::ORDER, $workflowId, 'from the request'),
            );

            self::assertContains($this->tally($results), [
                ['reported: suspended' => 1, 'thrown: run_in_flight' => 1],
                ['reported: refused: run_in_flight' => 1, 'returned: suspended' => 1],
            ]);
            self::assertSame($round, $this->bench->executions->count(AwaitPaymentNode::class), 'The first node runs once per workflow ID.');
        }
    }

    /**
     * @return array<int, array<string, mixed>> what start(new OrderPlaced(...))->run() returned or threw in each request
     */
    protected function requestsStartAtOnce(string $workflowId): array
    {
        return $this->bench->atOnce(...array_map(
            static fn (int $request): array => ApplicationCall::startAndRun(LocalProcessBench::ORDER, $workflowId, "{$workflowId}/{$request}"),
            range(1, self::REQUESTS),
        ));
    }

    /**
     * A request dispatches a start, which is then the only message that waits in the queue for a worker.
     */
    protected function dispatchAStart(
        string $orderId,
        string $definition = LocalProcessBench::GATED_ORDER,
        string $workflowId = self::WORKFLOW_ID,
    ): Invocation {
        $this->bench->work();
        $this->bench->iq->workflow($definition, $workflowId)->start(new OrderPlaced($orderId))->dispatch();
        [$queued] = $this->bench->queue->take();

        return $queued;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    protected function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
