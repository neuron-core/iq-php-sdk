<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function gc_collect_cycles;

/**
 * A stream nobody takes a step of: events() was called, so the segment is
 * used and announced, but Neuron was never asked anything. There is no run
 * to observe, and the announcement stays the only word the platform hears.
 */
class NeverTakenStreamTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_a_stream_that_is_never_taken_ignites_nothing(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $held = $stage->stored();
        $events = $stage->stream();

        unset($events);
        gc_collect_cycles();

        self::assertSame($held, $stage->stored());
        self::assertSame([0, 0, []], [$stage->writes(), $stage->bodiesRun(), $stage->attemptsOpened()]);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_is_never_taken_leaves_its_announcement_in_the_queue_and_no_observe(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        unset($events);
        gc_collect_cycles();

        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_the_queue_is_asked_to_take_no_observe_for_a_stream_that_is_never_taken(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $observesHanded = 0;
        $stage->queue->beforeTaking(static function (Invocation $handed) use (&$observesHanded): void {
            $observesHanded += $handed->verb === Verb::Observe ? 1 : 0;
        });
        $events = $stage->stream();

        unset($events);
        gc_collect_cycles();

        self::assertSame(0, $observesHanded);
    }

    #[DataProvider('kinds')]
    public function test_the_worker_posts_the_announcement_of_a_stream_that_was_never_taken_and_no_report(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        unset($events);

        $results = $stage->drain();

        self::assertCount(1, $results);
        self::assertInstanceOf(Report::class, $results[0]);
        self::assertSame([self::ANNOUNCEMENTS], array_column($stage->requests(), 0));
    }

    #[DataProvider('kinds')]
    public function test_the_same_segment_prepared_again_streams_to_its_end_after_one_that_was_never_taken(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        unset($events);

        $taken = $stage->take($stage->stream());

        self::assertSame('completed', $taken->returned?->getStatus()->value);
        self::assertSame([Verb::Announce, Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('backends')]
    public function test_a_start_prepared_again_after_one_that_was_never_taken_runs_under_a_run_id_of_its_own(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $stage->stream();
        $neverIgnited = $stage->queued()[0]->runId;

        $taken = $stage->take($stage->stream());

        self::assertNotSame($neverIgnited, $taken->returned?->getRunId());
        self::assertSame($stage->queued()[1]->runId, $taken->returned?->getRunId());
    }
}
