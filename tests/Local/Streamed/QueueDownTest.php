<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Section 9.6 of the design, for a stream: a queue that does not take the
 * announcement fails events() itself, before a generator exists and before
 * anything ignites; one that does not take the observe changes nothing for
 * the consumer.
 */
class QueueDownTest extends StreamedTestCase
{
    use CatchesIQExceptions;

    #[DataProvider('kinds')]
    public function test_a_queue_that_does_not_take_the_announcement_fails_events_itself_as_dispatch_failed(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->closeQueueTo(Verb::Announce);

        $reason = self::reasonThrownBy($segment->events(...));

        self::assertSame(Code::DispatchFailed, $reason);
    }

    #[DataProvider('kinds')]
    public function test_the_dispatch_failed_exception_carries_what_the_queue_threw_as_its_previous(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $down = $stage->closeQueueTo(Verb::Announce);

        $thrown = self::thrownBy($segment->events(...));

        self::assertSame($down, $thrown?->getPrevious());
    }

    #[DataProvider('kinds')]
    public function test_nothing_ignites_and_no_node_body_runs_when_the_queue_does_not_take_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->closeQueueTo(Verb::Announce);

        $thrown = self::thrownBy($segment->events(...));

        self::assertSame([0, []], [$stage->bodiesRun(), $stage->attemptsOpened()]);
        self::assertNotNull($thrown, 'The queue took the announcement.');
    }

    #[DataProvider('kinds')]
    public function test_the_store_is_unchanged_when_the_queue_does_not_take_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $held = $stage->stored();
        $stage->closeQueueTo(Verb::Announce);

        $thrown = self::thrownBy($segment->events(...));

        self::assertSame(0, $stage->writes());
        self::assertSame($held, $stage->stored());
        self::assertNotNull($thrown, 'The queue took the announcement.');
    }

    #[DataProvider('kinds')]
    public function test_no_observe_follows_an_announcement_the_queue_did_not_take(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->closeQueueTo(Verb::Announce);

        $thrown = self::thrownBy($segment->events(...));

        self::assertSame([], $stage->queued());
        self::assertNotNull($thrown, 'The queue took the announcement.');
    }

    #[DataProvider('kinds')]
    public function test_the_same_segment_prepared_again_streams_once_the_queue_is_back(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $stage->closeQueueTo(Verb::Announce);
        self::thrownBy($segment->events(...));
        $stage->openQueue();

        $taken = $stage->take($stage->stream());

        self::assertSame('completed', $taken->returned?->getStatus()->value);
        self::assertSame([Verb::Announce, Verb::Observe], $stage->verbsQueued());
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_state_of_the_stream(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->closeQueueTo(Verb::Observe);

        $taken = $stage->take($stage->stream());

        self::assertNull($taken->thrown);
        self::assertSame(self::outcomeOf($ending)->value, $taken->returned?->getStatus()->value);
    }

    #[DataProvider('kindsThatThrow')]
    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_exception_of_the_node(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->closeQueueTo(Verb::Observe);

        $taken = $stage->take($stage->stream());

        self::assertInstanceOf($ending === Ending::Fails ? NodeFailed::class : WorkflowRefusedException::class, $taken->thrown);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_queue_that_does_not_take_the_observe_takes_no_frame_away_from_the_consumer(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $apart = $this->sameStageApart($backend, $kind, $ending);
        $whileTheQueueIsUp = $apart->take($apart->stream());
        $stage->closeQueueTo(Verb::Observe);

        $taken = $stage->take($stage->stream());

        self::assertEquals($whileTheQueueIsUp->events(), $taken->events());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_queue_that_does_not_take_the_observe_is_asked_once_and_keeps_only_the_announcement(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        $observesHanded = 0;
        $stage->queue->beforeTaking(static function (Invocation $handed) use (&$observesHanded): void {
            if ($handed->verb === Verb::Observe) {
                $observesHanded++;

                throw new RuntimeException('The queue is down.');
            }
        });

        $stage->take($stage->stream());

        self::assertSame(1, $observesHanded);
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_store_holds_what_the_segment_left_whether_or_not_the_queue_took_the_observe(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->closeQueueTo(Verb::Observe);

        $stage->take($stage->stream());

        self::assertSame(
            self::outcomeOf($ending)->value === 'completed' ? null : self::outcomeOf($ending)->value,
            $stage->stored()['status'] ?? null,
        );
    }
}
