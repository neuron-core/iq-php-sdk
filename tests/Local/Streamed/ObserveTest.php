<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Streamed\Ending;
use NeuronCore\Cloud\Tests\Support\Faults\StoredRun;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_fill;
use function array_keys;
use function count;

/**
 * Section 9.6 of the design: a stream that its consumer takes to the end
 * leaves in the queue what run() leaves there, an announcement and then one
 * observe that says how the segment ended. The observe is handed over when
 * the stream ends: once the last frame was given, and the store holds what
 * the segment left. A segment that fails under a stream adapter does not
 * wait for the end: LastFrameTest has its observe in the queue when the
 * first error frame is given.
 */
class ObserveTest extends StreamedTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_a_stream_taken_to_its_end_hands_the_queue_one_announcement_then_one_observe(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $stage->take($stage->stream());

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_releasing_a_stream_that_was_taken_to_its_end_queues_nothing_more(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $events = $stage->stream();
        $stage->take($events);

        unset($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_is_not_in_the_queue_while_the_consumer_holds_a_frame_the_last_one_included(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertGreaterThanOrEqual(3, count($taken->frames));
        self::assertSame(array_fill(0, count($taken->frames), [Verb::Announce]), $taken->notedAtEachFrame);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_reaches_the_queue_after_the_last_node_body_ran(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $bodiesRunBefore = $stage->bench->executions->total();

        $stage->take($stage->stream());

        self::assertSame(2, $stage->bodiesRun(), 'The segment runs the first node and the last one.');
        self::assertSame($bodiesRunBefore + 2, $stage->queue->bodiesRunBefore()[count($stage->queue->taken()) - 1]);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_reaches_the_queue_once_the_store_holds_what_the_segment_left(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $heldWhenObserved = false;
        $stage->queue->beforeTaking(static function (Invocation $handed) use ($stage, &$heldWhenObserved): void {
            if ($handed->verb === Verb::Observe) {
                $heldWhenObserved = $stage->stored();
            }
        });

        $stage->take($stage->stream());

        self::assertSame($stage->stored(), $heldWhenObserved);
        self::assertSame(
            self::outcomeOf($ending) === Outcome::Completed ? null : self::outcomeOf($ending)->value,
            $heldWhenObserved['status'] ?? null,
        );
    }

    #[DataProvider('kindsThatThrow')]
    public function test_the_observe_is_in_the_queue_when_the_exception_reaches_the_consumer(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $events = $stage->stream();
        $queuedWhenThrown = null;

        try {
            $events->valid();
            $events->next();
            $events->next();
            $events->next();
        } catch (NodeFailed|WorkflowRefusedException) {
            $queuedWhenThrown = $stage->verbsQueued();
        }

        self::assertSame([Verb::Announce, Verb::Observe], $queuedWhenThrown);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_says_how_the_stream_ended_field_by_field(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $stage->take($stage->stream());

        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => self::outcomeOf($ending)->value,
            'code' => null,
            'errorClass' => match ($ending) {
                Ending::Fails => NodeFailed::class,
                Ending::Refuses => WorkflowRefusedException::class,
                Ending::Completes, Ending::Waits => null,
            },
            'executedAttempt' => $kind->attempt(),
        ], $stage->observe()->body);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_carries_what_the_observe_of_run_carries_for_the_same_segment(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $ofRun = $this->observeOfRun($backend, $kind, $ending)->body ?? [];

        $stage->take($stage->stream());

        $ofTheStream = $stage->observe()->body ?? [];
        // Each observe names its own segment: every other field says how that segment ended.
        unset($ofRun['invocationId'], $ofTheStream['invocationId']);
        self::assertSame($ofRun, $ofTheStream);
        self::assertSame(['verb', 'outcome', 'code', 'errorClass', 'executedAttempt'], array_keys($ofTheStream));
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_travels_as_a_message_of_the_application_about_the_run_and_carries_no_input(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        $observe = $stage->observe();
        self::assertSame([
            'invocationId' => $observe->invocationId,
            'verb' => Verb::Observe->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $taken),
            'attempt' => null,
            'expiresAt' => null,
            'signal' => null,
            'payload' => null,
            'event' => null,
            'body' => $observe->body,
        ], $observe->toArray());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_carries_the_attempt_neuron_opened_the_segment_under(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $stage->take($stage->stream());

        self::assertCount(1, $stage->attemptsOpened());
        self::assertSame($stage->attemptsOpened()[0], $stage->observe()->body['executedAttempt'] ?? null);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_worker_that_runs_the_queue_reports_the_outcome_of_the_observe_with_the_run_the_store_holds(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->take($stage->stream());

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [self::outcomeOf($ending), $kind->attempt(), true],
            [$report->outcome, $report->executedAttempt, $report->snapshotRead],
        );
        self::assertTrue(StoredRun::same($stage->stored(), $report->run));
    }

    #[DataProvider('backends')]
    public function test_a_stream_that_loses_its_run_while_a_node_streams_throws_the_refusal_of_neuron_at_its_next_write(string $backend): void
    {
        $stage = $this->stageWhoseRunIsTakenOverMidStream($backend);

        $taken = $stage->take($stage->stream());

        self::assertInstanceOf(WorkflowRefusedException::class, $taken->thrown);
        self::assertSame(RefusalReason::StaleAttempt, $taken->thrown->reason);
        self::assertCount(2, $taken->frames, 'The node in flight streamed its two words before its step was refused.');
    }

    #[DataProvider('backends')]
    public function test_a_stream_that_lost_its_run_is_observed_as_refused_under_the_attempt_it_executed(string $backend): void
    {
        $stage = $this->stageWhoseRunIsTakenOverMidStream($backend);

        $stage->take($stage->stream());

        self::assertSame(
            [Outcome::Refused->value, RefusalReason::StaleAttempt->value, null, Kind::Answer->attempt()],
            [
                $stage->observe()->body['outcome'] ?? null,
                $stage->observe()->body['code'] ?? null,
                $stage->observe()->body['errorClass'] ?? null,
                $stage->observe()->body['executedAttempt'] ?? null,
            ],
        );
    }

    /**
     * The same segment on a store of its own, run eagerly: the observe run() left in its queue.
     */
    protected function observeOfRun(string $backend, Kind $kind, Ending $ending): Invocation
    {
        $stage = $this->sameStageApart($backend, $kind, $ending);

        try {
            $stage->prepare()->run();
        } catch (NodeFailed|WorkflowRefusedException) {
            // What run() throws is not the subject: its observe is.
        }

        return $stage->observe();
    }

    /**
     * A stage for an answer, under a lease of one second. Between the two
     * words of the first node the lease passes, and another process finishes the run.
     */
    protected function stageWhoseRunIsTakenOverMidStream(string $backend): Stage
    {
        $stage = $this->stage($backend, Kind::Answer, lease: 1);
        $stage->midStream->whenReached(static function () use ($stage): void {
            $stage->outliveTheLease();
            $stage->elsewhere()->recover();
        });

        return $stage;
    }
}
