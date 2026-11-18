<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Streamed\FailedUse;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Section 4 of the design: a PendingSegment is used once, and events() is a
 * use like run() and dispatch(). It counts from the call, whatever becomes of
 * the generator: taken to its end, left in the middle, or never taken at all.
 */
class SingleUseTest extends StreamedTestCase
{
    use CatchesIQExceptions;

    #[DataProvider('twoUses')]
    public function test_a_second_use_of_a_segment_is_refused_as_segment_used_when_one_of_the_two_is_a_stream(
        string $backend,
        Kind $kind,
        string $first,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $segment->{$first}();

        $reason = self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
    }

    #[DataProvider('twoUses')]
    public function test_a_second_use_queues_nothing_executes_nothing_and_asks_nothing_of_the_store(
        string $backend,
        Kind $kind,
        string $first,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $segment->{$first}();
        $left = [$stage->verbsQueued(), $stage->bodiesRun(), $stage->journal->exposedTo(FaultMode::BeforeOperation)];

        self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame($left, [$stage->verbsQueued(), $stage->bodiesRun(), $stage->journal->exposedTo(FaultMode::BeforeOperation)]);
    }

    /**
     * The flag that makes a segment single-use must follow the run ID, not
     * the object: a copy made before the first use holds the same run ID.
     */
    #[DataProvider('twoUses')]
    public function test_a_copy_of_a_segment_made_before_its_first_use_is_used_up_with_it(
        string $backend,
        Kind $kind,
        string $first,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $copy = clone $segment;
        $segment->{$first}();
        $bodiesRun = $stage->bodiesRun();

        $reason = self::reasonThrownBy(static fn (): mixed => $copy->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
        self::assertSame($bodiesRun, $stage->bodiesRun(), 'The copy executed the run ID of the segment a second time.');
    }

    #[DataProvider('twoUses')]
    public function test_a_segment_is_used_up_by_the_first_use_of_its_copy(string $backend, Kind $kind, string $first, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $copy = clone $segment;
        $copy->{$first}();

        $reason = self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
    }

    #[DataProvider('secondUses')]
    public function test_a_segment_cannot_be_used_again_while_its_stream_is_being_taken(string $backend, Kind $kind, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $events = $segment->events();
        $events->current();

        $reason = self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
        self::assertTrue($events->valid(), 'The second use ended the stream that was being taken.');
    }

    #[DataProvider('secondUses')]
    public function test_a_stream_goes_on_to_its_end_after_a_second_use_of_its_segment_was_refused(string $backend, Kind $kind, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $events = $segment->events();
        $events->current();
        self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        $taken = $stage->take($events);

        self::assertSame([...TaleWorkflow::OPENING, ...TaleWorkflow::CLOSING], $taken->words());
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('secondUses')]
    public function test_a_segment_cannot_be_used_again_once_its_stream_was_taken_to_its_end(string $backend, Kind $kind, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->take($segment->events());
        $bodiesRun = $stage->bodiesRun();

        $reason = self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
        self::assertSame($bodiesRun, $stage->bodiesRun());
    }

    #[DataProvider('failedFirstUses')]
    public function test_a_segment_whose_only_use_failed_is_used_all_the_same(string $backend, Kind $kind, FailedUse $failure, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $this->segmentWhoseOnlyUseFailed($stage, $failure);

        $reason = self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame(Code::SegmentUsed, $reason);
    }

    #[DataProvider('failedFirstUses')]
    public function test_the_use_that_follows_a_failed_one_queues_nothing_and_executes_nothing(
        string $backend,
        Kind $kind,
        FailedUse $failure,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind);
        $segment = $this->segmentWhoseOnlyUseFailed($stage, $failure);
        $left = [$stage->verbsQueued(), $stage->bodiesRun(), $stage->attemptsOpened()];

        self::reasonThrownBy(static fn (): mixed => $segment->{$second}());

        self::assertSame($left, [$stage->verbsQueued(), $stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_was_streamed_does_not_use_up_the_next_start_of_the_same_workflow(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $first = $stage->take($stage->stream());

        $next = $stage->take($stage->stream());

        self::assertSame(['completed', 'completed'], [$first->returned?->getStatus()->value, $next->returned?->getStatus()->value]);
        self::assertNotSame($first->returned?->getRunId(), $next->returned?->getRunId());
    }

    /**
     * @return array<string, array{string, Kind, string, string}> the method of the first use, and of the second:
     *         one of the two is events()
     */
    public static function twoUses(): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            foreach ([['events', 'events'], ['events', 'run'], ['events', 'dispatch'], ['run', 'events'], ['dispatch', 'events']] as [$first, $second]) {
                $rows["{$kind->label()}: {$first}(), then {$second}()"] = [$kind, $first, $second];
            }
        }

        return self::onBothBackends($rows);
    }

    /**
     * @return array<string, array{string, Kind, string}> the method called on a segment that was already streamed
     */
    public static function secondUses(): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            foreach (['events', 'run', 'dispatch'] as $second) {
                $rows["{$kind->label()}, then {$second}()"] = [$kind, $second];
            }
        }

        return self::onBothBackends($rows);
    }

    /**
     * @return array<string, array{string, Kind, FailedUse, string}> how the only use failed, and the method called next
     */
    public static function failedFirstUses(): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            foreach (FailedUse::cases() as $failure) {
                foreach ($failure->streams() ? ['events', 'run', 'dispatch'] : ['events'] as $second) {
                    $rows["{$kind->label()}: {$failure->label()}, then {$second}()"] = [$kind, $failure, $second];
                }
            }
        }

        return self::onBothBackends($rows);
    }

    /**
     * Prepare the segment of the stage and use it once, in a way that fails. The queue is up again afterwards.
     */
    protected function segmentWhoseOnlyUseFailed(Stage $stage, FailedUse $failure): PendingSegment
    {
        if ($failure === FailedUse::StreamMisconfigured) {
            $stage->thread->configure(static fn () => throw new RuntimeException('The response of this request is already closed.'));
        }

        $segment = $stage->prepare();

        if ($failure === FailedUse::StreamWhoseNodeThrew) {
            $stage->midStream->whenReached(static fn () => throw new NodeFailed('The node failed between two words.'));
        }

        if ($failure === FailedUse::StreamRefused) {
            $stage->letAnotherRequestGetThereFirst();
        }

        if ($failure === FailedUse::StreamUnannounced || $failure === FailedUse::RunUnannounced) {
            $stage->closeQueueTo(Verb::Announce);
        }

        if ($failure === FailedUse::DispatchUnqueued) {
            $stage->closeQueueTo($stage->kind->verb());
        }

        $failed = match ($failure) {
            FailedUse::RunUnannounced => self::caught($segment->run(...)) instanceof Throwable,
            FailedUse::DispatchUnqueued => self::caught($segment->dispatch(...)) instanceof Throwable,
            FailedUse::StreamUnannounced, FailedUse::StreamMisconfigured => self::caught($segment->events(...)) instanceof Throwable,
            FailedUse::StreamRefused, FailedUse::StreamWhoseNodeThrew => $stage->take($segment->events())->thrown instanceof Throwable,
            FailedUse::StreamAbandoned => self::leftAfterTheFirstFrame($segment),
        };

        $stage->openQueue();
        self::assertTrue($failed, 'The only use of the segment did not fail.');

        return $segment;
    }

    /**
     * @return bool whether the stream had a first frame to leave
     */
    protected static function leftAfterTheFirstFrame(PendingSegment $segment): bool
    {
        $events = $segment->events();

        return $events->valid();
    }
}
