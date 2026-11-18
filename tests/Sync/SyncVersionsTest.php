<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Sync;

use NeuronCore\IQ\Tests\Support\EndpointFlows\SyncTestCase;

/**
 * What a SyncResult says about the versions of a deployment: which ones can
 * leave the code, and which ones left it while runs remain. The deployment
 * holds versions 1 and 2 of "order-fulfilment" and "nightly-reconciliation".
 */
class SyncVersionsTest extends SyncTestCase
{
    public function test_an_older_version_with_no_open_run_is_retirable(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 2, 14),
            self::openRuns('order-fulfilment', 1, 0),
        ]))->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
    }

    public function test_an_older_version_with_an_open_run_is_not_retirable(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 2, 0),
            self::openRuns('order-fulfilment', 1, 1),
        ]))->sync(self::ENDPOINT);

        self::assertSame([], $result->retirable);
    }

    public function test_the_highest_registered_version_is_not_retirable_although_no_run_is_open_on_it(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 2, 0),
            self::openRuns('nightly-reconciliation', 1, 0),
        ]))->sync(self::ENDPOINT);

        self::assertSame([], $result->retirable);
    }

    public function test_the_retirable_versions_are_listed_by_definition_in_the_order_of_the_answer(): void
    {
        $platform = self::platformAccepting(versions: [
            self::openRuns('nightly-reconciliation', 4, 0),
            self::openRuns('order-fulfilment', 2, 3),
            self::openRuns('nightly-reconciliation', 2, 0),
            self::openRuns('order-fulfilment', 1, 0),
            self::openRuns('nightly-reconciliation', 3, 9),
            self::openRuns('nightly-reconciliation', 1, 0),
        ]);
        $iq = self::shop($platform)
            ->register('nightly-reconciliation', self::workflow(...), version: 2)
            ->register('nightly-reconciliation', self::workflow(...), version: 3)
            ->register('nightly-reconciliation', self::workflow(...), version: 4);

        $result = $iq->sync(self::ENDPOINT);

        self::assertSame(['nightly-reconciliation' => [2, 1], 'order-fulfilment' => [1]], $result->retirable);
    }

    public function test_a_version_above_the_highest_registered_one_is_not_retirable(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 3, 0),
            self::openRuns('order-fulfilment', 2, 0),
        ]))->sync(self::ENDPOINT);

        self::assertSame([], $result->retirable);
    }

    public function test_a_version_of_a_definition_this_process_did_not_register_is_not_retirable(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 1, 0),
            self::openRuns('archived-import', 1, 0),
        ]))->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
    }

    public function test_a_version_the_answer_lists_twice_is_retirable_once(): void
    {
        $result = self::shop(self::platformAccepting(versions: [
            self::openRuns('order-fulfilment', 1, 0),
            self::openRuns('order-fulfilment', 1, 0),
        ]))->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
    }

    public function test_a_stranded_version_is_listed_with_the_runs_that_remain_on_it(): void
    {
        $result = self::shop(self::platformAccepting(stranded: [self::openRuns('order-fulfilment', 3, 2)]))->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [3 => 2]], $result->stranded);
    }

    public function test_a_stranded_version_of_a_definition_that_left_the_code_is_listed_too(): void
    {
        $result = self::shop(self::platformAccepting(stranded: [
            self::openRuns('trial', 3, 2),
            self::openRuns('order-fulfilment', 5, 1),
            self::openRuns('trial', 1, 7),
        ]))->sync(self::ENDPOINT);

        self::assertSame(['trial' => [3 => 2, 1 => 7], 'order-fulfilment' => [5 => 1]], $result->stranded);
    }

    public function test_a_stranded_version_is_never_retirable(): void
    {
        $result = self::shop(self::platformAccepting(
            versions: [self::openRuns('order-fulfilment', 2, 0)],
            stranded: [self::openRuns('trial', 1, 4)],
        ))->sync(self::ENDPOINT);

        self::assertSame([], $result->retirable);
    }

    public function test_an_answer_that_lists_nothing_gives_two_empty_lists(): void
    {
        $result = self::shop(self::platformAccepting())->sync(self::ENDPOINT);

        self::assertSame([[], []], [$result->retirable, $result->stranded]);
    }

    public function test_the_result_carries_the_three_fields_of_the_answer_together(): void
    {
        $result = self::shop(self::platformAccepting(
            versions: [self::openRuns('order-fulfilment', 2, 14), self::openRuns('order-fulfilment', 1, 0)],
            stranded: [self::openRuns('trial', 3, 2)],
            modified: false,
        ))->sync(self::ENDPOINT);

        self::assertFalse($result->modified);
        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
        self::assertSame(['trial' => [3 => 2]], $result->stranded);
    }
}
