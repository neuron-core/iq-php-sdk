<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\Cloud\Tests\Stub\Streamed\Ending;
use NeuronCore\Cloud\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Faults\StoredRun;
use NeuronCore\Cloud\Tests\Support\InPlace\TracedCallback;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_map;
use function count;
use function time;

/**
 * Section 4 of the design: events() applies the configure() callback just
 * before the announcement, so before the generator is returned, and streams
 * the workflow the callback adjusted. That is how the endpoint of example (b)
 * hooks its stream adapter onto the turn. The persistence, the lease and the
 * completion policy of section 9.1 stay the SDK's: they are imposed again
 * after the callback.
 */
class ConfigureTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_events_applies_the_callback_once_before_it_returns(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $events = $stage->stream();
        $appliedOnceReturned = count($callback->adjusted);
        $stage->take($events);

        self::assertSame([1, 1], [$appliedOnceReturned, count($callback->adjusted)]);
    }

    #[DataProvider('kinds')]
    public function test_events_gives_the_callback_the_workflow_of_the_definition_bound_to_the_workflow_id(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());

        $stage->stream();

        self::assertInstanceOf(TaleWorkflow::class, $callback->adjusted[0] ?? null);
        self::assertSame(Stage::WORKFLOW_ID, $callback->adjusted[0]->getWorkflowId());
    }

    #[DataProvider('kinds')]
    public function test_the_stream_executes_the_workflow_the_callback_adjusted(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $callback = new TracedCallback();
        $stage->thread->configure($callback->closure());
        $events = $stage->stream();
        $heardBeforeTheFirstStep = $callback->segmentsHeard;

        $stage->take($events);

        self::assertSame([0, 1], [$heardBeforeTheFirstStep, $callback->segmentsHeard]);
    }

    #[DataProvider('kinds')]
    public function test_the_callback_is_applied_before_the_announcement_reaches_the_queue(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $queuedWhenApplied = null;
        $stage->thread->configure(static function () use ($stage, &$queuedWhenApplied): void {
            $queuedWhenApplied = $stage->verbsQueued();
        });

        $stage->stream();

        self::assertSame([], $queuedWhenApplied);
        self::assertSame([Verb::Announce], $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_a_stream_keeps_the_callback_that_was_set_when_its_segment_was_prepared(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $setBefore = new TracedCallback();
        $setAfter = new TracedCallback();
        $stage->thread->configure($setBefore->closure());
        $prepared = $stage->prepare();
        $stage->thread->configure($setAfter->closure());

        $stage->take($prepared->events());

        self::assertSame([1, 0], [$setBefore->segmentsHeard, $setAfter->segmentsHeard]);
    }

    #[DataProvider('kinds')]
    public function test_what_the_callback_throws_is_thrown_by_events_as_it_is(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $refused = new RuntimeException('The response of this request is already closed.');
        $stage->thread->configure(static fn () => throw $refused);
        $segment = $stage->prepare();

        $thrown = self::caught($segment->events(...));

        self::assertSame($refused, $thrown);
    }

    #[DataProvider('kinds')]
    public function test_a_callback_that_throws_leaves_nothing_announced_nothing_run_and_nothing_asked_of_the_store(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn () => throw new RuntimeException('The response of this request is already closed.'));
        $segment = $stage->prepare();
        $operations = $stage->journal->exposedTo(FaultMode::BeforeOperation);

        $thrown = self::caught($segment->events(...));

        self::assertNotNull($thrown);
        self::assertSame([[], 0, []], [$stage->queued(), $stage->bodiesRun(), $stage->attemptsOpened()]);
        self::assertSame($operations, $stage->journal->exposedTo(FaultMode::BeforeOperation));
    }

    #[DataProvider('kinds')]
    public function test_a_callback_that_sets_another_persistence_leaves_the_stream_in_the_store_of_the_sdk(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $another = new InMemoryPersistence();
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setPersistence($another));
        $events = $stage->stream();

        $events->valid();
        $whileStreaming = $stage->stored();
        $stage->take($events);

        self::assertSame(['running', 'suspended'], [$whileStreaming['status'] ?? null, $stage->stored()['status'] ?? null]);
        self::assertNull(StoredRun::of($another, Stage::WORKFLOW_ID));
    }

    #[DataProvider('kinds')]
    public function test_a_callback_that_removes_the_lease_leaves_the_stream_under_the_lease_of_its_definition(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setLeaseTimeout(null));
        $events = $stage->stream();
        $takenAt = time();

        $events->valid();

        $leaseExpiresAt = $stage->stored()['leaseExpiresAt'] ?? null;
        self::assertGreaterThanOrEqual($takenAt + Stage::LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + Stage::LEASE, $leaseExpiresAt);
    }

    #[DataProvider('kinds')]
    public function test_a_callback_that_sets_another_lease_leaves_the_stream_under_the_lease_of_its_definition(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setLeaseTimeout(Stage::LEASE * 10));
        $events = $stage->stream();
        $takenAt = time();

        $events->valid();

        $leaseExpiresAt = $stage->stored()['leaseExpiresAt'] ?? null;
        self::assertGreaterThanOrEqual($takenAt + Stage::LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + Stage::LEASE, $leaseExpiresAt);
    }

    #[DataProvider('kinds')]
    public function test_a_callback_that_retains_the_completion_leaves_no_run_once_the_stream_completed_it(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->retainCompletionUntilAcknowledged());

        $taken = $stage->take($stage->stream());

        self::assertSame('completed', $taken->returned?->getStatus()->value);
        self::assertNull($stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_the_consumer_receives_the_frames_of_the_stream_adapter_the_callback_set(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter()));

        $taken = $stage->take($stage->stream());

        self::assertSame(
            ['start', 'word', 'word', 'word', 'word', 'end'],
            array_map(static fn (object $frame): string => $frame instanceof ProtocolEvent ? $frame->type : $frame::class, $taken->events()),
        );
    }

    #[DataProvider('kinds')]
    public function test_the_channel_the_callback_set_is_sent_every_frame_the_consumer_receives(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $sent = [];
        $channel = new CallbackChannel(onSend: static function (ProtocolEvent $frame) use (&$sent): void {
            $sent[] = $frame;
        });
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow
            ->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter())
            ->setChannel(static fn (): CallbackChannel => $channel));

        $taken = $stage->take($stage->stream());

        self::assertCount(6, $sent);
        self::assertSame($sent, $taken->events());
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_names_the_lease_of_the_definition_whatever_lease_the_callback_set(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->thread->configure(static fn (Workflow $workflow): Workflow => $workflow->setLeaseTimeout(5));
        $leaseAnnounced = null;
        $stage->queue->beforeTaking(static function (Invocation $handed) use (&$leaseAnnounced): void {
            $leaseAnnounced ??= $handed->body['leaseSeconds'] ?? null;
        });

        $stage->stream();

        self::assertSame(Stage::LEASE, $leaseAnnounced);
    }
}
