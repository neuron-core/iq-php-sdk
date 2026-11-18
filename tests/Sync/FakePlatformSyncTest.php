<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Sync;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The fake platform as the test of an application uses it for App Sync: it
 * accepts a sync without pinging the endpoint, counts no run, and remembers
 * the list it accepted last. A sync needs a store every process shares, so
 * the deployment of these tests is on SQLite.
 */
class FakePlatformSyncTest extends IQTestCase
{
    use CatchesIQExceptions;

    protected const ENDPOINT = 'https://shop.example.com/iq';

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    public function test_the_answer_to_a_sync_lists_every_published_version_with_no_open_run_and_nothing_stranded(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);

        // The same list again, sent by hand: the answer is what sync() was given, but for "modified".
        $answer = $bench->platform->request($bench->platform->received()[0])->json();

        self::assertSame(
            [
                'protocol' => 1,
                'modified' => false,
                'versions' => [
                    ['definition' => 'order-fulfilment', 'version' => 1, 'openRuns' => 0],
                    ['definition' => 'order-fulfilment', 'version' => 2, 'openRuns' => 0],
                    ['definition' => 'order-fulfilment', 'version' => 3, 'openRuns' => 0],
                    ['definition' => 'nightly-reconciliation', 'version' => 1, 'openRuns' => 0],
                ],
                'stranded' => [],
            ],
            $answer,
        );
    }

    public function test_every_version_below_the_current_one_comes_back_retirable(): void
    {
        $result = $this->deployment()->iq->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [1, 2]], $result->retirable);
    }

    public function test_no_version_ever_comes_back_stranded(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);
        $redeployed = $this->redeployedWithout($bench);

        $result = $redeployed->sync(self::ENDPOINT);

        self::assertSame([], $result->stranded);
    }

    public function test_the_first_sync_modifies_and_the_same_sync_again_does_not(): void
    {
        $bench = $this->deployment();

        $first = $bench->iq->sync(self::ENDPOINT);
        $again = $bench->iq->sync(self::ENDPOINT);

        self::assertSame([true, false], [$first->modified, $again->modified]);
    }

    public function test_a_sync_of_another_list_modifies(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);
        $bench->iq->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 4);

        self::assertTrue($bench->iq->sync(self::ENDPOINT)->modified);
    }

    public function test_a_sync_of_the_same_list_for_another_endpoint_modifies(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);

        self::assertTrue($bench->iq->sync('https://eu.shop.example.com/iq')->modified);
    }

    public function test_a_sync_is_accepted_without_a_ping_of_the_endpoint(): void
    {
        $bench = $this->deployment();
        // No signing key: a ping of this deployment would be answered 401, and the platform would refuse the sync.
        $unverifiable = (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($bench->persistence)
            ->register('order-fulfilment', static fn (): Workflow => new Workflow());

        $unverifiable->sync(self::ENDPOINT);

        $bench->platform->assertSynced();
        self::assertSame([], $bench->platform->delivered());
    }

    public function test_fail_next_with_422_refuses_a_sync_as_endpoint_unverified(): void
    {
        $bench = $this->deployment();
        $bench->platform->failNext(1, 422);

        $reason = self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT));

        self::assertSame(Code::EndpointUnverified, $reason);
    }

    public function test_a_sync_refused_with_422_published_nothing(): void
    {
        $bench = $this->deployment();
        $bench->platform->failNext(1, 422);
        self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT));

        $published = $bench->iq->sync(self::ENDPOINT);

        self::assertTrue($published->modified, 'The list the platform refused is new to it.');
    }

    public function test_fail_next_refuses_only_as_many_syncs_as_it_is_told(): void
    {
        $bench = $this->deployment();
        $bench->platform->failNext(2, 422);

        $reasons = [
            self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT)),
            self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT)),
            self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT)),
        ];

        self::assertSame([Code::EndpointUnverified, Code::EndpointUnverified, null], $reasons);
    }

    public function test_assert_synced_passes_once_a_sync_was_accepted(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);

        $bench->platform->assertSynced();

        self::assertCount(1, $bench->platform->received());
    }

    public function test_assert_synced_fails_when_no_sync_was_sent(): void
    {
        $bench = $this->deployment();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The platform accepted no sync.');

        $bench->platform->assertSynced();
    }

    public function test_assert_synced_fails_when_the_only_sync_was_refused(): void
    {
        $bench = $this->deployment();
        $bench->platform->failNext(1, 422);
        self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT));

        $this->expectException(AssertionFailedError::class);

        $bench->platform->assertSynced();
    }

    public function test_assert_synced_fails_when_sync_refused_to_send_anything(): void
    {
        $bench = $this->deployment();
        self::reasonThrownBy(static fn (): mixed => $bench->iq->sync('http://shop.example.com/iq'));

        $this->expectException(AssertionFailedError::class);

        $bench->platform->assertSynced();
    }

    public function test_assert_synced_does_not_take_an_announcement_or_a_report_for_a_sync(): void
    {
        $bench = $this->benchOn(TestBench::SQLITE);
        $executions = $bench->executions;
        $bench->iq->register('completing', static fn (): Workflow => new CompletingWorkflow($executions));
        $bench->iq->workflow('completing', 'order:123')->start()->dispatch();
        $bench->dispatcher->runAll($bench->iq);

        $this->expectException(AssertionFailedError::class);

        $bench->platform->assertSynced();
    }

    public function test_assert_synced_still_passes_after_a_later_sync_was_refused(): void
    {
        $bench = $this->deployment();
        $bench->iq->sync(self::ENDPOINT);
        $bench->platform->failNext(1, 422);
        self::reasonThrownBy(static fn (): mixed => $bench->iq->sync(self::ENDPOINT));

        $bench->platform->assertSynced();

        self::assertCount(2, $bench->platform->received());
    }

    /**
     * A deployment whose code holds three versions of one definition and one of another.
     */
    protected function deployment(): TestBench
    {
        $bench = $this->benchOn(TestBench::SQLITE);

        $bench->iq
            ->register('order-fulfilment', static fn (): Workflow => new Workflow())
            ->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 2)
            ->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 3)
            ->register('nightly-reconciliation', static fn (): Workflow => new Workflow());

        return $bench;
    }

    /**
     * The next deploy of the same application, whose code lost "nightly-reconciliation" and two versions.
     */
    protected function redeployedWithout(TestBench $bench): IQ
    {
        return (new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform))
            ->setPersistence($bench->persistence)
            ->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 3);
    }
}
