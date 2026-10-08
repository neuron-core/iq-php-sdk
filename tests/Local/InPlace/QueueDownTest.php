<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Section 9.6 of the design: a queue that does not take the announcement
 * fails the call before anything ignites; one that does not take the observe
 * changes nothing for the caller.
 */
class QueueDownTest extends InPlaceTestCase
{
    #[DataProvider('kinds')]
    public function test_a_queue_that_does_not_take_the_announcement_fails_the_call_as_dispatch_failed(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Announce);

        $played = $stage->play();

        self::assertInstanceOf(CloudException::class, $played->thrown);
        self::assertSame(Code::DispatchFailed, $played->thrown->reason);
    }

    #[DataProvider('kinds')]
    public function test_the_dispatch_failed_exception_carries_what_the_queue_threw_as_its_previous(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $down = self::queueDownFor($stage, Verb::Announce);

        $played = $stage->play();

        self::assertSame($down, $played->thrown?->getPrevious());
    }

    #[DataProvider('kinds')]
    public function test_no_node_body_runs_when_the_queue_does_not_take_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Announce);

        $played = $stage->play();

        self::assertSame(0, $played->bodiesRun);
        self::assertSame([], $played->attemptsOpened);
    }

    #[DataProvider('kinds')]
    public function test_nothing_is_written_to_the_store_when_the_queue_does_not_take_the_announcement(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Announce);
        $held = $stage->stored();
        $writes = $stage->journal->exposedTo(FaultMode::AfterWrite);

        $stage->play();

        self::assertSame($writes, $stage->journal->exposedTo(FaultMode::AfterWrite));
        self::assertSame($held, $stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_no_observe_follows_an_announcement_the_queue_did_not_take(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Announce);

        $played = $stage->play();

        self::assertSame([], $played->queued);
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_state(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        self::queueDownFor($stage, Verb::Observe);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(self::outcomeOf($ending)->value, $played->returned->getStatus()->value);
    }

    #[DataProvider('kinds')]
    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_exception_of_the_node(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Observe);
        $failure = new NodeFailed('The node failed while the queue was down.');
        $stage->midBody->whenReached(static fn () => throw $failure);

        $played = $stage->play();

        self::assertSame($failure, $played->thrown);
    }

    #[DataProvider('refusalsThrown')]
    public function test_a_queue_that_does_not_take_the_observe_does_not_hide_the_refusal_of_neuron(
        string $backend,
        Kind $kind,
        Ending $ending,
        RefusalReason $reason,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);
        self::queueDownFor($stage, Verb::Observe);

        $played = $stage->playOvertaken();

        self::assertInstanceOf(WorkflowRefusedException::class, $played->thrown);
        self::assertSame($reason, $played->thrown->reason);
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_stays_in_the_queue_when_the_observe_is_not_taken(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        self::queueDownFor($stage, Verb::Observe);

        $played = $stage->play();

        self::assertSame([Verb::Announce], $played->verbsQueued());
    }

    /**
     * @return array<string, array{string, Kind, Ending, RefusalReason}> the refusals that reach the caller: the one
     *         of the wake of recover() is turned into null
     */
    public static function refusalsThrown(): array
    {
        return self::onBothBackends([
            'a start that meets a suspended run' => [Kind::Start, Ending::Waits, RefusalReason::RunInFlight],
            'an answer another request gave first' => [Kind::Answer, Ending::Waits, RefusalReason::StaleAttempt],
            'a signal for a run another request completed' => [Kind::Signal, Ending::Completes, RefusalReason::StaleRun],
        ]);
    }

    /**
     * From now on the queue of the stage throws for every message of that kind.
     *
     * @return RuntimeException what it throws
     */
    protected static function queueDownFor(Stage $stage, Verb $verb): RuntimeException
    {
        $down = new RuntimeException("The queue is down for '{$verb->value}'.");

        $stage->queue->beforeTaking(static function (Invocation $handed) use ($verb, $down): void {
            if ($handed->verb === $verb) {
                throw $down;
            }
        });

        return $down;
    }
}
