<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use Generator;
use JsonException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\Cloud\Tests\Stub\Streamed\Ending;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Tests\Support\Streamed\Taken;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_unique;
use function array_values;
use function gc_collect_cycles;

use const NAN;
use const SORT_REGULAR;

/**
 * Neuron settles a segment before it yields its last frames: the event of
 * the wait, or what the stream adapter says of the end, of the wait or of
 * the failure. A consumer that lets go while it holds one of those frames
 * stopped a stream, not a run: Neuron leaves the run as it settled it.
 * Section 9.6 of the design: the observe says "suspended" or "completed"
 * when Neuron reported that end of the segment, also when the consumer threw
 * into the frame instead of letting go. A failure is not left to the end of
 * the stream: the observe says "failed", with the class of what was thrown,
 * and it is in the queue when the first error frame is given. When a listener
 * of the application hid what Neuron reported, the observe of a consumer
 * that let go carries no outcome, and the worker reports what the store shows.
 */
class LastFrameTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_letting_go_on_the_event_of_the_wait_leaves_the_run_suspended_on_that_wait(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $events = self::takenUpTo($stage->stream(), InterruptEvent::class);
        $heldAtTheLastFrame = $stage->stored();

        unset($events);

        self::assertSame($heldAtTheLastFrame, $stage->stored());
        self::assertSame(['suspended', $kind->attempt()], [$heldAtTheLastFrame['status'] ?? null, $heldAtTheLastFrame['attempt'] ?? null]);
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_event_of_the_wait_is_observed_once_as_suspended(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $events = self::takenUpTo($stage->stream(), InterruptEvent::class);
        $queuedAtTheLastFrame = $stage->verbsQueued();

        unset($events);

        self::assertSame([[Verb::Announce], [Verb::Announce, Verb::Observe]], [$queuedAtTheLastFrame, $stage->verbsQueued()]);
        self::assertSame(self::settledAs($stage, Outcome::Suspended), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_frame_the_stream_adapter_gives_for_the_wait_is_observed_as_suspended(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'interrupt');

        unset($events);

        self::assertSame(self::settledAs($stage, Outcome::Suspended), $stage->observe()->body);
        self::assertSame('suspended', $stage->stored()['status'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_event_of_the_wait_leaves_the_run_suspended_when_the_queue_refuses_the_observe(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $stage->closeQueueTo(Verb::Observe);
        $events = self::takenUpTo($stage->stream(), InterruptEvent::class);

        unset($events);

        self::assertSame('suspended', $stage->stored()['status'] ?? null);
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_recover_has_nothing_to_finish_after_a_stream_that_was_left_on_the_event_of_its_wait(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $events = self::takenUpTo($stage->stream(), InterruptEvent::class);
        unset($events);
        $bodiesRun = $stage->bodiesRun();

        $recovered = $stage->thread->recover();

        self::assertNull($recovered);
        self::assertSame($bodiesRun, $stage->bodiesRun());
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_end_frame_of_a_completed_segment_leaves_no_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'end');
        $heldAtTheLastFrame = $stage->stored();

        unset($events);

        self::assertSame([null, null], [$heldAtTheLastFrame, $stage->stored()]);
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_end_frame_of_a_completed_segment_is_observed_once_as_completed(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'end');
        $queuedAtTheLastFrame = $stage->verbsQueued();

        unset($events);

        self::assertSame([[Verb::Announce], [Verb::Announce, Verb::Observe]], [$queuedAtTheLastFrame, $stage->verbsQueued()]);
        self::assertSame(self::settledAs($stage, Outcome::Completed), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_letting_go_on_the_end_frame_throws_nothing_when_the_queue_refuses_the_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $stage->closeQueueTo(Verb::Observe);
        $events = self::takenUpTo($stage->stream(), 'end');

        unset($events);

        self::assertNull($stage->stored());
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_end_frame_of_a_completed_segment_comes_back_and_leaves_no_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'end');
        $unencodable = new JsonException('The wire cannot carry this event.');

        $cameBack = self::caught(static fn (): mixed => $events->throw($unencodable));

        self::assertSame($unencodable, $cameBack);
        self::assertNull($stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_end_frame_of_a_completed_segment_is_observed_once_as_completed(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'end');

        self::caught(static fn (): mixed => $events->throw(new JsonException('The wire cannot carry this event.')));

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::settledAs($stage, Outcome::Completed), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_frame_of_the_wait_comes_back_and_leaves_the_run_suspended(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'interrupt');
        $unencodable = new JsonException('The wire cannot carry this event.');

        $cameBack = self::caught(static fn (): mixed => $events->throw($unencodable));

        self::assertSame($unencodable, $cameBack);
        self::assertSame(['suspended', $kind->attempt()], [$stage->stored()['status'] ?? null, $stage->stored()['attempt'] ?? null]);
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_frame_of_the_wait_is_observed_once_as_suspended(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'interrupt');

        self::caught(static fn (): mixed => $events->throw(new JsonException('The wire cannot carry this event.')));

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(self::settledAs($stage, Outcome::Suspended), $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_an_end_frame_the_sse_encoder_cannot_write_is_observed_as_completed_and_the_encoder_exception_still_reaches_the_response(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(
            static fn (): EchoingAdapter => new class () extends EchoingAdapter {
                public function end(): iterable
                {
                    return [new ProtocolEvent('end', ['weight' => NAN])];
                }
            },
        ));

        $thrown = self::caught(static fn (): array => [...SSEEncoder::encode($stage->stream())]);

        self::assertInstanceOf(JsonException::class, $thrown);
        self::assertSame(self::settledAs($stage, Outcome::Completed), $stage->observe()->body);
        self::assertNull($stage->stored());
    }

    #[DataProvider('kindsThatThrow')]
    public function test_letting_go_on_an_error_frame_leaves_the_run_failed_as_the_node_left_it(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'error');
        $heldAtTheErrorFrame = $stage->stored();

        unset($events);

        self::assertSame($heldAtTheErrorFrame, $stage->stored());
        self::assertSame(['failed', $kind->attempt()], [$heldAtTheErrorFrame['status'] ?? null, $heldAtTheErrorFrame['attempt'] ?? null]);
    }

    /**
     * Neuron holds what the node threw while it gives the error frames, and where PHP keeps the arguments of a
     * stack trace that exception holds the stream: PHP would destroy a stream left there only when it collects
     * cycles. The observe does not wait for that.
     */
    #[DataProvider('kindsThatThrow')]
    public function test_the_one_observe_of_a_segment_that_fails_is_in_the_queue_when_the_first_error_frame_is_given(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'error');
        $queuedAtTheErrorFrame = $stage->verbsQueued();

        unset($events);

        self::assertSame([[Verb::Announce, Verb::Observe], [Verb::Announce, Verb::Observe]], [$queuedAtTheErrorFrame, $stage->verbsQueued()]);
    }

    #[DataProvider('kindsThatThrow')]
    public function test_a_stream_taken_to_the_exception_has_its_observe_in_the_queue_from_the_first_error_frame_and_not_before(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());

        $taken = $stage->take($stage->stream());

        self::assertInstanceOf(self::thrownBy($ending), $taken->thrown);
        self::assertSame(
            [[[Verb::Announce]], [[Verb::Announce, Verb::Observe]], [[Verb::Announce, Verb::Observe]]],
            [
                array_values(array_unique([...self::queuedAt($taken, 'start'), ...self::queuedAt($taken, 'word')], SORT_REGULAR)),
                self::queuedAt($taken, 'error'),
                self::queuedAt($taken, 'closed'),
            ],
        );
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kindsThatThrow')]
    public function test_the_observe_of_a_stream_left_on_an_error_frame_says_failed_with_the_class_of_what_the_node_threw(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'error');

        unset($events);

        self::assertSame(
            [...self::settledAs($stage, Outcome::Failed), 'errorClass' => self::thrownBy($ending)],
            $stage->observe()->body,
        );
    }

    #[DataProvider('kindsThatThrow')]
    public function test_the_report_of_a_stream_left_on_an_error_frame_says_failed_and_shows_the_run_the_node_failed(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'error');
        unset($events);

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Outcome::Failed, null, self::thrownBy($ending), $kind->attempt()],
            [$report->outcome, $report->code, $report->errorClass, $report->executedAttempt],
        );
        self::assertSame(['failed', $kind->attempt()], [$report->run['status'] ?? null, $report->run['attempt'] ?? null]);
    }

    /**
     * Neuron's encoder throws into the stream the exception of a frame it cannot write, and an error frame may
     * be one. The segment had failed before that, with what the node threw: the observe says so.
     */
    #[DataProvider('kindsThatThrow')]
    public function test_an_exception_thrown_into_an_error_frame_comes_back_and_the_observe_keeps_the_class_of_what_the_node_threw(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $events = self::takenUpTo($stage->stream(), 'error');
        $unencodable = new JsonException('The wire cannot carry this event.');

        $cameBack = self::caught(static fn (): mixed => $events->throw($unencodable));

        self::assertSame($unencodable, $cameBack);
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(
            [...self::settledAs($stage, Outcome::Failed), 'errorClass' => self::thrownBy($ending)],
            $stage->observe()->body,
        );
    }

    #[DataProvider('kindsThatThrow')]
    public function test_the_error_frame_is_still_given_when_the_queue_refuses_the_observe(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());
        $stage->closeQueueTo(Verb::Observe);
        $events = self::takenUpTo($stage->stream(), 'error');

        unset($events);

        self::assertSame('failed', $stage->stored()['status'] ?? null);
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    /**
     * The SDK hears of the failure from Neuron, after the listeners of the application. One of them that throws
     * on that event keeps it from the SDK, which then says nothing of how the segment ended, and not before PHP
     * destroys the stream.
     */
    #[DataProvider('kindsThatThrow')]
    public function test_a_stream_left_on_an_error_frame_under_a_listener_that_throws_at_the_failure_is_reported_as_observed_with_the_failed_run(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => self::heardByAListenerThatThrowsAt(WorkflowError::class)(self::adapted()($workflow)));
        $events = self::takenUpTo($stage->stream(), 'error');
        $queuedAtTheErrorFrame = $stage->verbsQueued();
        unset($events);
        // Where PHP keeps the arguments of a trace, the exception Neuron holds keeps the stream until then.
        gc_collect_cycles();

        [, $report] = $stage->drain();

        self::assertSame([Verb::Announce], $queuedAtTheErrorFrame);
        self::assertSame(self::leftUnsaid($stage), $stage->observe()->body);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Outcome::Observed, null, 'failed', $kind->attempt()],
            [$report->outcome, $report->errorClass, $report->run['status'] ?? null, $report->run['attempt'] ?? null],
        );
    }

    /**
     * Neuron reports a listener that threw with the event it reports a failed segment with, and the segment
     * goes on: the store, which the SDK asks, does not show the run failed.
     */
    #[DataProvider('kindsAndEndings')]
    public function test_a_listener_that_throws_at_the_start_of_the_segment_is_not_taken_for_a_failure_of_the_segment(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => self::heardByAListenerThatThrowsAt(WorkflowStart::class)(self::adapted()($workflow)));

        $taken = $stage->take($stage->stream());

        self::assertSame([[Verb::Announce]], array_values(array_unique(self::queuedAt($taken, 'word'), SORT_REGULAR)));
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(
            [self::outcomeOf($ending)->value, $kind->attempt()],
            [$stage->observe()->body['outcome'] ?? null, $stage->observe()->body['executedAttempt'] ?? null],
        );
    }

    /**
     * Neuron reports the end of a segment to the listeners of the workflow,
     * and the SDK hears there that a stream left on its last frame had
     * suspended or completed. A listener of the application that throws on
     * that event keeps it from the SDK, which then says nothing of how the
     * segment ended: the platform hears what the store shows.
     */
    #[DataProvider('kinds')]
    public function test_a_stream_left_on_its_wait_under_a_listener_that_throws_at_the_end_is_reported_as_observed_with_the_suspended_run(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $stage->thread->configure(self::heardByAListenerThatThrowsAtTheEnd());
        $events = self::takenUpTo($stage->stream(), InterruptEvent::class);
        unset($events);

        [, $report] = $stage->drain();

        self::assertSame(self::leftUnsaid($stage), $stage->observe()->body);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Outcome::Observed, 'suspended', $kind->attempt()],
            [$report->outcome, $report->run['status'] ?? null, $report->run['attempt'] ?? null],
        );
    }

    #[DataProvider('kinds')]
    public function test_a_stream_left_on_its_end_frame_under_a_listener_that_throws_at_the_end_is_reported_as_observed_with_no_run(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => self::heardByAListenerThatThrowsAtTheEnd()(self::adapted()($workflow)));
        $events = self::takenUpTo($stage->stream(), 'end');
        unset($events);

        [, $report] = $stage->drain();

        self::assertSame(self::leftUnsaid($stage), $stage->observe()->body);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Observed, null, true], [$report->outcome, $report->run, $report->snapshotRead]);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_listener_that_throws_at_the_end_of_the_segment_changes_nothing_for_a_stream_taken_to_its_end(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::heardByAListenerThatThrowsAtTheEnd());

        $stage->take($stage->stream());

        self::assertSame(
            [self::outcomeOf($ending)->value, $kind->attempt()],
            [$stage->observe()->body['outcome'] ?? null, $stage->observe()->body['executedAttempt'] ?? null],
        );
    }

    /**
     * @return callable(Workflow): Workflow what a streaming endpoint gives configure()
     */
    protected static function adapted(): callable
    {
        return static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter());
    }

    /**
     * @return callable(Workflow): Workflow a callback that hooks a failing monitor onto the workflow
     */
    protected static function heardByAListenerThatThrowsAtTheEnd(): callable
    {
        return self::heardByAListenerThatThrowsAt(WorkflowEnd::class);
    }

    /**
     * @param class-string $event what Neuron reports to the listeners of the workflow
     * @return callable(Workflow): Workflow a callback that hooks a monitor that fails on that event onto the workflow
     */
    protected static function heardByAListenerThatThrowsAt(string $event): callable
    {
        return static fn (Workflow $workflow): Workflow => $workflow->subscribe(
            $event,
            static fn () => throw new RuntimeException('The monitoring of the application is down.'),
        );
    }

    /**
     * @return class-string what the last node throws when it ends the segment that way
     */
    protected static function thrownBy(Ending $ending): string
    {
        return $ending === Ending::Refuses ? WorkflowRefusedException::class : NodeFailed::class;
    }

    /**
     * Take a stream up to one of its last frames, and stop there with the frame in hand.
     *
     * @param Generator<int, object, mixed, WorkflowState> $events
     * @param string $frame the class of the event to stop on, or the type of the frame of a stream adapter
     * @return Generator<int, object, mixed, WorkflowState> the stream, standing on that frame
     */
    protected static function takenUpTo(Generator $events, string $frame): Generator
    {
        while ($events->valid()) {
            $current = $events->current();

            if ($current instanceof $frame || ($current instanceof ProtocolEvent && $current->type === $frame)) {
                return $events;
            }

            $events->next();
        }

        self::fail("The stream ended without giving '{$frame}'.");
    }

    /**
     * @return array<int, Verb[]> what the queue held each time the consumer held a frame of that type
     */
    protected static function queuedAt(Taken $taken, string $frame): array
    {
        $queued = [];

        foreach ($taken->events() as $position => $event) {
            if ($event instanceof ProtocolEvent && $event->type === $frame) {
                $queued[] = $taken->notedAtEachFrame[$position];
            }
        }

        return $queued;
    }

    /**
     * What the observe of a stream carries when its consumer stopped before
     * the SDK heard from Neuron how the segment ended: the segment it speaks
     * for, and no outcome.
     *
     * @return array<string, mixed>
     */
    protected static function leftUnsaid(Stage $stage): array
    {
        return [
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $stage->kind->verb()->value,
        ];
    }

    /**
     * What the observe of a segment that Neuron reported as suspended or completed carries, and all but the class for one it failed.
     *
     * @return array<string, mixed>
     */
    protected static function settledAs(Stage $stage, Outcome $outcome): array
    {
        return [
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $stage->kind->verb()->value,
            'outcome' => $outcome->value,
            'code' => null,
            'errorClass' => null,
            'executedAttempt' => $stage->kind->attempt(),
        ];
    }
}
