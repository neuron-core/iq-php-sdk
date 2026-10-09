<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Faults\StoreFailed;
use NeuronCore\Cloud\Tests\Stub\Streamed\CloseTaleNode;
use NeuronCore\Cloud\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\Cloud\Tests\Stub\Streamed\OpenTaleNode;
use NeuronCore\Cloud\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_slice;
use function time;

/**
 * Row 10 of section 7 of the design, for a stream: the store goes away while
 * a consumer is taking the segment, and stays away. Neuron cannot commit the
 * step of the node that streams, nor the failure, so the run stays "running"
 * under its lease while the observe of a stream taken to its end says
 * "failed": the platform plans from the snapshot, and the lease is what lets
 * the run be finished. A consumer that lets go instead hands an observe that
 * carries no outcome, as for any stream it stops.
 */
class StoreOutageTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_a_store_that_goes_away_under_a_node_lets_it_stream_its_words_then_throws_its_own_exception(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $outage = self::storeGoesAway($stage);

        $taken = $stage->take($events);

        self::assertSame(TaleWorkflow::OPENING, $taken->words());
        self::assertSame($outage, $taken->thrown);
    }

    #[DataProvider('kinds')]
    public function test_the_error_frames_of_the_stream_adapter_name_the_exception_of_the_store(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter()));
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);

        $taken = $stage->take($events);

        self::assertEquals(
            [new ProtocolEvent('error', ['class' => StoreFailed::class]), new ProtocolEvent('closed')],
            array_slice($taken->events(), -2),
        );
    }

    #[DataProvider('kinds')]
    public function test_a_stream_the_store_failed_under_is_observed_once_as_failed_with_the_class_of_the_store_exception(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);

        $stage->take($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Failed->value,
            'code' => null,
            'errorClass' => StoreFailed::class,
            'executedAttempt' => $kind->attempt(),
        ], $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_a_run_whose_failure_could_not_be_written_stays_running_under_its_lease(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);

        $stage->take($events);

        $run = $stage->stored();
        self::assertSame(['running', $kind->attempt()], [$run['status'] ?? null, $run['attempt'] ?? null]);
        self::assertGreaterThan(time(), $run['leaseExpiresAt'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_a_store_that_is_away_when_the_consumer_lets_go_throws_nothing_at_the_consumer(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);

        unset($events);

        self::assertSame('running', $stage->stored()['status'] ?? null, 'Neuron could not write that it failed the run.');
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_let_go_while_the_store_is_away_hands_an_observe_that_carries_no_outcome(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);

        unset($events);

        self::assertSame(
            ['invocationId' => $stage->announce()->body['invocationId'] ?? null, 'verb' => $kind->verb()->value],
            $stage->observe()->body,
        );
    }

    #[DataProvider('kinds')]
    public function test_a_store_that_is_away_at_the_first_step_throws_there_and_no_node_body_runs(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $outage = self::storeGoesAway($stage);

        $thrown = self::caught($events->valid(...));

        self::assertSame($outage, $thrown);
        self::assertSame([0, []], [$stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_could_not_be_admitted_is_observed_as_aborted_before_anything_ran(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        self::storeGoesAway($stage);

        self::caught($events->valid(...));

        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Aborted->value,
            'code' => Code::NotExecuted->value,
            'errorClass' => StoreFailed::class,
            'executedAttempt' => null,
        ], $stage->observe()->body);
    }

    #[DataProvider('backends')]
    public function test_recover_finishes_the_run_once_its_lease_has_passed_and_the_store_is_back(string $backend): void
    {
        $stage = $this->stageWhoseStoreWentAwayUnderItsFirstNode($backend);
        $stage->outliveTheLease();

        $recovered = $stage->thread->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertNull($stage->stored());
    }

    #[DataProvider('backends')]
    public function test_the_recovery_runs_again_only_the_node_whose_step_the_store_had_not_taken(string $backend): void
    {
        $stage = $this->stageWhoseStoreWentAwayUnderItsFirstNode($backend);
        $stage->outliveTheLease();

        $stage->thread->recover();

        self::assertSame(
            [2, 1],
            [$stage->bench->executions->count(OpenTaleNode::class), $stage->bench->executions->count(CloseTaleNode::class)],
        );
    }

    /**
     * From now on every operation the workflows of the stage ask of the store fails, as when a database went away.
     *
     * @return StoreFailed what the store throws
     */
    protected static function storeGoesAway(Stage $stage): StoreFailed
    {
        $outage = new StoreFailed('The store went away.');
        $stage->interceptStore(static fn () => throw $outage);

        return $outage;
    }

    /**
     * A stage for a start, under a lease of one second: the store was away
     * from the first word of the stream to its end, and is back.
     */
    protected function stageWhoseStoreWentAwayUnderItsFirstNode(string $backend): Stage
    {
        $stage = $this->stage($backend, Kind::Start, lease: 1);
        $events = $stage->stream();
        $events->current();
        self::storeGoesAway($stage);
        $stage->take($events);
        $stage->interceptStore(static function (): void {
        });

        return $stage;
    }
}
