<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\InPlace\FailedUse;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Section 4 of the design: a PendingSegment is used once, because a second
 * run() or dispatch() would execute the same run ID again.
 */
class SingleUseTest extends InPlaceTestCase
{
    #[DataProvider('twoUses')]
    public function test_a_second_use_of_a_segment_is_refused_as_segment_used(string $backend, Kind $kind, string $first, string $second): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $segment->{$first}();

        $again = $stage->play(static fn (): WorkflowState|string => $segment->{$second}());

        self::assertInstanceOf(CloudException::class, $again->thrown);
        self::assertSame(Code::SegmentUsed, $again->thrown->reason);
    }

    #[DataProvider('twoUses')]
    public function test_a_second_use_queues_nothing_executes_nothing_and_writes_nothing(
        string $backend,
        Kind $kind,
        string $first,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $segment->{$first}();
        $writes = $stage->journal->exposedTo(FaultMode::AfterWrite);

        $again = $stage->play(static fn (): WorkflowState|string => $segment->{$second}());

        self::assertSame([[], 0, 0], [$again->queued, $again->bodiesRun, $again->requests]);
        self::assertSame($writes, $stage->journal->exposedTo(FaultMode::AfterWrite));
    }

    #[DataProvider('failedFirstUses')]
    public function test_a_segment_whose_only_use_failed_is_used_all_the_same(
        string $backend,
        Kind $kind,
        FailedUse $failure,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $segment = $this->segmentWhoseOnlyUseFailed($stage, $failure);

        $again = $stage->play(static fn (): WorkflowState|string => $segment->{$second}());

        self::assertInstanceOf(CloudException::class, $again->thrown);
        self::assertSame(Code::SegmentUsed, $again->thrown->reason);
    }

    #[DataProvider('failedFirstUses')]
    public function test_the_use_that_follows_a_failed_one_queues_nothing_and_executes_nothing(
        string $backend,
        Kind $kind,
        FailedUse $failure,
        string $second,
    ): void {
        $stage = $this->stage($backend, $kind, Ending::Waits);
        $segment = $this->segmentWhoseOnlyUseFailed($stage, $failure);

        $again = $stage->play(static fn (): WorkflowState|string => $segment->{$second}());

        self::assertSame([[], 0, []], [$again->queued, $again->bodiesRun, $again->attemptsOpened]);
    }

    /**
     * The flag that makes a segment single-use must follow the run ID, not
     * the object: a copy made before the first use holds the same run ID.
     */
    #[DataProvider('backends')]
    public function test_a_copy_of_a_start_made_before_it_ran_cannot_execute_its_run_id_a_second_time(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $start = $stage->prepare();
        $copy = clone $start;
        $start->run();

        $again = $stage->play($copy->run(...));

        self::assertSame(0, $again->bodiesRun, 'The copy executed the run ID of the start a second time.');
        self::assertInstanceOf(CloudException::class, $again->thrown);
        self::assertSame(Code::SegmentUsed, $again->thrown->reason);
    }

    #[DataProvider('backends')]
    public function test_a_used_segment_does_not_use_up_the_next_one_of_the_same_workflow(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $stage->prepare()->run();

        $next = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $next->returned);
        self::assertSame(WorkflowStatus::Completed, $next->returned->getStatus());
    }

    /**
     * @return array<string, array{string, Kind, string, string}> the method of the first use, and of the second
     */
    public static function twoUses(): array
    {
        $rows = [];
        foreach ([Kind::Start, Kind::Answer, Kind::Signal] as $kind) {
            foreach (['run', 'dispatch'] as $first) {
                foreach (['run', 'dispatch'] as $second) {
                    $rows["{$kind->label()}: {$first}(), then {$second}()"] = [$kind, $first, $second];
                }
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
        foreach ([Kind::Start, Kind::Answer, Kind::Signal] as $kind) {
            foreach (FailedUse::cases() as $failure) {
                if ($failure->meetsARun() && $kind !== Kind::Start) {
                    continue;
                }

                foreach (['run', 'dispatch'] as $second) {
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
        if ($failure === FailedUse::RunMisconfigured) {
            $stage->thread->configure(static fn () => throw new RuntimeException('The response of this request is already closed.'));
        }

        if ($failure === FailedUse::RunWhoseNodeThrew) {
            $stage->midBody->whenReached(static fn () => throw new NodeFailed('The node failed on purpose.'));
        }

        if ($failure->meetsARun()) {
            $stage->elsewhere()->start()->dispatch();
        }

        $segment = $stage->prepare();

        if ($failure->findsTheQueueDown()) {
            $stage->queue->beforeTaking(static fn () => throw new RuntimeException('The queue is down.'));
        }

        $used = $stage->play($failure->dispatches() ? $segment->dispatch(...) : $segment->run(...));
        $stage->queue->beforeTaking(static function (): void {
        });

        self::assertNotNull($used->thrown, 'The only use of the segment did not fail.');

        return $segment;
    }
}
