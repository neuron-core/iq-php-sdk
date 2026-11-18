<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Faults;

use Closure;
use Composer\InstalledVersions;
use NeuronAI\HttpClient\HttpRequest;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Support\Faults\FaultCase;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\FaultSweep;
use NeuronCore\IQ\Tests\Support\Faults\Sighting;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Faults\StoreOperation;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_diff_key;
use function array_filter;
use function array_unique;
use function count;
use function is_array;
use function json_encode;
use function preg_match;
use function str_ends_with;
use function version_compare;

use const JSON_THROW_ON_ERROR;

/**
 * An order is delivered while the store fails once, and that for every
 * operation a clean delivery of it makes: at the operation, which then does
 * nothing, or after the write, which is then committed with its answer lost.
 * The same order is delivered again over a healthy store, then the wakes the
 * snapshots call for, then a late copy of the start.
 *
 * Each test reads what the cases of one such sweep left behind, and names
 * every case that broke its rule.
 */
class StoreFaultTest extends TestCase
{
    /** @var array<int|string, FaultSweep> */
    protected static array $sweeps = [];

    public static function tearDownAfterClass(): void
    {
        self::$sweeps = [];
    }

    #[DataProvider('sweeps')]
    public function test_the_fault_strikes_in_every_case_of_the_sweep(Closure $play): void
    {
        $sweep = $this->sweep($play);

        $spared = array_filter($sweep->faulted, static fn (FaultCase $case): bool => !$case->struck instanceof StoreOperation);

        self::assertNotEmpty($sweep->faulted, 'A clean delivery makes no operation the fault can hit.');
        self::assertSame([], array_column($spared, 'label'), 'The delivery under test ended before the fault could strike.');
    }

    #[DataProvider('sweeps')]
    public function test_a_node_body_runs_again_only_when_the_fault_left_its_step_uncommitted(Closure $play): void
    {
        $sweep = $this->sweep($play);
        $twice = [];

        foreach ($sweep->cases() as $case) {
            // The node that ended a clean run is the one whose step has no record of its own.
            $endedBy = self::theEndOfARunCommitsItsLastStep() && $case->leftTheEndUnsettled() ? $sweep->clean->lastExecuted : null;

            foreach ($case->executions as $node => $ran) {
                $clean = $sweep->clean->executions[$node] ?? 0;

                if ($ran > $clean + (int) ($case->leftUncommitted($node) || $node === $endedBy)) {
                    $twice[] = "{$case->label}: {$node} ran {$ran} times, {$clean} in a clean run";
                }
            }
        }

        self::assertSame([], $twice);
    }

    #[DataProvider('sweeps')]
    public function test_a_run_id_is_executed_by_one_start_at_most(Closure $play): void
    {
        $twice = [];

        foreach ($this->sweep($play)->cases() as $case) {
            $starts = array_filter($case->sightings, static fn (Sighting $sighting): bool => $sighting->report['verb'] === Verb::Start->value);
            $attempts = array_unique(array_filter(array_column(array_column($starts, 'report'), 'executedAttempt')));

            if ($case->ignitions > 1 || count($attempts) > 1) {
                $twice[] = "{$case->label}: the store created {$case->ignitions} runs, and the starts reported "
                    . count($attempts) . ' attempts they executed under';
            }
        }

        self::assertSame([], $twice);
    }

    #[DataProvider('sweeps')]
    public function test_everything_the_platform_is_sent_matches_its_schema(Closure $play): void
    {
        $broken = [];

        foreach ($this->sweep($play)->cases() as $case) {
            foreach ($case->requests as $request) {
                $violations = ProtocolSchema::violations($this->messageOf($request), is_array($request->body) ? $request->body : []);

                if ($violations !== []) {
                    $broken[] = "{$case->label}: " . json_encode(['body' => $request->body, 'violations' => $violations], JSON_THROW_ON_ERROR);
                }
            }
        }

        self::assertSame([], $broken);
    }

    #[DataProvider('sweeps')]
    public function test_completed_is_reported_only_for_a_run_that_completed(Closure $play): void
    {
        $sweep = $this->sweep($play);
        $completed = 0;
        $premature = [];

        foreach ($sweep->cases() as $case) {
            foreach ($case->sightings as $sighting) {
                if ($sighting->report['outcome'] !== Outcome::Completed->value) {
                    continue;
                }

                $completed++;

                if (!$sighting->ended || $sighting->stored !== null) {
                    $premature[] = "{$case->label}: the store still held " . json_encode($sighting->stored, JSON_THROW_ON_ERROR);
                }

                foreach ($sweep->clean->executions as $node => $clean) {
                    if (($case->executions[$node] ?? 0) < $clean) {
                        $premature[] = "{$case->label}: {$node} ran less than the {$clean} times of a clean run";
                    }
                }
            }
        }

        self::assertSame($sweep->clean->ended, $completed > 0, 'Completed is reported in a sweep when its clean run completes, and only then.');
        self::assertSame([], $premature);
    }

