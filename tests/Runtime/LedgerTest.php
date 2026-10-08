<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Runtime\Claim;
use NeuronCore\Cloud\Runtime\Ledger;
use NeuronCore\Cloud\Tests\Stub\RacingPersistence;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function hash;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

class LedgerTest extends CloudTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    #[DataProvider('backends')]
    public function test_a_claim_writes_the_run_id_and_its_use_by_time_in_the_record_of_its_workflow_id(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);
        $useBy = time() + 900;

        self::assertSame(Claim::Written, $ledger->claim(self::WORKFLOW_ID, '1-abc', $useBy));

        self::assertSame(
            '{"1-abc":' . $useBy . '}',
            $this->store()->get('__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID), 'starts'),
        );
    }

    #[DataProvider('backends')]
    public function test_a_second_claim_of_the_same_run_id_finds_it_there(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);
        $ledger->claim(self::WORKFLOW_ID, '1-abc', time() + 900);

        self::assertSame(Claim::AlreadyThere, $ledger->claim(self::WORKFLOW_ID, '1-abc', time() + 900));
        self::assertCount(1, $this->entries());
    }

    #[DataProvider('backends')]
    public function test_a_claim_whose_use_by_time_has_passed_is_refused_and_writes_nothing(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);

        self::assertSame(Claim::Expired, $ledger->claim(self::WORKFLOW_ID, '1-abc', time()));
        self::assertSame([], $this->entries());
        self::assertFalse($ledger->has(self::WORKFLOW_ID, '1-abc'));
    }

    #[DataProvider('backends')]
    public function test_the_starts_of_one_workflow_id_share_one_record(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);
        $useBy = time() + 900;

        $ledger->claim(self::WORKFLOW_ID, '1-abc', $useBy);
        self::assertSame(Claim::Written, $ledger->claim(self::WORKFLOW_ID, '2-def', $useBy + 60));

        self::assertSame(['1-abc' => $useBy, '2-def' => $useBy + 60], $this->entries());
    }

    #[DataProvider('backends')]
    public function test_a_run_id_is_claimed_for_one_workflow_id_only(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);
        $ledger->claim(self::WORKFLOW_ID, '1-abc', time() + 900);

        self::assertTrue($ledger->has(self::WORKFLOW_ID, '1-abc'));
        self::assertFalse($ledger->has(self::WORKFLOW_ID, '1-other'));
        self::assertFalse($ledger->has('order:124', '1-abc'));
        self::assertSame(Claim::Written, $ledger->claim('order:124', '1-abc', time() + 900));
    }

    #[DataProvider('backends')]
    public function test_a_claim_drops_the_entries_more_than_an_hour_past_their_use_by_time(string $backend): void
    {
        $ledger = $this->ledgerOn($backend);
        $now = time();
        $this->seed(['1-long-gone' => $now - 3700, '1-kept' => $now - 3500, '1-pending' => $now + 60]);

        // No claim has dropped it yet: an entry stays in the ledger until one does.
        self::assertTrue($ledger->has(self::WORKFLOW_ID, '1-long-gone'));

        $ledger->claim(self::WORKFLOW_ID, '1-new', $now + 900);

        self::assertSame(['1-kept' => $now - 3500, '1-pending' => $now + 60, '1-new' => $now + 900], $this->entries());
    }

    #[DataProvider('backends')]
    public function test_a_claim_that_loses_its_write_to_another_process_reads_again(string $backend): void
    {
        $ledger = $this->ledgerRacingOn($backend, 4);

        self::assertSame(Claim::Written, $ledger->claim(self::WORKFLOW_ID, '1-abc', time() + 900));

        self::assertSame(['1-rival-1', '1-rival-2', '1-rival-3', '1-rival-4', '1-abc'], array_keys($this->entries()));
    }

    #[DataProvider('backends')]
    public function test_a_claim_gives_up_after_five_lost_writes_and_writes_nothing(string $backend): void
    {
        $ledger = $this->ledgerRacingOn($backend, 5);

        try {
            $ledger->claim(self::WORKFLOW_ID, '1-abc', time() + 900);
            self::fail('The claim should have given up.');
        } catch (CloudException $gaveUp) {
            self::assertSame(Code::Unavailable, $gaveUp->reason);
        }

        self::assertFalse($ledger->has(self::WORKFLOW_ID, '1-abc'));
        self::assertCount(5, $this->entries());
    }

    protected function ledgerOn(string $backend): Ledger
    {
        return new Ledger($this->benchOn($backend)->persistence);
    }

    /**
     * A ledger whose first $races conditional writes each lose to another process claiming a start of the same workflow ID.
     */
    protected function ledgerRacingOn(string $backend, int $races): Ledger
    {
        $rival = $this->ledgerOn($backend);
        $rivals = 0;

        $store = new RacingPersistence($this->store(), $races, static function () use ($rival, &$rivals): void {
            $rival->claim(self::WORKFLOW_ID, '1-rival-' . ++$rivals, time() + 900);
        });

        return new Ledger(static fn (): PersistenceInterface => $store);
    }

    protected function store(): PersistenceInterface
    {
        return ($this->bench->persistence)();
    }

    /**
     * @return array<string, int> the record of the workflow ID, as the store holds it
     */
    protected function entries(): array
    {
        $stored = $this->store()->get('__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID), 'starts');

        return $stored === null ? [] : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, int> $entries
     */
    protected function seed(array $entries): void
    {
        $this->store()->initializeIfAbsent(
            '__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID),
            'starts',
            json_encode($entries, JSON_THROW_ON_ERROR),
        );
    }
}
