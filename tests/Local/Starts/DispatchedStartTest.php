<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Starts;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Flows\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\IQ\Tests\Stub\Flows\OrderWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Support\Flows\StartTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;
use function usleep;

/**
 * start()->dispatch() (section 9.6 of the design): what the request leaves
 * in the queue, and what a worker makes of it.
 */
class DispatchedStartTest extends StartTestCase
{
    protected const INVOCATION_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    protected const MINTED_ON_VERSION_3 = '/^3-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    protected const ONE_HOUR = 3600;

    /** While true, the factory of "order" throws, as when a worker cannot reach what it builds the workflow from. */
    protected bool $factoryFails = false;

    #[DataProvider('backends')]
    public function test_a_dispatched_start_leaves_one_start_of_the_application_in_the_queue(string $backend): void
    {
        $this->benchOn($backend);

        $this->order()->start(new OrderPlaced('777'))->dispatch();

        $queued = $this->onlyQueued();
        self::assertSame(
            [Verb::Start, Origin::APPLICATION, 'order', self::WORKFLOW_ID, null],
            [$queued->verb, $queued->origin, $queued->definition, $queued->workflowId, $queued->attempt],
        );
        self::assertMatchesRegularExpression(self::INVOCATION_ID, $queued->invocationId);
    }

    #[DataProvider('backends')]
    public function test_dispatch_returns_the_run_id_of_the_start_it_queued(string $backend): void
    {
        $this->benchOn($backend);

        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        self::assertSame($this->onlyQueued()->runId, $runId);
    }

