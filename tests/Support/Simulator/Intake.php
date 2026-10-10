<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

use Closure;
use Throwable;

use function abs;
use function array_is_list;
use function explode;
use function in_array;
use function is_array;
use function json_decode;

/**
 * What the application sends the platform: a sync, an announcement or a
 * report. Rule 15: only coordination data is kept, "recorded" is answered
 * only after a durable write, and a run the platform did not start is
 * accepted like any other.
 */
final class Intake
{
    public const SYNC = 'sync';

    public const ANNOUNCEMENT = 'announcement';

    public const REPORT = 'report';

    /** Rule 7: a start reported "rejected" with one of these never runs under its run ID. */
    protected const FINAL_REJECTIONS = ['expired', 'invalid_start'];

    public function __construct(
        protected Database $database,
        protected Schemas $schemas,
        protected Settings $settings,
        protected Manifest $manifest,
        protected Endpoint $endpoint,
        protected Runs $runs,
        protected Orders $orders,
        protected Segments $segments,
        protected Alerts $alerts,
    ) {
    }

    /**
     * @param string $message SYNC, ANNOUNCEMENT or REPORT
     * @return array{int, array<string, mixed>} the status and the body of the answer
     */
    public function receive(string $message, string $body, float $now): array
    {
        if (!$this->speaksProtocolOne($message, $body)) {
            return [400, ['code' => 'unsupported_protocol']];
        }

        $fields = $this->schemas->read($message, $body);
        if ($fields === null) {
            return [400, ['code' => 'invalid_request']];
        }

        if ($message === self::SYNC) {
            return $this->sync($fields, $now);
        }

        if ($this->loses($message)) {
            return [503, []];
        }

        return $this->record(function () use ($message, $fields, $now): void {
            if ($message === self::ANNOUNCEMENT) {
                $this->announcement($fields, $now);
            } else {
                $this->report($fields, $now);
            }
        });
    }

    /**
     * A body that is no JSON object is malformed, whatever protocol it meant to speak.
     */
    protected function speaksProtocolOne(string $message, string $body): bool
    {
        $fields = json_decode($body, true);

        if (!is_array($fields) || array_is_list($fields)) {
            return true;
        }

        return $message === self::SYNC
            ? !is_array($fields['protocols'] ?? null) || in_array(1, $fields['protocols'], true)
            : ($fields['protocol'] ?? null) === 1;
    }

    /**
     * A fault of the platform itself, which a test plans: the notice is not recorded, and the application
     * must send it again.
     *
     * @param string $message ANNOUNCEMENT or REPORT
     */
    protected function loses(string $message): bool
    {
        $planned = $message === self::REPORT ? 'reportsLost' : 'announcementsLost';
        $left = $this->settings->whole($planned);

        if ($left > 0) {
            $this->settings->change([$planned => $left - 1]);
        }

        return $left > 0;
    }

    /**
     * Rule 15: answer "recorded" only after a durable write.
     *
     * @param Closure(): void $write
     * @return array{int, array<string, mixed>}
     */
    protected function record(Closure $write): array
    {
        try {
            $this->database->atomically($write);
        } catch (Throwable) {
            // Nothing was recorded: any answer but a 200 counts as undelivered, and the application sends it again.
            return [503, []];
        }

        return [200, ['recorded' => true]];
    }

