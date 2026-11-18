<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Wait;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;
use function count;

/**
 * Row 19 of section 7 of the design: the API key is wrong or revoked.
 * Segments run. Every announcement and report is answered 401 and ends as a
 * failed job that can be replayed: the store is untouched, and the platform
 * catches up from the replayed observes.
 */
class Row19WrongApiKeyTest extends FailureTableTestCase
{
    protected const ORDER = 'R1901';

    protected const REVOKED_KEY = ['IQ_API_KEY' => 'a-key-the-platform-revoked'];

    protected const FAILED = SpoolDispatcher::NOTICES . ':failed';

    public function test_segments_run_under_a_revoked_api_key_and_the_platform_catches_up_from_the_replayed_notices(): void
    {
        $workers = [$this->shop->startWorker(SpoolDispatcher::NOTICES, self::REVOKED_KEY), $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::REVOKED_KEY)];
        $runId = (string) $this->shop->checkout(self::ORDER, self::NOTE)['body']['runId'];
        Wait::until('the announcement and the observe of the segment ended as failed jobs', fn (): bool => count($this->shop->queue()->waiting(self::FAILED)) === 2);

        $waiting = $this->stored(self::ORDER);
        self::assertSame(['suspended', 1], [$waiting['status'] ?? null, $waiting['attempt'] ?? null], 'The segment ran.');
        self::assertSame([401, 401], array_column($this->crossed(WireLog::ANNOUNCEMENT, self::ORDER), 'status'), 'Posted by the worker, then by the queue.');
        self::assertSame([401, 401], array_column($this->crossed(WireLog::REPORT, self::ORDER), 'status'));
        self::assertSame([], $this->platform->runsOf(self::workflowIdOf(self::ORDER)), 'The platform recorded nothing: no timer fires for the run.');
        self::assertSame([], $this->shop->queue()->waiting(SpoolDispatcher::NOTICES . ':later'), 'A 401 is final: nothing is tried again by itself.');

        // The key is fixed, the workers are restarted with it and a person replays the failed jobs.
        foreach ($workers as $worker) {
            $worker->stop();
        }
        $this->startWorkers();
        $this->shop->replayFailed(SpoolDispatcher::NOTICES);
        $held = $this->awaitWait($runId, 'wait_for_event');

        self::assertSame($waiting, $this->stored(self::ORDER), 'The store was not touched.');
        self::assertSame('application', $held['origin']);
        self::assertSame(['wake'], array_column($held['timers'], 'action'), 'The timer of the wait is armed now.');
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);

        $this->theOrderIsPaid(self::ORDER, $runId);
        $this->awaitClosed($runId, 'completed');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
    }
}
