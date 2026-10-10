<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd;

use NeuronCore\Cloud\Tests\Support\EndToEnd\FailureTableTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\Wait;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Shop;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;

/**
 * The first limit of section 6 of the design, as rule 3 of appendix B now
 * settles it: every notice of a run the shop started is lost, so the
 * platform has never heard of the run. A later snapshot of its workflow ID
 * shows it with the definition that started it: the platform adopts the run
 * under that definition, and its timer fires.
 */
class LostNoticesTest extends FailureTableTestCase
{
    protected const ORDER = 'N4001';

    /** A list of the queue no worker reads: what is moved there is lost. */
    protected const LOST = 'lost';

    public function test_the_timer_of_a_run_whose_notices_were_all_lost_fires_once_a_later_snapshot_shows_the_run(): void
    {
        $runId = $this->theOrderSleepsAndThePlatformNeverHeardOfIt();
        self::assertSame([[], []], [$this->platform->runsOf(self::workflowIdOf(self::ORDER)), $this->platform->tick()]);

        // The customer checks out again. Neuron refuses the start in the request, and its two notices reach the platform.
        self::assertSame(409, $this->shop->checkout(self::ORDER, self::NOTE, inPlace: true)['status']);
        $this->shop->startWorker(SpoolDispatcher::NOTICES);
        $shipped = $this->awaitClosed($runId, 'completed');

        [$refused] = $this->reports(self::ORDER, 'refused');
        [$wake] = $this->crossed(WireLog::WAKE, self::ORDER);
        self::assertSame('run_in_flight', $refused['code']);
        self::assertNotSame($runId, $refused['runId'], 'The report is about the start that was refused.');
        self::assertSame([$runId, Shop::DEFINITION, 'suspended'], [$refused['run']['runId'], $refused['run']['definition'], $refused['run']['status']]);
        self::assertSame([], $this->recordedNoticesOf($runId, except: $wake['fields']['invocationId']), 'Until its wake ran, the platform had seen the run in that snapshot alone.');
        self::assertSame([Shop::DEFINITION, 'application'], [$shipped['definition'], $shipped['origin']]);
        self::assertSame([202, Shop::DEFINITION, $runId, 2], [$wake['status'], $wake['fields']['definition'], $wake['fields']['runId'], $wake['fields']['attempt']]);
        self::assertSame('spent', $this->platform->run($refused['runId'])['state']);
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame([], $this->platform->alerts());
    }

    /**
     * The same run, seen this time in the answer to an abandon. The platform issued a start for the order and
     * the queue lost it; the shop then started the order itself. An operator abandons the run of the lost
     * start: the endpoint answers 409 and shows the run that holds the workflow ID.
     */
    public function test_a_run_seen_only_in_the_answer_to_an_abandon_is_adopted_and_its_timer_fires(): void
    {
        $lost = $this->fire(self::ORDER);
        $this->platform->tick();
        $this->shop->queue()->move(SpoolDispatcher::SEGMENTS, self::LOST);
        $runId = $this->theOrderSleepsAndThePlatformNeverHeardOfIt();
        self::assertSame([$lost], array_column($this->platform->runsOf(self::workflowIdOf(self::ORDER)), 'runId'));

        $this->platform->abandon($lost);
        $sent = $this->platform->tick();
        $adopted = $this->platform->run($runId);
        $shipped = $this->awaitClosed($runId, 'completed');

        [$abandon] = $this->crossed(WireLog::ABANDON, self::ORDER);
        [$wake] = $this->crossed(WireLog::WAKE, self::ORDER);
        self::assertSame([['abandon', 409]], array_map(static fn (array $message): array => [$message['action'], $message['status']], $sent), 'The platform had sent nothing else when it held the run.');
        self::assertSame([409, 'stale_run', true], [$abandon['status'], $abandon['answer']['code'], $abandon['answer']['claimed']]);
        self::assertSame([$runId, Shop::DEFINITION, 'suspended'], [$abandon['answer']['run']['runId'], $abandon['answer']['run']['definition'], $abandon['answer']['run']['status']]);
        self::assertSame([Shop::DEFINITION, 'application', 'open', ['wake']], [$adopted['definition'], $adopted['origin'], $adopted['state'], array_column($adopted['timers'], 'action')]);
        self::assertSame([], $this->recordedNoticesOf($runId, except: $wake['fields']['invocationId']), 'Until its wake ran, the platform had recorded no notice of the run.');
        self::assertSame([Shop::DEFINITION, 'application'], [$shipped['definition'], $shipped['origin']]);
        self::assertSame([202, Shop::DEFINITION, $runId, 2], [$wake['status'], $wake['fields']['definition'], $wake['fields']['runId'], $wake['fields']['attempt']]);
        self::assertSame('ended_unknown', $this->platform->run($lost)['state'], 'The answer says that the lost start is claimed, and not by whom.');
        $this->assertNodeBodiesFinished(self::ORDER, awaitPayment: 1, ship: 1);
        self::assertSame([], $this->platform->alerts());
    }

    /**
     * The order is checked out and paid, and sleeps until it ships. The checkout ran in its request and left
     * its two notices on the queue; the platform failed to record the two of the payment, which a worker then
     * left there too. The queue loses all four before any worker of the notices runs.
     *
     * @return string the run ID, which only the store of the shop knows
     */
    protected function theOrderSleepsAndThePlatformNeverHeardOfIt(): string
    {
        $this->shop->startWorker(SpoolDispatcher::SEGMENTS);
        self::assertSame(200, $this->shop->checkout(self::ORDER, self::NOTE, inPlace: true)['status']);
        $this->platform->configure(['announcementsLost' => 1, 'reportsLost' => 1]);
        self::assertSame(202, $this->shop->pay(self::ORDER, 49.9, self::PAYMENT_REFERENCE));

        Wait::until('the order sleeps until it ships', fn (): bool => ($this->stored(self::ORDER)['wait']['type'] ?? null) === 'sleep_until');
        Wait::until('the four notices wait in the queue', fn (): bool => count($this->shop->queue()->waiting(SpoolDispatcher::NOTICES)) === 4);
        $this->shop->queue()->move(SpoolDispatcher::NOTICES, self::LOST);

        return (string) $this->stored(self::ORDER)['runId'];
    }

    /**
     * @param string $except the invocation ID of a segment whose notices are left out
     * @return array<int, array<string, mixed>> the announcements and the reports of a run that the platform recorded
     */
    protected function recordedNoticesOf(string $runId, string $except): array
    {
        $recorded = array_filter(
            [...$this->crossed(WireLog::ANNOUNCEMENT, self::ORDER), ...$this->crossed(WireLog::REPORT, self::ORDER)],
            static fn (array $notice): bool => $notice['status'] === 200
                && $notice['fields']['runId'] === $runId
                && $notice['fields']['invocationId'] !== $except,
        );

        return array_values(array_column($recorded, 'fields'));
    }
}