    /**
     * Rule 16. The endpoint is pinged before the list is applied, so a wrong URL or key fails the deploy.
     *
     * @param array<string, mixed> $request
     * @return array{int, array<string, mixed>}
     */
    protected function sync(array $request, float $now): array
    {
        $url = $request['endpoint'];

        if (!$this->endpoint->isRoutable($url)) {
            return [422, ['code' => 'endpoint_unverified']];
        }

        if ($this->endpoint->wouldMoveUnconfirmed($url)) {
            $this->alerts->raise(
                Alerts::ENDPOINT_MOVE,
                $url,
                'A sync names an endpoint on another host than the one the environment is on: an owner must confirm it.',
                $now,
            );

            return [422, ['code' => 'endpoint_unverified']];
        }

        if ($this->endpoint->ping($url) !== 200) {
            return [422, ['code' => 'endpoint_unverified']];
        }

        $modified = $this->database->atomically(function () use ($request, $url): bool {
            $listChanged = $this->manifest->replace($request['definitions']);
            $endpointMoved = $this->endpoint->url() !== $url;
            $this->endpoint->moveTo($url);
            // The ping verified the signature: deliveries a 401 had stopped resume.
            $this->endpoint->resume();

            return $listChanged || $endpointMoved;
        });

        return [200, ['protocol' => 1, 'modified' => $modified] + $this->openRunsByVersion()];
    }

    /**
     * @return array{versions: array<int, array<string, mixed>>, stranded: array<int, array<string, mixed>>} the open
     *         runs of every published version, and of every version that left the code while runs remain
     */
    protected function openRunsByVersion(): array
    {
        $open = [];
        foreach ($this->runs->open() as $run) {
            $version = $run['definition'] . ' ' . $this->manifest->versionOf($run['definition'], $run['run_id']);
            $open[$version] = ($open[$version] ?? 0) + 1;
        }

        $versions = [];
        foreach ($this->manifest->all() as ['definition' => $definition, 'version' => $version]) {
            $versions[] = ['definition' => $definition, 'version' => $version, 'openRuns' => $open["{$definition} {$version}"] ?? 0];
            unset($open["{$definition} {$version}"]);
        }

        $stranded = [];
        foreach ($open as $version => $openRuns) {
            [$definition, $number] = explode(' ', $version);
            $stranded[] = ['definition' => $definition, 'version' => (int) $number, 'openRuns' => $openRuns];
        }

        return ['versions' => $versions, 'stranded' => $stranded];
    }

    /**
     * "A segment of this run is starting; its lease is leaseSeconds."
     *
     * @param array<string, mixed> $announcement
     */
    protected function announcement(array $announcement, float $now): void
    {
        $run = $this->runs->heardOf($announcement['runId'], $announcement['workflowId'], $announcement['definition'], $now);

        $this->segments->announced($announcement, $now);
        $this->alertOnUnissuedOrder($announcement, $run, $now);
        $this->runs->reopenForAnnouncement($run, $announcement['at']);
    }

    /**
     * "This is the run now": the outcome of a call, and the run as the store held it just before sending.
     *
     * @param array<string, mixed> $report
     */
    protected function report(array $report, float $now): void
    {
        $run = $this->runs->heardOf($report['runId'], $report['workflowId'], $report['definition'], $now);
        // Rule 6: a report whose snapshot was not read carries none. Its run and its claimed are ignored.
        $claimed = $report['snapshotRead'] ? $report['claimed'] : null;

        $this->segments->reported($report, $claimed, $this->saysTooEarly($report), $now);
        $this->alertOnUnissuedOrder($report, $run, $now);
        $this->alertOnClock($report, $run, $now);

        if ($report['snapshotRead']) {
            $this->runs->read($report['workflowId'], $report['run'], $report['at'], $now);
        } else {
            $this->runs->unread($report['runId'], $now);
        }

        // Rule 7: a report closes the run named by its run ID when its outcome is completed.
        if ($report['outcome'] === 'completed') {
            $this->runs->close($report['runId'], Runs::COMPLETED, $report['at']);
        }

        if ($report['outcome'] === 'failed') {
            $this->database->write(
                'UPDATE failures SET error_class = ? WHERE run_id = ? AND attempt = ?',
                [$report['errorClass'], $report['runId'], $report['executedAttempt']],
            );
        }

        if ($report['verb'] === Orders::START) {
            $this->startReported($report, $claimed, $now);
        }

        if ($report['verb'] === Orders::WAKE && $report['outcome'] === 'rejected') {
            $this->wakeRejected($report, $run, $now);
        }
    }

