<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Support\CloudTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;

use function array_slice;
use function is_array;
use function parse_url;

use const PHP_URL_PATH;

/**
 * A test of a segment that the application runs in its own process: on a
 * stage, once per backend, and for every kind of segment where the data
 * provider says so.
 */
abstract class InPlaceTestCase extends CloudTestCase
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    /** The format of an invocation ID in appendix A of the design. */
    protected const INVOCATION_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    /** The format of a run ID minted by the SDK or the platform in appendix A of the design. */
    protected const MINTED_RUN_ID = '/^[1-9][0-9]{0,5}-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    protected function assertPostConditions(): void
    {
        if (isset($this->bench)) {
            $this->bench->assertProtocolRespected();
        }
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
     * @return array<string, array{string, Kind}> the kinds of segment that are prepared before they are used
     */
    public static function preparedKinds(): array
    {
        return self::onBothBackends([
            Kind::Start->label() => [Kind::Start],
            Kind::Answer->label() => [Kind::Answer],
            Kind::Signal->label() => [Kind::Signal],
        ]);
    }

    /**
     * @return array<string, array{string, Kind}> the kinds of segment that continue a run
     */
    public static function continuations(): array
    {
        return self::onBothBackends([
            Kind::Answer->label() => [Kind::Answer],
            Kind::Signal->label() => [Kind::Signal],
            Kind::Recovery->label() => [Kind::Recovery],
        ]);
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment, ended every way a node can end it
     */
    public static function kindsAndEndings(): array
    {
        return self::onBothBackends(self::endedEveryWay(Kind::cases(), Ending::cases()));
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment that is prepared before it is used,
     *         ended every way a node can end it
     */
    public static function preparedKindsAndEndings(): array
    {
        return self::onBothBackends(self::endedEveryWay([Kind::Start, Kind::Answer, Kind::Signal], Ending::cases()));
    }

    /**
     * @return array<string, array{string, Kind, Ending}> every kind of segment, ended each way that returns a state
     */
    public static function kindsThatReturnAState(): array
    {
        return self::onBothBackends(self::endedEveryWay(Kind::cases(), [Ending::Completes, Ending::Waits]));
    }

    /**
     * @return array<string, array{string, Kind, Ending, RefusalReason}> every kind of segment, with how the process
     *         that got to the workflow ID first ended its own segment, and what Neuron then answers
     */
    public static function refusals(): array
    {
        return self::onBothBackends([
            'a start that meets a suspended run' => [Kind::Start, Ending::Waits, RefusalReason::RunInFlight],
            'a start that meets a failed run' => [Kind::Start, Ending::Fails, RefusalReason::RunInFlight],
            'an answer another request gave first' => [Kind::Answer, Ending::Waits, RefusalReason::StaleAttempt],
            'an answer for a run another request completed' => [Kind::Answer, Ending::Completes, RefusalReason::StaleRun],
            'a signal another request gave first' => [Kind::Signal, Ending::Waits, RefusalReason::StaleAttempt],
            'a signal for a run another request completed' => [Kind::Signal, Ending::Completes, RefusalReason::StaleRun],
            'the wake of recover() overtaken by a wake that reached a wait' => [Kind::Recovery, Ending::Waits, RefusalReason::StaleAttempt],
            'the wake of recover() overtaken by a wake that completed the run' => [Kind::Recovery, Ending::Completes, RefusalReason::StaleRun],
            'the wake of recover() overtaken by a wake that failed again' => [Kind::Recovery, Ending::Fails, RefusalReason::StaleAttempt],
        ]);
    }

    /**
     * @param Kind[] $kinds
     * @param Ending[] $endings
     * @return array<string, array{Kind, Ending}>
     */
    protected static function endedEveryWay(array $kinds, array $endings): array
    {
        $rows = [];
        foreach ($kinds as $kind) {
            foreach ($endings as $ending) {
                $how = match ($ending) {
                    Ending::Completes => 'that completes the run',
                    Ending::Waits => 'that stops at a wait',
                    Ending::Fails => 'whose last node throws',
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
        return Stage::on($this->benchOn($backend), $kind, $ending, $lease);
    }

    protected function stageWithoutQueue(string $backend, Kind $kind, Ending $ending = Ending::Completes): Stage
    {
        return Stage::withoutQueue($this->benchOn($backend), $kind, $ending);
    }

    /**
     * How a segment ends when its last node ends it that way.
     */
    protected static function outcomeOf(Ending $ending): Outcome
    {
        return match ($ending) {
            Ending::Completes => Outcome::Completed,
            Ending::Waits => Outcome::Suspended,
            Ending::Fails => Outcome::Failed,
        };
    }

    /**
     * The run ID of the segment that was played, from what Neuron returned or left in the store: never from a notice.
     */
    protected static function runIdOf(Stage $stage, Played $played): string
    {
        return $played->returned instanceof WorkflowState
            ? (string) $played->returned->getRunId()
            : (string) ($stage->stored()['runId'] ?? '');
    }

    /**
     * @param int $from how many requests the platform had received before
     * @return array<int, array{string, array<string, mixed>}> the path and the body of every request the platform
     *         received since, in order
     */
    protected function requestsSince(int $from): array
    {
        $requests = [];
        foreach (array_slice($this->bench->platform->received(), $from) as $request) {
            $requests[] = [(string) parse_url($request->uri, PHP_URL_PATH), is_array($request->body) ? $request->body : []];
        }

        return $requests;
    }
}
