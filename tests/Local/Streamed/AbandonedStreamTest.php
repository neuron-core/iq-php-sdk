<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\Cloud\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

use function time;

/**
 * Section 9.6 of the design and row 15 of section 7: a consumer that lets go
 * of a stream while its segment is executing, as a response does when the
 * browser closes. Neuron fails the run as its generator is destroyed, and the
 * SDK then hands the queue one observe that says nothing of how the segment
 * ended: the worker reports what the store shows. Each way PHP has of letting
 * go of a generator is a test of its own.
 */
class AbandonedStreamTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_a_break_out_of_a_foreach_over_the_stream_fails_the_run_at_once(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        foreach ($stage->stream() as $frame) {
            break;
        }

        self::assertSame(self::failedUnder($stage), $stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_a_break_out_of_a_foreach_over_the_stream_hands_the_queue_one_observe_that_carries_no_outcome(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        foreach ($stage->stream() as $frame) {
            break;
        }

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_the_observe_of_a_break_reaches_the_queue_once_neuron_has_failed_the_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $heldWhenObserved = self::noteWhatTheStoreHoldsAtTheObserve($stage);

        foreach ($stage->stream() as $frame) {
            break;
        }

        self::assertSame('failed', $heldWhenObserved()['status'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_a_break_fails_the_run_and_throws_nothing_when_the_queue_refuses_the_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->closeQueueTo(Verb::Observe);

        foreach ($stage->stream() as $frame) {
            break;
        }

        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_kept_in_a_variable_after_a_break_leaves_the_run_executing_under_its_lease(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        $run = $stage->stored();
        self::assertSame(['running', $kind->attempt()], [$run['status'] ?? null, $run['attempt'] ?? null]);
        self::assertGreaterThan(time(), $run['leaseExpiresAt'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_kept_in_a_variable_after_a_break_has_handed_the_queue_no_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        self::assertSame([Verb::Announce], $stage->verbsQueued());
        self::assertTrue($events->valid(), 'The stream that was kept has ended.');
    }

    #[DataProvider('kinds')]
    public function test_a_stream_kept_in_a_variable_after_a_break_can_still_be_taken_to_its_end(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        $taken = $stage->take($events);

        self::assertSame([...TaleWorkflow::OPENING, ...TaleWorkflow::CLOSING], $taken->words());
        self::assertSame(Outcome::Completed->value, $stage->observe()->body['outcome'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_releasing_a_stream_that_was_kept_after_a_break_fails_the_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        unset($events);

        self::assertSame(self::failedUnder($stage), $stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_releasing_a_stream_that_was_kept_after_a_break_hands_the_queue_one_observe_that_carries_no_outcome(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        unset($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_releasing_a_stream_that_was_kept_fails_the_run_and_throws_nothing_when_the_queue_refuses_the_observe(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->closeQueueTo(Verb::Observe);
        $events = $stage->stream();

        foreach ($events as $frame) {
            break;
        }

        unset($events);

        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_goes_out_of_scope_fails_the_run_at_once(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        self::holdTheFirstFrameAndReturn($stage);

        self::assertSame(self::failedUnder($stage), $stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_goes_out_of_scope_hands_the_queue_one_observe_that_carries_no_outcome(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        self::holdTheFirstFrameAndReturn($stage);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_the_observe_of_a_stream_that_went_out_of_scope_reaches_the_queue_once_neuron_has_failed_the_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $heldWhenObserved = self::noteWhatTheStoreHoldsAtTheObserve($stage);

        self::holdTheFirstFrameAndReturn($stage);

        self::assertSame('failed', $heldWhenObserved()['status'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_goes_out_of_scope_fails_the_run_and_throws_nothing_when_the_queue_refuses_the_observe(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->closeQueueTo(Verb::Observe);

        self::holdTheFirstFrameAndReturn($stage);

        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_unsetting_a_stream_that_is_being_taken_fails_the_run_at_once(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame(self::failedUnder($stage), $stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_unsetting_a_stream_that_is_being_taken_hands_the_queue_one_observe_that_carries_no_outcome(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_the_observe_of_a_stream_that_was_unset_reaches_the_queue_once_neuron_has_failed_the_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $heldWhenObserved = self::noteWhatTheStoreHoldsAtTheObserve($stage);
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame('failed', $heldWhenObserved()['status'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_unsetting_a_stream_fails_the_run_and_throws_nothing_when_the_queue_refuses_the_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->closeQueueTo(Verb::Observe);
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_left_in_the_middle_of_its_last_node_is_failed_and_observed_like_one_left_at_its_first_frame(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $events->next();
        $events->next();

        unset($events);

        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    /**
     * Neuron yields the opening frame of a stream adapter before it reports anything about the segment.
     */
    #[DataProvider('kinds')]
    public function test_a_stream_left_on_the_opening_frame_of_its_adapter_is_failed_under_its_attempt_and_observed_with_no_outcome_although_no_node_ran(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter()));
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame(0, $stage->bodiesRun());
        self::assertSame(self::failedUnder($stage), $stage->stored());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    /**
     * Neuron lets the stream adapter close its protocol when it fails an abandoned run, and an adapter can throw there.
     */
    #[DataProvider('kinds')]
    public function test_the_observe_is_handed_over_all_the_same_when_the_stream_adapter_throws_while_neuron_closes_the_abandoned_stream(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $broken = new RuntimeException('The adapter cannot say that the stream failed.');
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(
            static fn (): EchoingAdapter => new class ($broken) extends EchoingAdapter {
                public function __construct(protected RuntimeException $broken)
                {
                    parent::__construct();
                }

                public function error(Throwable $error): iterable
                {
                    throw $this->broken;
                }
            },
        ));
        $events = $stage->stream();
        $events->current();

        $thrownAtTheConsumer = self::caught(static function () use (&$events): void {
            $events = null;
        });

        self::assertSame($broken, $thrownAtTheConsumer, 'Neuron throws what the adapter threw: the SDK hides nothing of it.');
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::abandoned($stage), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_no_node_body_runs_once_the_consumer_has_let_go_of_the_stream(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $bodiesRunWhenLeft = $stage->bodiesRun();

        unset($events);
        $stage->drain();

        self::assertSame([1, 1], [$bodiesRunWhenLeft, $stage->bodiesRun()]);
        self::assertSame([$kind->attempt()], $stage->attemptsOpened());
    }

    /**
     * A consumer written as a function of its own: the stream is a local of that function.
     */
    protected static function holdTheFirstFrameAndReturn(Stage $stage): void
    {
        $events = $stage->stream();
        $events->current();
    }

    /**
     * @return callable(): (array<string, mixed>|null) gives what the store held when the queue was handed the observe
     */
    protected static function noteWhatTheStoreHoldsAtTheObserve(Stage $stage): callable
    {
        $held = null;
        $stage->queue->beforeTaking(static function (Invocation $handed) use ($stage, &$held): void {
            if ($handed->verb === Verb::Observe) {
                $held = $stage->stored();
            }
        });

        return static function () use (&$held): ?array {
            return $held;
        };
    }

    /**
     * The run of the stage as Neuron leaves it when the consumer of its stream lets go: failed under the
     * attempt of the segment, with no lease left to wait out.
     *
     * @return array<string, mixed>
     */
    protected static function failedUnder(Stage $stage): array
    {
        return [
            'runId' => $stage->announce()->runId,
            'status' => 'failed',
            'attempt' => $stage->kind->attempt(),
            'leaseExpiresAt' => null,
        ];
    }

    /**
     * What the observe of an abandoned stream carries: the segment it speaks for, and no outcome.
     *
     * @return array<string, mixed>
     */
    protected static function abandoned(Stage $stage): array
    {
        return [
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $stage->kind->verb()->value,
        ];
    }
}