    /**
     * Rule 8: "too early by the application's clock" is what the report of a wake itself says: suspended, with
     * no segment executed, on the very wait and attempt the wake was sent for.
     *
     * @param array<string, mixed> $report
     */
    protected function saysTooEarly(array $report): bool
    {
        $wake = $this->orders->find($report['invocationId']);
        $shown = $report['snapshotRead'] ? $report['run'] : null;

        return $wake !== null
            && $wake['verb'] === Orders::WAKE
            && $report['outcome'] === 'suspended'
            && $report['executedAttempt'] === null
            && ($shown['runId'] ?? null) === $wake['run_id']
            && $shown['attempt'] === $wake['attempt']
            && 'timer:' . ($shown['wait']['interruptId'] ?? '') === $wake['purpose'];
    }

    /**
     * Rule 7. A start reported refused, rejected as expired or invalid, or aborted after its claim is never
     * sent again. When no snapshot ever named its run and the run is open, it never runs under that run ID:
     * it is spent. Its code is shown to an operator when the platform issued the start; a start the
     * application issued has no operator. "expired" says only that this copy did not run: the start stays
     * open and is asked about (rule 12).
     *
     * @param array<string, mixed> $report
     */
    protected function startReported(array $report, ?bool $claimed, float $now): void
    {
        $run = (array) $this->runs->find($report['runId']);
        $showsTheRun = $report['snapshotRead'] && ($report['run']['runId'] ?? null) === $report['runId'];

        if ($report['outcome'] === 'observed' && $claimed === false && !$showsTheRun) {
            // Rule 13: no copy claimed the start, so the platform may send its own again.
            $this->runs->update($report['runId'], ['unclaimed_at' => $now]);
        }

        $neverRuns = $report['outcome'] === 'refused'
            || ($report['outcome'] === 'rejected' && in_array($report['code'], self::FINAL_REJECTIONS, true))
            || ($report['outcome'] === 'aborted' && $claimed === true);

        if (!$neverRuns) {
            return;
        }

        $start = $this->orders->startOf($report['runId']);
        if ($start !== null) {
            $this->orders->update($start['invocation_id'], ['ended' => "reported {$report['outcome']}", 'due_at' => null]);
        }

        if ($report['code'] !== 'expired' && $run['seen_at'] === null && $run['closed_as'] === null) {
            $this->runs->close($report['runId'], Runs::SPENT, $report['at'], $start === null ? null : $report['code']);
        }
    }

    /**
     * Rules 7 and 18: a wake reported "rejected: wrong_definition" is never sent again, and a person is told.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $run
     */
    protected function wakeRejected(array $report, array $run, float $now): void
    {
        if ($this->orders->find($report['invocationId']) !== null) {
            $this->orders->update($report['invocationId'], ['ended' => 'wrong_definition', 'due_at' => null]);
        }

        $this->alerts->raise(
            Alerts::WRONG_DEFINITION,
            $report['invocationId'],
            'A wake was rejected: another definition started the run it names.',
            $now,
            $run,
        );
    }

    /**
     * Rule 18: an announcement, or a report, of a platform order the platform never issued.
     *
     * @param array<string, mixed> $notice
     * @param array<string, mixed> $run
     */
    protected function alertOnUnissuedOrder(array $notice, array $run, float $now): void
    {
        if ($notice['origin'] === 'platform' && $this->orders->find($notice['invocationId']) === null) {
            $this->alerts->raise(
                Alerts::UNISSUED_ORDER,
                $notice['invocationId'],
                "The application executed a {$notice['verb']} of the platform that the platform never issued.",
                $now,
                $run,
            );
        }
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $run
     */
    protected function alertOnClock(array $report, array $run, float $now): void
    {
        if (abs($report['at'] - $now) > $this->settings->get('reportClockTolerance')) {
            $this->alerts->raise(
                Alerts::REPORT_CLOCK,
                "{$report['invocationId']}@{$report['at']}",
                'The clock of a report is ' . ($report['at'] - (int) $now) . ' seconds from the clock of the platform.',
                $now,
                $run,
            );
        }
    }
}
