<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Runtime\InPlaceDispatcher;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use NeuronCore\Cloud\Tests\Support\Units\Queued;
use NeuronCore\Cloud\Verb;

class InPlaceDispatcherTest extends CloudTestCase
{
    use CatchesCloudExceptions;

    public function test_a_message_it_is_handed_runs_at_once_through_invoke(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        (new InPlaceDispatcher($bench->cloud))->dispatch(Queued::announcementOf(Queued::start('greeting')));

        $bench->platform->assertAnnounced(Queued::WORKFLOW_ID, Verb::Start);
    }

    public function test_a_message_invoke_throws_for_fails_the_dispatch(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->platform->failNext(1);
        $dispatcher = new InPlaceDispatcher($bench->cloud);

        $reason = self::reasonThrownBy(static function () use ($dispatcher): void {
            $dispatcher->dispatch(Queued::announcementOf(Queued::start('greeting')));
        });

        self::assertSame(Code::ReportUndelivered, $reason);
    }
}