    #[DataProvider('sweeps')]
    public function test_a_report_whose_snapshot_was_read_shows_the_run_the_store_holds(Closure $play): void
    {
        $read = 0;
        $wrong = [];

        foreach ($this->sweep($play)->cases() as $case) {
            foreach ($case->sightings as $sighting) {
                if ($sighting->report['snapshotRead'] !== true) {
                    continue;
                }

                $read++;

                if (!StoredRun::same($sighting->report['run'], $sighting->stored)) {
                    $wrong[] = "{$case->label}: reported " . json_encode($sighting->report['run'], JSON_THROW_ON_ERROR)
                        . ', the store held ' . json_encode($sighting->stored, JSON_THROW_ON_ERROR);
                }
            }
        }

        self::assertGreaterThan(0, $read, 'No report of the sweep carried a snapshot.');
        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_wakes_its_snapshots_call_for_bring_a_run_that_started_where_a_clean_run_ends(Closure $play): void
    {
        $sweep = $this->sweep($play);
        $astray = [];

        foreach ($sweep->cases() as $case) {
            // A start that was claimed and never ignited is spent: no run is left to bring anywhere.
            $expected = $case->ignitions === 0 ? null : $sweep->clean->left;

            if (!StoredRun::same($this->standing($case->left), $this->standing($expected))) {
                $astray[] = "{$case->label}: the store holds " . json_encode($case->left, JSON_THROW_ON_ERROR)
                    . ', a clean run leaves ' . json_encode($expected, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $astray);
    }

    #[DataProvider('sweeps')]
    public function test_invoke_throws_for_an_abort_only_when_the_application_issued_it_and_no_start_was_claimed(Closure $play): void
    {
        $wrong = [];

        foreach ($this->sweep($play)->cases() as $case) {
            foreach ($case->sightings as $sighting) {
                $report = $sighting->report;
                $toTryAgain = $report['origin'] === Origin::APPLICATION
                    && $report['outcome'] === Outcome::Aborted->value
                    && $report['claimed'] !== true;

                if ($sighting->thrown?->value !== ($toTryAgain ? $report['code'] : null)) {
                    $wrong[] = "{$case->label}: invoke() " . ($sighting->thrown === null ? 'returned' : "threw '{$sighting->thrown->value}'")
                        . ' after it reported ' . json_encode($report, JSON_THROW_ON_ERROR);
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * @return iterable<string, array{Closure(): FaultSweep}>
     */
    public static function sweeps(): iterable
    {
        // The definition, who issues its start, and how many orders of the run are delivered before the one under test.
        $orders = [
            'a platform start that suspends' => ['order', Origin::PLATFORM, 0],
            'a platform start that waits for an event' => ['payment', Origin::PLATFORM, 0],
            'a platform start that completes' => ['greeting', Origin::PLATFORM, 0],
            'a platform start whose node throws' => ['flaky', Origin::PLATFORM, 0],
            'a wake that suspends again' => ['order', Origin::PLATFORM, 1],
            'a wake that completes the run' => ['order', Origin::PLATFORM, 2],
            'a wake that retries a failed run' => ['flaky', Origin::PLATFORM, 1],
            'an application start that suspends' => ['order', Origin::APPLICATION, 0],
            'a signal of the application that continues a run' => ['payment', Origin::PLATFORM, 1],
        ];

        foreach (TestBench::backends() as $store => [$backend]) {
            foreach (FaultMode::cases() as $mode) {
                foreach ($orders as $order => [$definition, $origin, $ordersBefore]) {
                    yield "{$store}: {$mode->value} of {$order}" => [
                        static fn (): FaultSweep => FaultSweep::play($backend, $mode, $definition, $origin, $ordersBefore),
                    ];
                }
            }
        }
    }

    /**
     * Neuron wrote a record for every step up to 4.1.10. From 4.1.11 on the step that ends a run has none:
     * settling the run commits it. A version that is no release, as a branch installed from source, is taken
     * for the current one.
     */
    protected static function theEndOfARunCommitsItsLastStep(): bool
    {
        $installed = (string) InstalledVersions::getVersion('neuron-core/neuron-ai');

        return preg_match('/^\d+\.\d+\.\d+/', $installed) !== 1 || version_compare($installed, '4.1.11', '>=');
    }

    /**
     * A sweep waits for real leases to pass, so it is played once and read by every test of its data set.
     *
     * @param Closure(): FaultSweep $play
     */
    protected function sweep(Closure $play): FaultSweep
    {
        return self::$sweeps[$this->dataName()] ??= $play();
    }

    /**
     * Where a run stands, however many attempts brought it there.
     *
     * @param array<string, mixed>|null $run
     * @return array<string, mixed>|null
     */
    protected function standing(?array $run): ?array
    {
        return $run === null ? null : array_diff_key($run, ['attempt' => true]);
    }

    protected function messageOf(HttpRequest $request): string
    {
        return str_ends_with($request->uri, '/v1/reports') ? 'report' : 'announcement';
    }
}
