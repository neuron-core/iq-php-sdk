<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\EndToEnd\FailureTable;

use NeuronCore\IQ\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\IQ\Tests\Support\EndToEnd\Process;
use NeuronCore\IQ\Tests\Support\EndToEnd\WireLog;
use NeuronCore\IQ\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Support\ExampleApp\Shop;
use NeuronCore\IQ\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;
use function array_unique;
use function count;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Row 12 of section 7 of the design: a deploy removes or has not yet
 * delivered a version. The endpoint answers 404, or an old worker reports
 * "aborted"; both are sent again while the version is in the manifest, and a
 * version that left the manifest is never invoked: runs meet the code they
 * were started on.
 */
class Row12DeployAndVersionsTest extends FailureTableTestCase
{
    protected const ORDER = 'R1201';

    /** The code of the next release holds both versions; the one after it drops the first. */
    protected const BOTH_VERSIONS = ['SHOP_VERSIONS' => '1,2'];

    protected const SECOND_VERSION_ONLY = ['SHOP_VERSIONS' => '2'];

    public function test_a_start_for_a_version_the_deploy_has_not_delivered_yet_is_sent_again_until_the_new_code_runs_it(): void
    {
        // The sync of the release published version 2 before the web tier and the workers were restarted.
        self::assertSame(0, $this->shop->sync(self::BOTH_VERSIONS)['exitCode']);
        $oldWorker = $this->startWorkers();
        $runId = $this->fire(self::ORDER);
        $this->await('the old endpoint refused the start', fn (): bool => $this->crossed(WireLog::START, self::ORDER) !== []);

        $this->shop->stopServing();
        $this->shop->serve(self::BOTH_VERSIONS);
        $aborted = $this->awaitReport(self::ORDER, 'aborted');
        $oldWorker->stop();
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS, self::BOTH_VERSIONS);
        $this->awaitWait($runId, 'wait_for_event');

        $starts = $this->crossed(WireLog::START, self::ORDER);
        [$ran] = $this->reports(self::ORDER, 'suspended');
        self::assertStringStartsWith('2-', $runId, 'The platform mints a start with the current version.');
        self::assertSame([404, 'unknown_version'], [$starts[0]['status'], $starts[0]['answer']['code']]);
        self::assertSame(202, $starts[count($starts) - 1]['status']);
        self::assertCount(1, array_unique(array_column($starts, 'body')), 'Every re-send is the same start, with the same use-by time.');
        self::assertSame(['unknown_version', null, null, null], [$aborted['code'], $aborted['errorClass'], $aborted['claimed'], $aborted['run']]);
        self::assertSame(['start', 1, true], [$ran['verb'], $ran['executedAttempt'], $ran['claimed']]);
        self::assertSame(1, $this->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        $this->assertThePlatformHoldsTheStoredRun(self::ORDER, $runId);
        self::assertSame([], $this->platform->alerts());
    }

    public function test_a_run_of_a_version_the_deploy_removed_is_answered_404_then_never_invoked_until_its_code_is_back(): void
    {
        $worker = $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment(self::ORDER);
        $this->theOrderIsPaid(self::ORDER, $runId);

        // The release restarts the web tier and the workers on code that dropped version 1. Its sync comes last.
        $worker = $this->redeploy($worker, self::SECOND_VERSION_ONLY);
        $this->await('the endpoint refused the wake of the timer twice', fn (): bool => count($this->crossed(WireLog::WAKE, self::ORDER)) >= 2);
        $sync = $this->shop->sync(self::SECOND_VERSION_ONLY);
        $refused = $this->crossed(WireLog::WAKE, self::ORDER);
        $held = $this->platform->run($runId);

        foreach ($refused as $wake) {
            self::assertSame([404, 'unknown_version'], [$wake['status'], $wake['answer']['code']]);
        }
        self::assertCount(1, array_unique(array_column($refused, 'body')), 'Sent again as the same wake while the version was in the manifest.');
        self::assertSame([], $this->shop->queue()->waiting(SpoolDispatcher::SEGMENTS), 'The new code queued nothing for a version it does not have.');
        self::assertSame(0, $sync['exitCode'], 'The sync is accepted anyway.');
        self::assertSame([Shop::DEFINITION => [1 => 1]], json_decode($sync['printed'], true, flags: JSON_THROW_ON_ERROR)['stranded']);
        self::assertSame(['wake', 'stranded_version'], [$held['timers'][0]['action'], $held['timers'][0]['held']]);
        self::assertSame([], $this->platform->tick(), 'A stranded version is never invoked.');
        self::assertSame(['suspended', 2], [$this->stored(self::ORDER)['status'] ?? null, $this->stored(self::ORDER)['attempt'] ?? null], 'The run was not touched.');

        // A release that holds the code of version 1 again.
        $this->redeploy($worker, self::BOTH_VERSIONS);
        self::assertSame(0, $this->shop->sync(self::BOTH_VERSIONS)['exitCode']);
        $this->awaitClosed($runId, 'completed');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
    }

    /**
     * The web tier and the worker of the segments are stopped, then started on the code of a release. The
     * platform ticks only while a test waits, so nothing reaches the endpoint in between.
     *
     * @param array<string, string> $release the environment of the code that is deployed
     * @return Process the worker of the segments of the release
     */
    protected function redeploy(Process $worker, array $release): Process
    {
        $worker->stop();
        $this->shop->stopServing();
        $this->shop->serve($release);

        return $this->shop->startWorker(SpoolDispatcher::SEGMENTS, $release);
    }
}
