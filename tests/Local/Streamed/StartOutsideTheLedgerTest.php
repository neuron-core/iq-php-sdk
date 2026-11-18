<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Ledger;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 9.2 of the design: a start executed in the calling process, with
 * run() or with events(), exists in one copy and is not entered in the
 * ledger. Its report says "claimed: false" although it ran.
 */
class StartOutsideTheLedgerTest extends StreamedTestCase
{
    #[DataProvider('endingsOfAStart')]
    public function test_a_start_streamed_in_place_writes_nothing_in_the_ledger(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);

        $stage->take($stage->stream());

        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('endingsOfAStart')]
    public function test_a_start_streamed_in_place_reads_and_writes_no_record_but_those_of_its_run(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $partitionsReached = [];
        $stage->interceptStore(static function (string $operation, string $partition) use (&$partitionsReached): void {
            $partitionsReached[$partition] = true;
        });

        $stage->take($stage->stream());

        self::assertSame([Stage::WORKFLOW_ID => true], $partitionsReached);
    }

    #[DataProvider('endingsOfAStart')]
    public function test_the_worker_that_posts_the_notices_of_a_streamed_start_writes_nothing_in_the_ledger_either(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $stage->take($stage->stream());

        $stage->drain();

        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('endingsOfAStart')]
    public function test_the_report_of_a_streamed_start_says_unclaimed_although_it_ran(string $backend, Ending $ending): void
    {
        $stage = $this->stage($backend, Kind::Start, $ending);
        $taken = $stage->take($stage->stream());

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([false, 1], [$report->claimed, $report->executedAttempt]);
        self::assertFalse((new Ledger($stage->bench->persistence))->has(Stage::WORKFLOW_ID, self::runIdOf($stage, $taken)));
    }

    #[DataProvider('backends')]
    public function test_a_start_whose_stream_was_abandoned_is_not_entered_in_the_ledger(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $events = $stage->stream();
        $events->current();
        unset($events);

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertFalse($report->claimed);
        self::assertNull($stage->ledgerRecord());
    }

    #[DataProvider('continuations')]
    public function test_the_report_of_a_streamed_answer_or_signal_says_nothing_of_the_ledger(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->take($stage->stream());

        [, $report] = $stage->drain();

        self::assertInstanceOf(Report::class, $report);
        self::assertNull($report->claimed);
    }

    /**
     * @return array<string, array{string, Ending}>
     */
    public static function endingsOfAStart(): array
    {
        return self::onBothBackends([
            'a start that completes the run' => [Ending::Completes],
            'a start that stops at a wait' => [Ending::Waits],
            'a start whose last node throws' => [Ending::Fails],
            'a start whose last node throws a refusal of Neuron' => [Ending::Refuses],
        ]);
    }
}
