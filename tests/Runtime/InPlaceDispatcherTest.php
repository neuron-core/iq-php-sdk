<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Runtime\InPlaceDispatcher;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Tests\Support\Units\Queued;
use NeuronCore\IQ\Verb;

class InPlaceDispatcherTest extends IQTestCase
{
    use CatchesIQExceptions;

    public function test_a_message_it_is_handed_runs_at_once_through_invoke(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        (new InPlaceDispatcher($bench->iq))->dispatch(Queued::announcementOf(Queued::start('greeting')));

        $bench->platform->assertAnnounced(Queued::WORKFLOW_ID, Verb::Start);
    }

    public function test_a_message_invoke_throws_for_fails_the_dispatch(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->platform->failNext(1);
        $dispatcher = new InPlaceDispatcher($bench->iq);

        $reason = self::reasonThrownBy(static function () use ($dispatcher): void {
            $dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));
        });

        self::assertSame(Code::ReportUndelivered, $reason);
    }
}
