<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use JsonException;
use NeuronCore\IQ\Tests\Stub\Faults\StoreFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;

/**
 * Rule 3 of the design and question 5: with a dispatcher set, a web request
 * never calls the platform. A streamed segment hands its two notices to the
 * queue, whatever becomes of the stream: no HTTP request leaves the process
 * that streams. StreamedTestCase checks the same after every test of the suite.
 */
class NoPlatformCallTest extends StreamedTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_a_stream_taken_to_its_end_makes_no_http_request(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $stage->take($stage->stream());

        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_neuron_refuses_makes_no_http_request(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $stage->letAnotherRequestGetThereFirst();

        $refusal = self::caught($events->valid(...));

        self::assertNotNull($refusal);
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_whose_consumer_lets_go_makes_no_http_request(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        unset($events);

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_an_exception_is_thrown_into_makes_no_http_request(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();

        self::caught(static fn (): mixed => $events->throw(new JsonException('The wire cannot carry this event.')));

        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_is_never_taken_makes_no_http_request(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        $stage->stream();

        self::assertSame([Verb::Announce], $stage->verbsQueued());
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_events_makes_no_http_request_when_the_queue_does_not_take_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->closeQueueTo(Verb::Announce);

        $thrown = self::caught($segment->events(...));

        self::assertNotNull($thrown);
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_stream_makes_no_http_request_when_the_queue_does_not_take_the_observe(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->closeQueueTo(Verb::Observe);

        $stage->take($stage->stream());

        self::assertSame([Verb::Announce], $stage->verbsQueued());
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_makes_no_http_request_when_the_store_fails_under_it(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $stage->interceptStore(static fn () => throw new StoreFailed('The store went away.'));

        $taken = $stage->take($events);

        self::assertInstanceOf(StoreFailed::class, $taken->thrown);
        self::assertSame([], $stage->requests());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_platform_hears_of_a_stream_from_the_worker_that_runs_the_queue_and_from_nobody_else(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->take($stage->stream());
        $sentByTheRequest = $stage->requests();

        $stage->drain();

        self::assertSame([], $sentByTheRequest);
        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($stage->requests(), 0));
    }
}
