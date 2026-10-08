<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Step 5 of the runner: a start claims its run ID in the ledger immediately
 * before it is given to Neuron, and the claim is never undone.
 */
class ClaimTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_platform_start_that_loses_five_conditional_writes_is_aborted_as_unavailable(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalsWinTheLedger(5);

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::Unavailable->value, $report->code);
        self::assertSame(CloudException::class, $report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::Unavailable->value);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_loses_five_conditional_writes_writes_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalsWinTheLedger(5);

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertNull($report->claimed);
        self::assertSame(
            ['1-rival-1', '1-rival-2', '1-rival-3', '1-rival-4', '1-rival-5'],
            array_keys(json_decode((string) $this->ledgerRecord(), true, flags: JSON_THROW_ON_ERROR)),
        );
    }

    #[DataProvider('backends')]
    public function test_an_application_start_that_loses_five_conditional_writes_is_reported_then_thrown_as_unavailable(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalsWinTheLedger(5);

        $thrown = $this->thrownBy($this->start('greeting', self::APPLICATION));

        self::assertSame(Code::Unavailable, $thrown->reason);
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::Unavailable->value);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_loses_four_conditional_writes_claims_at_the_fifth_and_runs(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->rivalsWinTheLedger(4);

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertTrue($report->claimed);
        self::assertTrue($this->isClaimed());
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_claim_meets_a_failing_store_is_aborted_as_unavailable_and_runs_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->failStoreOn('get', $this->ledgerPartition());

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::Unavailable->value, $report->code);
        self::assertSame(StoreUnavailable::class, $report->errorClass);
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_start_that_expires_between_the_first_check_and_the_claim_is_rejected_as_expired_and_writes_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $expiresAt = time() + 2;
        $this->interceptStore(function (string $operation, string $partition) use ($expiresAt): void {
            if ($partition === $this->ledgerPartition()) {
                $this->holdUntil($expiresAt);
            }
        });

        $report = $bench->cloud->invoke($this->start('greeting', fields: ['expiresAt' => $expiresAt]));

        self::assertSame(Outcome::Rejected, $report->outcome);
        self::assertSame(Code::Expired->value, $report->code);
        self::assertCount(1, $this->announcementsSent(), 'The start had passed the first check: it was announced.');
        self::assertNull($report->claimed);
        self::assertNull($this->ledgerRecord());
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_platform_start_aborted_after_its_claim_is_not_executed_and_stays_claimed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->neuronCannotWriteTheRun();

        $report = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertSame(StoreUnavailable::class, $report->errorClass);
        self::assertNull($report->executedAttempt);
        self::assertTrue($report->claimed);
        self::assertTrue($this->isClaimed(), 'The claim is never undone.');
        self::assertNull($report->run);
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('backends')]
    public function test_an_application_start_aborted_after_its_claim_is_reported_and_not_thrown(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->neuronCannotWriteTheRun();

        $report = $bench->cloud->invoke($this->start('greeting', self::APPLICATION));

        self::assertSame(Outcome::Aborted, $report->outcome);
        self::assertSame(Code::NotExecuted->value, $report->code);
        self::assertTrue($report->claimed, 'It cannot run again, so its worker must not queue it again.');
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Aborted, Code::NotExecuted->value);
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_a_start_aborted_after_its_claim_is_rejected_as_already_claimed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->neuronCannotWriteTheRun();
        $bench->cloud->invoke($this->start('greeting'));
        $bench->cloud->setPersistence($bench->persistence);

        $copy = $bench->cloud->invoke($this->start('greeting'));

        self::assertSame(Outcome::Rejected, $copy->outcome);
        self::assertSame(Code::AlreadyClaimed->value, $copy->code);
        self::assertTrue($copy->claimed);
        self::assertSame(0, $bench->executions->total(), 'A claimed start that did not run never runs.');
    }

    /**
     * Neuron creates a run with one conditional write in the partition named by the workflow ID: that write fails.
     */
    protected function neuronCannotWriteTheRun(): void
    {
        $this->failStoreOn('initializeIfAbsent', self::WORKFLOW_ID);
    }
}
