<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Faults\StoreFailed;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\CloseTaleNode;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\StoredRun;
use NeuronCore\IQ\Tests\Support\Faults\StoreOperation;
use NeuronCore\IQ\Tests\Support\Streamed\FaultedStream;
use NeuronCore\IQ\Tests\Support\Streamed\FaultedStreams;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function json_encode;
use function max;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * The store fails once in the middle of a stream, and that for every
 * operation the healthy stream asks of the store from its first step to its
 * end: at the operation, which then does nothing, or after the write, which
 * is then committed with its answer lost.
 *
 * Each test reads what the streams of one such sweep left behind, and names
 * every stream that broke its rule.
 */
class FailingStoreTest extends TestCase
{
    /** @var array<int|string, FaultedStreams> */
    protected static array $sweeps = [];

    public static function tearDownAfterClass(): void
    {
        self::$sweeps = [];
    }

    #[DataProvider('sweeps')]
    public function test_the_fault_strikes_in_every_stream_of_the_sweep(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $sweep = $this->sweep($backend, $kind, $ending, $mode);

        $spared = array_filter($sweep->faulted, static fn (FaultedStream $stream): bool => !$stream->struck instanceof StoreOperation);

        self::assertNotEmpty($sweep->faulted, 'The healthy stream asks nothing of the store that the fault can hit.');
        self::assertSame([], array_column($spared, 'label'), 'The stream ended before the fault could strike.');
    }

