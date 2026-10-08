<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 9.6 of the design: a segment run in the calling process is
 * announced to the queue before it executes, and observed to the queue once
 * it has ended.
 */
class NoticeOrderTest extends InPlaceTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_a_segment_hands_the_queue_one_announcement_then_one_observe_and_nothing_else(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertSame([Verb::Announce, Verb::Observe], $played->verbsQueued());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_announcement_is_in_the_queue_before_any_node_body_runs(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertSame(Verb::Announce, $played->queued[0]->verb);
        self::assertSame(0, $played->bodiesRunBefore[0]);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_reaches_the_queue_after_the_last_node_body_ran(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertSame(Verb::Observe, $played->queued[1]->verb);
        self::assertSame(2, $played->bodiesRun, 'The segment runs the first node and the last one.');
        self::assertSame(2, $played->bodiesRunBefore[1]);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_announcement_is_in_the_queue_before_anything_is_written_to_the_store(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $writesBefore = $stage->journal->exposedTo(FaultMode::AfterWrite);
        $writesWhenAnnounced = null;
        $stage->queue->beforeTaking(static function (Invocation $handed) use ($stage, &$writesWhenAnnounced): void {
            if ($handed->verb === Verb::Announce) {
                $writesWhenAnnounced = $stage->journal->exposedTo(FaultMode::AfterWrite);
            }
        });

        $stage->play();

        self::assertSame($writesBefore, $writesWhenAnnounced);
        self::assertGreaterThan($writesBefore, $stage->journal->exposedTo(FaultMode::AfterWrite), 'The segment did write.');
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_reaches_the_queue_once_the_store_holds_what_the_segment_left(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $heldWhenObserved = false;
        $stage->queue->beforeTaking(static function (Invocation $handed) use ($stage, &$heldWhenObserved): void {
            if ($handed->verb === Verb::Observe) {
                $heldWhenObserved = $stage->stored();
            }
        });

        $stage->play();

        self::assertSame($stage->stored(), $heldWhenObserved);
        self::assertSame(
            self::outcomeOf($ending) === Outcome::Completed ? null : self::outcomeOf($ending)->value,
            $heldWhenObserved['status'] ?? null,
        );
    }

    #[DataProvider('refusals')]
    public function test_a_segment_neuron_refuses_is_announced_and_observed_like_any_other(
        string $backend,
        Kind $kind,
        Ending $ending,
        RefusalReason $reason,
    ): void {
        $played = $this->stage($backend, $kind, $ending)->playOvertaken();

        self::assertSame([Verb::Announce, Verb::Observe], $played->verbsQueued());
        self::assertSame($reason->value, $played->observe()->body['code'] ?? null);
    }
}
