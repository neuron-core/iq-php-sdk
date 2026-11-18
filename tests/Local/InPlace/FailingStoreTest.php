<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\InPlace;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Faults\StoreFailed;
use NeuronCore\IQ\Tests\Stub\Matrix\Ending;
use NeuronCore\IQ\Tests\Stub\Matrix\EndingNode;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Faults\StoreOperation;
use NeuronCore\IQ\Tests\Support\InPlace\FaultedCall;
use NeuronCore\IQ\Tests\Support\InPlace\FaultedCalls;
use NeuronCore\IQ\Tests\Support\InPlace\Kind;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_sum;
use function count;
use function json_encode;
use function max;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * The store fails once while the application runs a segment in its own
 * process, and that for every operation the call makes on a healthy store:
 * at the operation, which then does nothing, or after the write, which is
 * then committed with its answer lost.
 *
 * Each test reads what the calls of one such sweep left behind, and names
 * every call that broke its rule.
 */
class FailingStoreTest extends TestCase
{
    /** @var array<int|string, FaultedCalls> */
    protected static array $sweeps = [];

    public static function tearDownAfterClass(): void
    {
        self::$sweeps = [];
    }

    #[DataProvider('sweeps')]
    public function test_the_fault_strikes_in_every_call_of_the_sweep(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $sweep = $this->sweep($backend, $kind, $ending, $mode);

        $spared = array_filter($sweep->faulted, static fn (FaultedCall $call): bool => !$call->struck instanceof StoreOperation);

        self::assertNotEmpty($sweep->faulted, 'The clean call makes no operation the fault can hit.');
        self::assertSame([], array_column($spared, 'label'), 'The call under test ended before the fault could strike.');
    }