    #[DataProvider('backends')]
    public function test_the_run_id_of_a_dispatched_start_is_minted_on_the_current_version(string $backend): void
    {
        $bench = $this->benchOn($backend);
        [$executions, $transcript] = [$bench->executions, $this->transcript];
        $bench->iq->register('order', static fn (): Workflow => new OrderWorkflow($executions, $transcript), version: 3);

        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        self::assertMatchesRegularExpression(self::MINTED_ON_VERSION_3, $runId);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_carries_the_event_it_was_given(string $backend): void
    {
        $this->benchOn($backend);
        $event = new OrderPlaced('777', 'Leave it with the neighbour.');

        $this->order()->start($event)->dispatch();

        self::assertEquals($event, $this->eventCarriedBy($this->onlyQueued()));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_with_no_input_carries_the_event_the_workflow_built_in_the_request(string $backend): void
    {
        $this->benchOn($backend);

        $this->order()->start()->dispatch();

        self::assertEquals(new OrderPlaced(self::WORKFLOW_ID), $this->eventCarriedBy($this->onlyQueued()));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_can_be_used_for_one_hour(string $backend): void
    {
        $this->benchOn($backend);
        $before = time();

        $this->order()->start(new OrderPlaced('777'))->dispatch();

        $useBy = $this->onlyQueued()->expiresAt;
        self::assertGreaterThanOrEqual($before + self::ONE_HOUR, $useBy);
        self::assertLessThanOrEqual(time() + self::ONE_HOUR, $useBy);
    }

    #[DataProvider('backends')]
    public function test_the_hour_of_a_dispatched_start_counts_from_the_dispatch_not_from_the_call_that_prepared_it(string $backend): void
    {
        $this->benchOn($backend);
        $prepared = $this->order()->start(new OrderPlaced('777'));
        $preparedAt = time();

        // The SDK reads time(), so only the real clock can separate the two calls.
        while (time() === $preparedAt) {
            usleep(50_000);
        }
        $prepared->dispatch();

        self::assertGreaterThan($preparedAt + self::ONE_HOUR, $this->onlyQueued()->expiresAt);
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_carries_nothing_a_start_has_no_use_for(string $backend): void
    {
        $this->benchOn($backend);

        $this->order()->start(new OrderPlaced('777'))->dispatch();

        $queued = $this->onlyQueued();
        self::assertSame([null, null, null], [$queued->signal, $queued->payload, $queued->body]);
    }

    #[DataProvider('backends')]
    public function test_the_request_that_dispatches_a_start_reads_the_store_once_and_writes_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        self::assertSame(['get'], $this->storeOperations);
        self::assertNull($this->holder());
        self::assertFalse($this->isClaimed($runId), 'The worker claims the start, not the request.');
        self::assertSame([], $bench->platform->received(), 'A request never calls the platform.');
    }

    #[DataProvider('backends')]
    public function test_the_request_that_dispatches_a_start_executes_no_node(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $this->order()->start(new OrderPlaced('777'))->dispatch();

        self::assertSame(0, $bench->executions->total());
        self::assertSame([], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_worker_ignites_the_run_with_the_event_given_to_start(string $backend): void
    {
        $this->benchOn($backend);
        $this->order()->start(new OrderPlaced('777'))->dispatch();

        $this->workOnce();

        self::assertSame(['placed:777'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_worker_runs_a_dispatched_start_under_the_run_id_the_request_was_given(string $backend): void
    {
        $this->benchOn($backend);
        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        $report = $this->workOnce();

        self::assertSame([Verb::Start, Origin::APPLICATION, Outcome::Suspended, 1], [$report->verb, $report->origin, $report->outcome, $report->executedAttempt]);
        self::assertSame($runId, $report->runId);
        self::assertSame($runId, $this->holder()?->runId);
    }

    #[DataProvider('backends')]
    public function test_the_ledger_holds_the_run_id_of_a_dispatched_start_once_a_worker_ran_it(string $backend): void
    {
        $this->benchOn($backend);
        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        $report = $this->workOnce();

        self::assertTrue($report->claimed);
        self::assertTrue($this->isClaimed($runId));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_delivered_twice_runs_once(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->order()->start(new OrderPlaced('777'))->dispatch();

        [$first, $second] = $this->queue->runAll($bench->iq, 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame([Outcome::Suspended, null], [$first->outcome, $first->code]);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$second->outcome, $second->code]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        self::assertSame(['placed:777'], $this->transcript->lines());
    }

    #[DataProvider('backends')]
    public function test_a_copy_of_a_dispatched_start_delivered_after_its_run_completed_executes_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $bench->iq->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions));
        $bench->iq->workflow('greeting', self::WORKFLOW_ID)->start()->dispatch();

        [$first, $second] = $this->queue->runAll($bench->iq, 2);

        self::assertInstanceOf(Report::class, $first);
        self::assertInstanceOf(Report::class, $second);
        self::assertSame([Outcome::Completed, null], [$first->outcome, $first->run]);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$second->outcome, $second->code]);
        self::assertSame(1, $executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_start_that_reaches_a_worker_past_its_use_by_time_is_rejected_as_expired(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->order()->start(new OrderPlaced('777'))->dispatch();
        $late = Invocation::fromArray(['expiresAt' => time() - 1] + $this->onlyQueued()->toArray());

        $report = $bench->iq->invoke($late);

        self::assertSame([Outcome::Rejected, Code::Expired->value], [$report->outcome, $report->code]);
        self::assertNull($report->run);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_an_expired_dispatched_start_is_neither_claimed_nor_thrown_back_to_the_worker(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();
        $late = Invocation::fromArray(['expiresAt' => time() - 1] + $this->onlyQueued()->toArray());

        $thrown = self::thrownBy(static fn (): Report => $bench->iq->invoke($late));

        self::assertNull($thrown, 'An expired start is dealt with: no worker tries it again.');
        self::assertFalse($this->isClaimed($runId));
    }

    #[DataProvider('abortsBeforeTheClaimOnEveryBackend')]
    public function test_a_dispatched_start_aborted_before_its_claim_is_thrown_back_to_the_worker(string $backend, string $abort, Code $reason): void
    {
        $this->benchOn($backend);
        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();

        [$thrown] = $this->queue->runAll($this->workerThatAborts($abort));

        self::assertInstanceOf(IQException::class, $thrown);
        self::assertSame($reason, $thrown->reason);
        self::assertStringContainsString($runId, $thrown->getMessage(), 'The exception names the run ID it speaks of.');
        self::assertFalse($this->isClaimed($runId));
    }

    #[DataProvider('abortsBeforeTheClaimOnEveryBackend')]
    public function test_a_dispatched_start_aborted_before_its_claim_is_reported_as_aborted(string $backend, string $abort, Code $reason): void
    {
        $this->benchOn($backend);
        $this->order()->start(new OrderPlaced('777'))->dispatch();

        $this->queue->runAll($this->workerThatAborts($abort));

        [$report] = $this->reportsSent();
        self::assertSame(
            [Outcome::Aborted->value, $reason->value, null, null],
            [$report['outcome'], $report['code'], $report['claimed'], $report['run']],
        );
    }

    #[DataProvider('abortsBeforeTheClaimOnEveryBackend')]
    public function test_a_dispatched_start_aborted_before_its_claim_stays_queued_then_runs_once(string $backend, string $abort): void
    {
        $bench = $this->benchOn($backend);
        $runId = $this->order()->start(new OrderPlaced('777'))->dispatch();
        $this->queue->runAll($this->workerThatAborts($abort));

        $this->factoryFails = false;
        $report = $this->workOnce();

        self::assertSame([Outcome::Suspended, $runId, true], [$report->outcome, $report->runId, $report->claimed]);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        self::assertSame(['placed:777'], $this->transcript->lines());
    }

    /**
     * @return iterable<string, array{string, string, Code}> a backend, why the worker cannot run the start yet,
     *                                                        and the reason it is thrown back with
     */
    public static function abortsBeforeTheClaimOnEveryBackend(): iterable
    {
        foreach (self::backends() as $store => [$backend]) {
            yield "a worker that lacks the version, on {$store}" => [$backend, 'unknown version', Code::UnknownVersion];
            yield "a worker that lacks the definition, on {$store}" => [$backend, 'unknown definition', Code::UnknownDefinition];
            yield "a factory that throws, on {$store}" => [$backend, 'factory', Code::NotExecuted];
        }
    }

    /**
     * "order" is registered as version 2 through a factory the test can make throw: the request mints on it.
     */
    protected function benchOn(string $backend): TestBench
    {
        $bench = parent::benchOn($backend);
        [$executions, $transcript] = [$bench->executions, $this->transcript];

        $bench->iq->register(
            'order',
            fn (): Workflow => $this->factoryFails
                ? throw new FactoryFailed('The factory reached a service that is down.')
                : new OrderWorkflow($executions, $transcript),
            version: 2,
        );

        return $bench;
    }

    /**
     * The service of a worker that cannot run the queued start yet: one still
     * running the code of before the deploy, on the same store and queue, or
     * the current one while its factory throws.
     */
    protected function workerThatAborts(string $abort): IQ
    {
        if ($abort === 'factory') {
            $this->factoryFails = true;

            return $this->bench->iq;
        }

        [$executions, $transcript] = [$this->bench->executions, $this->transcript];

        return (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $this->bench->platform))
            ->setPersistence($this->bench->persistence)
            ->setDispatcher($this->queue)
            ->register(
                $abort === 'unknown version' ? 'order' : 'another-order',
                static fn (): Workflow => new OrderWorkflow($executions, $transcript),
            );
    }
}
