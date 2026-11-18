<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Tests\Support\Streamed\Taken;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function array_fill;
use function count;

/**
 * Section 4 of the design: with no dispatcher set, every message runs in
 * place. That is for scripts and tests. The process that streams then posts
 * the two notices itself: the announcement before events() returns, the
 * report when the stream ends or is let go. A platform that cannot be
 * reached for the announcement counts as a dispatcher that threw.
 */
class WithoutDispatcherTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_events_has_posted_the_announcement_and_nothing_else_when_it_returns(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);

        $events = $stage->stream();

        self::assertSame([self::ANNOUNCEMENTS], array_column($stage->requests(), 0));
        self::assertSame($kind->verb()->value, $stage->requests()[0][1]['verb'] ?? null);
        self::assertTrue($events->valid(), 'The stream had nothing to give once it was taken.');
    }

    #[DataProvider('kindsAndEndings')]
    public function test_nothing_is_posted_while_the_consumer_holds_a_frame(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);

        $taken = Taken::from($stage->stream(), static fn (): int => count($stage->requests()));

        self::assertGreaterThanOrEqual(3, count($taken->frames));
        self::assertSame(array_fill(0, count($taken->frames), 1), $taken->notedAtEachFrame);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_is_posted_when_the_stream_ends(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);

        $stage->take($stage->stream());

        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($stage->requests(), 0));
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_says_how_the_stream_ended(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);

        $stage->take($stage->stream());

        [[, $announcement], [, $report]] = $stage->requests();
        self::assertSame(
            [
                $announcement['invocationId'],
                $kind->verb()->value,
                Origin::APPLICATION,
                self::outcomeOf($ending)->value,
                null,
                match ($ending) {
                    Ending::Fails => NodeFailed::class,
                    Ending::Refuses => WorkflowRefusedException::class,
                    Ending::Completes, Ending::Waits => null,
                },
                $kind->attempt(),
                true,
            ],
            [
                $report['invocationId'],
                $report['verb'],
                $report['origin'],
                $report['outcome'],
                $report['code'],
                $report['errorClass'],
                $report['executedAttempt'],
                $report['snapshotRead'],
            ],
        );
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_report_shows_the_run_the_store_holds(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);

        $stage->take($stage->stream());

        [, [, $report]] = $stage->requests();
        self::assertTrue(StoredRun::same($stage->stored(), $report['run']));
    }

    #[DataProvider('kinds')]
    public function test_a_platform_that_cannot_be_reached_for_the_announcement_fails_events_as_dispatch_failed(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $segment = $stage->prepare();
        $stage->bench->platform->failNext();

        $thrown = self::caught($segment->events(...));

        self::assertInstanceOf(IQException::class, $thrown);
        self::assertSame(Code::DispatchFailed, $thrown->reason);
    }

    #[DataProvider('kinds')]
    public function test_the_dispatch_failed_exception_carries_why_the_announcement_was_not_posted(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $segment = $stage->prepare();
        $stage->bench->platform->failNext();

        $undelivered = self::caught($segment->events(...))?->getPrevious();

        self::assertInstanceOf(IQException::class, $undelivered);
        self::assertSame(Code::ReportUndelivered, $undelivered->reason);
    }

    #[DataProvider('kinds')]
    public function test_nothing_ignites_when_the_platform_cannot_be_reached_for_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $segment = $stage->prepare();
        $held = $stage->stored();
        $stage->bench->platform->failNext();

        $thrown = self::caught($segment->events(...));

        self::assertNotNull($thrown);
        self::assertSame([$held, 0, 0, []], [$stage->stored(), $stage->writes(), $stage->bodiesRun(), $stage->attemptsOpened()]);
        self::assertCount(1, $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_segment_whose_announcement_could_not_be_posted_is_used_all_the_same(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $segment = $stage->prepare();
        $stage->bench->platform->failNext();
        self::caught($segment->events(...));

        $again = self::caught($segment->events(...));

        self::assertInstanceOf(IQException::class, $again);
        self::assertSame(Code::SegmentUsed, $again->reason);
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_a_platform_that_does_not_record_the_report_does_not_hide_the_state_of_the_stream(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $events = $stage->stream();
        $stage->bench->platform->failNext();

        $taken = $stage->take($events);

        self::assertSame(self::outcomeOf($ending)->value, $taken->returned?->getStatus()->value);
        self::assertCount(2, $stage->requests(), 'The report was tried once.');
    }

    #[DataProvider('kindsThatThrow')]
    public function test_a_platform_that_does_not_record_the_report_does_not_hide_the_exception_of_the_node(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stageWithoutQueue($backend, $kind, $ending);
        $events = $stage->stream();
        $stage->bench->platform->failNext();

        $taken = $stage->take($events);

        self::assertInstanceOf($ending === Ending::Fails ? NodeFailed::class : WorkflowRefusedException::class, $taken->thrown);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_whose_consumer_lets_go_posts_its_report_at_once(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $postedWhileTaken = array_column($stage->requests(), 0);

        unset($events);

        self::assertSame([self::ANNOUNCEMENTS], $postedWhileTaken);
        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($stage->requests(), 0));
    }

    #[DataProvider('kinds')]
    public function test_the_report_of_a_stream_whose_consumer_let_go_says_observed_and_shows_the_failed_run(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $events = $stage->stream();
        $events->current();

        unset($events);

        [, [, $report]] = $stage->requests();
        self::assertSame(
            [Outcome::Observed->value, null, null, 'failed', $kind->attempt()],
            [$report['outcome'], $report['errorClass'], $report['executedAttempt'], $report['run']['status'] ?? null, $report['run']['attempt'] ?? null],
        );
        self::assertTrue(StoredRun::same($stage->stored(), $report['run']));
    }

    #[DataProvider('kinds')]
    public function test_letting_go_of_a_stream_throws_nothing_when_the_platform_does_not_record_the_report(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $stage->bench->platform->failNext();

        unset($events);

        self::assertSame('failed', $stage->stored()['status'] ?? null);
        self::assertCount(2, $stage->requests(), 'The report was tried once.');
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_is_never_taken_posts_its_announcement_and_no_report(string $backend, Kind $kind): void
    {
        $stage = $this->stageWithoutQueue($backend, $kind);
        $events = $stage->stream();

        unset($events);

        self::assertSame([self::ANNOUNCEMENTS], array_column($stage->requests(), 0));
    }

    #[DataProvider('backends')]
    public function test_a_start_streamed_without_a_queue_is_reported_unclaimed_and_is_not_entered_in_the_ledger(string $backend): void
    {
        $stage = $this->stageWithoutQueue($backend, Kind::Start, Ending::Waits);

        $stage->take($stage->stream());

        [, [, $report]] = $stage->requests();
        self::assertFalse($report['claimed']);
        self::assertNull($stage->ledgerRecord());
    }
}