    #[DataProvider('sweeps')]
    public function test_the_caller_gets_the_exception_of_the_store_unless_a_node_had_thrown_its_own(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            $thrown = $call->played->thrown;
            $fromTheStore = $thrown instanceof StoreFailed && str_contains($thrown->getMessage(), (string) $call->struck?->describe());

            if (self::aNodeThrew($call, $ending) ? !$thrown instanceof NodeFailed : !$fromTheStore) {
                $wrong[] = "{$call->label}: the caller got " . ($thrown === null ? 'a result' : $thrown::class . ' (' . $thrown->getMessage() . ')');
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_a_call_queues_nothing_or_an_announcement_and_its_observe(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if ($call->played->verbsQueued() !== [] && $call->played->verbsQueued() !== [Verb::Announce, Verb::Observe]) {
                $wrong[] = "{$call->label}: the queue took " . json_encode(array_column($call->played->verbsQueued(), 'value'), JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_a_fault_that_strikes_before_the_announcement_leaves_nothing_run_and_nothing_written(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            $unannounced = $call->played->queued === [];
            $leftSomething = array_sum($call->bodiesRun) > 0 || $call->played->attemptsOpened !== [] || $call->struck?->writes();

            if ($unannounced && $leftSomething) {
                $wrong[] = "{$call->label}: nothing was announced, yet the call ran a node body or reached a write";
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_observe_says_aborted_when_neuron_opened_no_segment_and_failed_under_the_attempt_of_the_one_it_opened(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if ($call->played->queued === []) {
                continue;
            }

            $observed = $call->played->observe()->body ?? [];
            $expected = $call->played->attemptsOpened === []
                ? [Outcome::Aborted->value, Code::NotExecuted->value, null]
                : [Outcome::Failed->value, null, $call->played->attemptsOpened[0]];

            if ([$observed['outcome'], $observed['code'], $observed['executedAttempt']] !== $expected) {
                $wrong[] = "{$call->label}: the observe carries " . json_encode($observed, JSON_THROW_ON_ERROR)
                    . ', Neuron opened ' . json_encode($call->played->attemptsOpened, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_observe_names_the_class_of_what_the_caller_got(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if ($call->played->queued === []) {
                continue;
            }

            $named = $call->played->observe()->body['errorClass'] ?? null;

            if ($call->played->thrown === null || $named !== $call->played->thrown::class) {
                $wrong[] = "{$call->label}: the observe names " . json_encode($named, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_no_node_body_runs_twice_and_no_second_run_is_created(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $twice = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if (max($call->bodiesRun) > 1 || $call->runsCreated > 1 || count($call->played->attemptsOpened) > 1) {
                $twice[] = "{$call->label}: node bodies " . json_encode($call->bodiesRun, JSON_THROW_ON_ERROR)
                    . ", runs created {$call->runsCreated}, segments opened " . json_encode($call->played->attemptsOpened, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $twice);
    }

    #[DataProvider('sweeps')]
    public function test_the_calling_process_makes_no_http_request_whatever_the_store_does(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $called = array_filter(
            $this->sweep($backend, $kind, $ending, $mode)->faulted,
            static fn (FaultedCall $call): bool => $call->played->requests !== 0,
        );

        self::assertSame([], array_column($called, 'label'));
    }

    #[DataProvider('sweeps')]
    public function test_the_report_a_worker_then_posts_keeps_the_outcome_of_the_observe(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if ($call->played->queued === []) {
                continue;
            }

            $observed = $call->played->observe()->body ?? [];
            $kept = [$observed['outcome'], $observed['code'], $observed['errorClass'], $observed['executedAttempt']];

            if ($call->reported === null || $kept !== [$call->reported['outcome'], $call->reported['code'], $call->reported['errorClass'], $call->reported['executedAttempt']]) {
                $wrong[] = "{$call->label}: observed " . json_encode($observed, JSON_THROW_ON_ERROR)
                    . ', reported ' . json_encode($call->reported, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_report_a_worker_then_posts_shows_the_run_the_store_holds(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $read = 0;
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $call) {
            if ($call->reported === null) {
                continue;
            }

            $read++;

            if ($call->reported['snapshotRead'] !== true || !StoredRun::same($call->reported['run'], $call->leftWhenReported)) {
                $wrong[] = "{$call->label}: reported " . json_encode($call->reported['run'], JSON_THROW_ON_ERROR)
                    . ', the store held ' . json_encode($call->leftWhenReported, JSON_THROW_ON_ERROR);
            }
        }

        self::assertGreaterThan(0, $read, 'No call of the sweep was reported.');
        self::assertSame([], $wrong);
    }

    /**
     * @return array<string, array{string, Kind, Ending, FaultMode}>
     */
    public static function sweeps(): array
    {
        $calls = [
            'a start that completes the run' => [Kind::Start, Ending::Completes],
            'a start that stops at a wait' => [Kind::Start, Ending::Waits],
            'a start whose last node throws' => [Kind::Start, Ending::Fails],
            'an answer that completes the run' => [Kind::Answer, Ending::Completes],
            'a signal that stops at a wait' => [Kind::Signal, Ending::Waits],
            'the wake of recover() that completes the run' => [Kind::Recovery, Ending::Completes],
            'the wake of recover() whose last node throws' => [Kind::Recovery, Ending::Fails],
        ];

        $sweeps = [];
        foreach (TestBench::backends() as $store => [$backend]) {
            foreach (FaultMode::cases() as $mode) {
                foreach ($calls as $label => [$kind, $ending]) {
                    $sweeps["{$label}, when {$mode->value}, on {$store}"] = [$backend, $kind, $ending, $mode];
                }
            }
        }

        return $sweeps;
    }

    /**
     * The sweep of a data set, played once for all the tests that read it.
     */
    protected function sweep(string $backend, Kind $kind, Ending $ending, FaultMode $mode): FaultedCalls
    {
        return self::$sweeps[$this->dataName()] ??= FaultedCalls::play($backend, $kind, $ending, $mode);
    }

    /**
     * Whether the last node threw during the call: Neuron then throws what the node threw, whatever the store
     * answers to the write that marks the run failed.
     */
    protected static function aNodeThrew(FaultedCall $call, Ending $ending): bool
    {
        return $ending === Ending::Fails && $call->bodiesRun[EndingNode::class] > 0;
    }
}
