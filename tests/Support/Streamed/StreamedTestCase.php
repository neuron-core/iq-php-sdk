<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Streamed;

use Closure;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use Throwable;

/**
 * A test of a segment that the application streams in its own process: on a
 * stage, once per backend, and for every kind of segment where the data
 * provider says so. Whatever a test does, a stage that has a queue must have
 * made no call to the platform from the process that streams.
 */
abstract class StreamedTestCase extends IQTestCase
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    /** @var Stage[] the stages of the test */
    protected array $stages = [];

    /** @var TestBench[] the benches of the test, beside the one IQTestCase cleans up */
    protected array $otherBenches = [];

    protected function assertPostConditions(): void
    {
        foreach ([...(isset($this->bench) ? [$this->bench] : []), ...$this->otherBenches] as $bench) {
            $bench->assertProtocolRespected();
        }

        foreach ($this->stages as $stage) {
            if ($stage->hasAQueue) {
                self::assertSame(0, $stage->requestsOfTheCaller(), 'The process that streams called the platform, although a queue is set.');
            }
        }
    }

    protected function tearDown(): void
    {
        // A stream another request still holds writes to the store when it is released: before the store is removed.
        foreach ($this->stages as $stage) {
            $stage->release();
        }

        foreach ($this->otherBenches as $bench) {
            $bench->cleanUp();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, Kind}>
     */
    public static function kinds(): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            $rows[$kind->label()] = [$kind];
        }

        return self::onBothBackends($rows);
    }

    /**
     * @return array<string, array{string, Kind}> the kinds of segment that continue a run
     */
    public static function continuations(): array
    {
        return self::onBothBackends([
            Kind::Answer->label() => [Kind::Answer],
            Kind::Signal->label() => [Kind::Signal],
        ]);
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment, ended every way a node can end it
     */
    public static function kindsAndEndings(): array
    {
        return self::onBothBackends(self::endedEveryWay(Ending::cases()));
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment, ended each way that returns a state
     */
    public static function kindsThatReturnAState(): array
    {
        return self::onBothBackends(self::endedEveryWay([Ending::Completes, Ending::Waits]));
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment, ended each way that throws
     */
    public static function kindsThatThrow(): array
    {
        return self::onBothBackends(self::endedEveryWay([Ending::Fails, Ending::Refuses]));
    }

    /**
     * @param Ending[] $endings
     * @return array<string, array{Kind, Ending}>
     */
    protected static function endedEveryWay(array $endings): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            foreach ($endings as $ending) {
                $how = match ($ending) {
                    Ending::Completes => 'that completes the run',
                    Ending::Waits => 'that stops at a wait',
                    Ending::Fails => 'whose last node throws',
                    Ending::Refuses => 'whose last node throws a refusal of Neuron',
                };

                $rows["{$kind->label()} {$how}"] = [$kind, $ending];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, array<int, mixed>> $rows
     * @return array<string, array<int, mixed>> every row once per backend, with the backend in front
     */
    protected static function onBothBackends(array $rows): array
    {
        $cases = [];
        foreach (TestBench::backends() as $store => [$backend]) {
            foreach ($rows as $label => $row) {
                $cases["{$label}, on {$store}"] = [$backend, ...$row];
            }
        }

        return $cases;
    }

    /**
     * @param int $lease seconds
     */
    protected function stage(string $backend, Kind $kind, Ending $ending = Ending::Completes, int $lease = Stage::LEASE): Stage
    {
        return $this->stages[] = Stage::on($this->benchOn($backend), $kind, $ending, $lease);
    }

    protected function stageWithoutQueue(string $backend, Kind $kind, Ending $ending = Ending::Completes): Stage
    {
        return $this->stages[] = Stage::withoutQueue($this->benchOn($backend), $kind, $ending);
    }

    /**
     * The same stage on a store of its own, to play the same segment another way.
     */
    protected function sameStageApart(string $backend, Kind $kind, Ending $ending = Ending::Completes): Stage
    {
        return $this->stages[] = Stage::on($this->apart($backend), $kind, $ending);
    }

    /**
     * A bench beside the one of the test, with a store and a log of its own.
     */
    protected function apart(string $backend = TestBench::IN_MEMORY): TestBench
    {
        return $this->otherBenches[] = TestBench::on($backend);
    }

    /**
     * How a segment ends when its last node ends it that way.
     */
    protected static function outcomeOf(Ending $ending): Outcome
    {
        return match ($ending) {
            Ending::Completes => Outcome::Completed,
            Ending::Waits => Outcome::Suspended,
            Ending::Fails, Ending::Refuses => Outcome::Failed,
        };
    }

    /**
     * @param Closure(): mixed $call
     * @return Throwable|null what the call threw, whatever it is; null when it threw nothing
     */
    protected static function caught(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * The run ID of the segment that was streamed, from what Neuron returned or left in the store: never from a notice.
     */
    protected static function runIdOf(Stage $stage, Taken $taken): string
    {
        return (string) ($taken->returned?->getRunId() ?? $stage->stored()['runId'] ?? '');
    }
}