    #[DataProvider('sweeps')]
    public function test_the_consumer_gets_the_exception_of_the_store_unless_a_node_had_thrown_its_own(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $thrown = $stream->taken->thrown;
            $fromTheStore = $thrown instanceof StoreFailed && str_contains($thrown->getMessage(), (string) $stream->struck?->describe());

            if (self::aNodeThrew($stream, $ending) ? !$thrown instanceof NodeFailed : !$fromTheStore) {
                $wrong[] = "{$stream->label}: the consumer got " . ($thrown === null ? 'a state' : $thrown::class . ' (' . $thrown->getMessage() . ')');
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_frames_given_before_the_fault_are_the_first_frames_of_the_healthy_stream(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $sweep = $this->sweep($backend, $kind, $ending, $mode);
        $healthy = self::outlineOf($sweep->clean);
        $wrong = [];

        foreach ($sweep->faulted as $stream) {
            $given = self::outlineOf($stream);

            if ($given !== array_slice($healthy, 0, count($given))) {
                $wrong[] = "{$stream->label}: the consumer was given " . json_encode($given, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_every_stream_is_announced_once_and_observed_once(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $verbs = array_map(static fn (Invocation $message): Verb => $message->verb, $stream->queued);

            if ($verbs !== [Verb::Announce, Verb::Observe]) {
                $wrong[] = "{$stream->label}: the queue took " . json_encode(array_column($verbs, 'value'), JSON_THROW_ON_ERROR);
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

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $observed = $stream->observed() ?? [];
            $expected = $stream->attemptsOpened === []
                ? [Outcome::Aborted->value, Code::NotExecuted->value, null]
                : [Outcome::Failed->value, null, $stream->attemptsOpened[0]];

            if ([$observed['outcome'] ?? null, $observed['code'] ?? null, $observed['executedAttempt'] ?? null] !== $expected) {
                $wrong[] = "{$stream->label}: the observe carries " . json_encode($observed, JSON_THROW_ON_ERROR)
                    . ', Neuron opened ' . json_encode($stream->attemptsOpened, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_observe_names_the_class_of_what_the_consumer_got(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $named = $stream->observed()['errorClass'] ?? null;

            if ($stream->taken->thrown === null || $named !== $stream->taken->thrown::class) {
                $wrong[] = "{$stream->label}: the observe names " . json_encode($named, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_no_node_body_runs_twice_and_no_second_run_is_created(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $twice = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            if (max($stream->bodiesRun) > 1 || $stream->runsCreated > 1 || count($stream->attemptsOpened) > 1) {
                $twice[] = "{$stream->label}: node bodies " . json_encode($stream->bodiesRun, JSON_THROW_ON_ERROR)
                    . ", runs created {$stream->runsCreated}, segments opened " . json_encode($stream->attemptsOpened, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $twice);
    }

    #[DataProvider('sweeps')]
    public function test_the_process_that_streams_makes_no_http_request_whatever_the_store_does(
        string $backend,
        Kind $kind,
        Ending $ending,
        FaultMode $mode,
    ): void {
        $called = array_filter(
            $this->sweep($backend, $kind, $ending, $mode)->faulted,
            static fn (FaultedStream $stream): bool => $stream->requestsOfTheCaller !== 0,
        );

        self::assertSame([], array_column($called, 'label'));
    }

    #[DataProvider('sweeps')]
    public function test_the_report_a_worker_then_posts_keeps_the_outcome_of_the_observe(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $observed = $stream->observed() ?? [];
            $reported = $stream->reported ?? [];

            foreach (['outcome', 'code', 'errorClass', 'executedAttempt'] as $field) {
                if ($observed === [] || ($observed[$field] ?? null) !== ($reported[$field] ?? null)) {
                    $wrong[] = "{$stream->label}: observed " . json_encode($observed, JSON_THROW_ON_ERROR)
                        . ', reported ' . json_encode($reported, JSON_THROW_ON_ERROR);

                    break;
                }
            }
        }

        self::assertSame([], $wrong);
    }

    #[DataProvider('sweeps')]
    public function test_the_report_a_worker_then_posts_shows_the_run_the_store_holds(string $backend, Kind $kind, Ending $ending, FaultMode $mode): void
    {
        $wrong = [];

        foreach ($this->sweep($backend, $kind, $ending, $mode)->faulted as $stream) {
            $reported = $stream->reported ?? [];

            if (($reported['snapshotRead'] ?? null) !== true || !StoredRun::same($reported['run'], $stream->leftWhenReported)) {
                $wrong[] = "{$stream->label}: reported " . json_encode($reported['run'] ?? null, JSON_THROW_ON_ERROR)
                    . ', the store held ' . json_encode($stream->leftWhenReported, JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * @return array<string, array{string, Kind, Ending, FaultMode}>
     */
    public static function sweeps(): array
    {
        $streams = [
            'a start that completes the run' => [Kind::Start, Ending::Completes],
            'a start that stops at a wait' => [Kind::Start, Ending::Waits],
            'a start whose last node throws' => [Kind::Start, Ending::Fails],
            'an answer that completes the run' => [Kind::Answer, Ending::Completes],
            'an answer whose last node throws' => [Kind::Answer, Ending::Fails],
            'a signal that stops at a wait' => [Kind::Signal, Ending::Waits],
        ];

        $sweeps = [];
        foreach (TestBench::backends() as $store => [$backend]) {
            foreach (FaultMode::cases() as $mode) {
                foreach ($streams as $label => [$kind, $ending]) {
                    $sweeps["{$label}, when {$mode->value}, on {$store}"] = [$backend, $kind, $ending, $mode];
                }
            }
        }

        return $sweeps;
    }

    /**
     * The sweep of a data set, played once for all the tests that read it.
     */
    protected function sweep(string $backend, Kind $kind, Ending $ending, FaultMode $mode): FaultedStreams
    {
        return self::$sweeps[$this->dataName()] ??= FaultedStreams::play($backend, $kind, $ending, $mode);
    }

    /**
     * Whether the last node threw during the stream: Neuron then throws what the node threw, whatever the store
     * answers to the write that marks the run failed.
     */
    protected static function aNodeThrew(FaultedStream $stream, Ending $ending): bool
    {
        return $ending === Ending::Fails && $stream->bodiesRun[CloseTaleNode::class] > 0;
    }

    /**
     * @return string[] what tells one frame from another, for every frame the consumer was given, in order
     */
    protected static function outlineOf(FaultedStream $stream): array
    {
        return array_map(
            static fn (array $frame): string => $frame[0] . ' ' . $frame[1]::class . ' ' . json_encode($frame[1], JSON_THROW_ON_ERROR),
            $stream->taken->frames,
        );
    }
}
