<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use Generator;
use JsonException;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\Cloud\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function json_decode;
use function substr;

/**
 * An exception thrown into the stream with throw() is not an early stop: it
 * is forwarded to Neuron, as Neuron's own SSE encoder expects when it meets
 * an event the wire cannot carry. While the segment is executing, Neuron
 * fails the run for it, lets the stream adapter close its protocol, and
 * throws it back. The SDK observes the segment as failed with the class of
 * that exception, and hands the observe over when Neuron gives the first
 * error frame, or throws back when it has none to give. LastFrameTest has
 * the exception thrown into one of the last frames of a segment that had
 * already suspended or completed.
 */
class ThrownIntoTheStreamTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_stream_comes_back_to_the_consumer_as_it_is(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $unencodable = new JsonException('The wire cannot carry this event.');

        $cameBack = self::thrownInto($events, $unencodable);

        self::assertSame($unencodable, $cameBack);
    }

    #[DataProvider('kinds')]
    public function test_the_error_frames_of_the_stream_adapter_still_arrive_before_the_exception_comes_back(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = $stage->stream();
        $events->current();
        $unencodable = new JsonException('The wire cannot carry this event.');

        $first = $events->throw($unencodable);
        $events->next();
        $second = $events->current();
        $cameBack = self::caught($events->next(...));

        self::assertEquals(
            [new ProtocolEvent('error', ['class' => JsonException::class]), new ProtocolEvent('closed')],
            [$first, $second],
        );
        self::assertSame($unencodable, $cameBack);
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_stream_fails_the_run(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        self::thrownInto($events, new JsonException('The wire cannot carry this event.'));

        $run = $stage->stored();
        self::assertSame(['failed', $kind->attempt(), null], [$run['status'] ?? null, $run['attempt'] ?? null, $run['leaseExpiresAt'] ?? null]);
    }

    #[DataProvider('kinds')]
    public function test_an_exception_thrown_into_the_stream_is_observed_once_as_failed_with_its_class(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        self::thrownInto($events, new JsonException('The wire cannot carry this event.'));

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Failed->value,
            'code' => null,
            'errorClass' => JsonException::class,
            'executedAttempt' => $kind->attempt(),
        ], $stage->observe()->body);
    }

    #[DataProvider('kinds')]
    public function test_the_observe_is_handed_over_once_when_the_first_error_frame_is_given_and_not_when_the_exception_comes_back(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = $stage->stream();
        $events->current();
        $queuedBefore = $stage->verbsQueued();

        $events->throw(new JsonException('The wire cannot carry this event.'));
        $queuedAtTheFirstErrorFrame = $stage->verbsQueued();
        $events->next();
        $queuedAtTheLastErrorFrame = $stage->verbsQueued();
        self::caught($events->next(...));

        self::assertSame(
            [[Verb::Announce], [Verb::Announce, Verb::Observe], [Verb::Announce, Verb::Observe]],
            [$queuedBefore, $queuedAtTheFirstErrorFrame, $queuedAtTheLastErrorFrame],
        );
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_the_run_is_failed_in_the_store_before_the_first_error_frame_is_given(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = $stage->stream();
        $events->current();

        $events->throw(new JsonException('The wire cannot carry this event.'));

        self::assertSame('failed', $stage->stored()['status'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_an_exception_was_thrown_into_is_over_and_runs_no_other_node_body(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        self::thrownInto($events, new JsonException('The wire cannot carry this event.'));

        self::assertFalse($events->valid());
        self::assertSame([1, [$kind->attempt()]], [$stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('kinds')]
    public function test_the_exception_still_comes_back_and_the_run_is_failed_when_the_queue_refuses_the_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->closeQueueTo(Verb::Observe);
        $events = $stage->stream();
        $events->current();
        $unencodable = new JsonException('The wire cannot carry this event.');

        $cameBack = self::thrownInto($events, $unencodable);

        self::assertSame($unencodable, $cameBack);
        self::assertSame(['failed', [Verb::Announce]], [$stage->stored()['status'] ?? null, $stage->verbsQueued()]);
    }

    #[DataProvider('kinds')]
    public function test_releasing_a_stream_an_exception_was_thrown_into_before_it_came_back_leaves_the_one_observe_that_says_failed_with_its_class(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = $stage->stream();
        $events->current();
        $events->throw(new JsonException('The wire cannot carry this event.'));

        unset($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame([
            'invocationId' => $stage->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Failed->value,
            'code' => null,
            'errorClass' => JsonException::class,
            'executedAttempt' => $kind->attempt(),
        ], $stage->observe()->body);
    }

    /**
     * The consumer did not wait for Neuron to throw the exception back, and the SDK did not wait for the
     * consumer: Neuron had said that the segment failed, and with what.
     */
    #[DataProvider('kinds')]
    public function test_the_report_of_a_stream_released_on_the_error_frame_of_an_exception_thrown_in_says_failed_and_shows_the_failed_run(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted());
        $events = $stage->stream();
        $events->current();
        $events->throw(new JsonException('The wire cannot carry this event.'));
        unset($events);

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Failed, JsonException::class, $kind->attempt()], [$report->outcome, $report->errorClass, $report->executedAttempt]);
        self::assertSame(['failed', $kind->attempt()], [$report->run['status'] ?? null, $report->run['attempt'] ?? null]);
    }

    /**
     * The endpoint of example (b) of the design, with the SDK in place of
     * the direct call: Neuron's encoder throws into the stream the exception
     * of a frame it cannot write.
     */
    #[DataProvider('kinds')]
    public function test_the_sse_encoder_of_neuron_closes_the_protocol_through_the_stream_when_it_cannot_write_a_frame(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted(unencodable: TaleWorkflow::OPENING[1]));
        $sent = [];

        try {
            foreach (SSEEncoder::encode($stage->stream()) as $frame) {
                $sent[] = json_decode(substr($frame, 6), true)['type'] ?? null;
            }
        } catch (JsonException) {
            $sent[] = 'the exception of the encoder';
        }

        self::assertSame(['start', 'word', 'error', 'closed', 'the exception of the encoder'], $sent);
    }

    #[DataProvider('kinds')]
    public function test_a_frame_the_sse_encoder_cannot_write_is_observed_as_a_failure_with_the_class_of_the_encoder_exception(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(self::adapted(unencodable: TaleWorkflow::OPENING[1]));

        self::caught(static fn (): array => [...SSEEncoder::encode($stage->stream())]);

        self::assertSame(
            [Outcome::Failed->value, JsonException::class, $kind->attempt()],
            [
                $stage->observe()->body['outcome'] ?? null,
                $stage->observe()->body['errorClass'] ?? null,
                $stage->observe()->body['executedAttempt'] ?? null,
            ],
        );
        self::assertSame('failed', $stage->stored()['status'] ?? null);
    }

    /**
     * @param string|null $unencodable a word whose frame no JSON encoder can write
     * @return callable(Workflow): Workflow what a streaming endpoint gives configure()
     */
    protected static function adapted(?string $unencodable = null): callable
    {
        return static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(
            static fn (): EchoingAdapter => new EchoingAdapter($unencodable),
        );
    }

    /**
     * Throw into a stream that has no stream adapter: with no error frame to give, Neuron throws back at once.
     *
     * @param Generator<int, object, mixed, WorkflowState> $events
     */
    protected static function thrownInto(Generator $events, Throwable $exception): ?Throwable
    {
        return self::caught(static fn (): mixed => $events->throw($exception));
    }
}
