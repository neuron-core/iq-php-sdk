<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function array_column;
use function count;
use function max;
use function usort;

/**
 * The clock of the platform. One tick sends whatever is due: starts, wakes,
 * inspects and the abandons an operator asked for, one message at a time,
 * and treats each answer of the endpoint as the status table of appendix A
 * says. Nothing is sent to the application outside a tick, except the ping
 * of a sync.
 */
final class Clockwork
{
    /** How many times an abandon is sent again with the attempt the endpoint just showed. */
    protected const ABANDON_TRIES = 5;

    public function __construct(
        protected Settings $settings,
        protected Endpoint $endpoint,
        protected Runs $runs,
        protected Orders $orders,
        protected Segments $segments,
        protected Alerts $alerts,
        protected Plan $plan,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> what was sent, in order: the action, its reason, the run and the
     *                                          status of the answer
     */
    public function tick(float $now): array
    {
        if ($this->endpoint->url() === null) {
            return [];
        }

        $this->endpoint->pingToResume($now);
        $sent = [];

        foreach ($this->due($now) as $runId) {
            // Rule 17: timers that come due together are spread over several ticks, and nothing is sent to an
            // endpoint that asked for time or stopped verifying the signature.
            if (count($sent) >= $this->settings->whole('deliveriesPerTick') || !$this->endpoint->isOpen($now)) {
                break;
            }

            // Rule 6: a step taken earlier in this tick may have read a snapshot, so the step of this run is
            // planned again from what the platform holds now.
            $run = (array) $this->runs->find($runId);
            $step = $this->plan->next($run, $now);
            $status = $step === null ? null : $this->take($run, $step, $now);

            if ($step !== null && $status !== null) {
                $sent[] = ['action' => $step['action'], 'reason' => $step['reason'], 'runId' => $runId, 'status' => $status];
            }
        }

        return $sent;
    }

    /**
     * One message per run and tick, the longest due first.
     *
     * @return string[] the run IDs
     */
    protected function due(float $now): array
    {
        $due = [];

        foreach ($this->runs->toPlan() as $run) {
            $step = $this->plan->next($run, $now);

            if ($step !== null) {
                $due[] = [$run['run_id'], $step['at']];
            }
        }

        usort($due, static fn (array $one, array $other): int => $one[1] <=> $other[1]);

        return array_column($due, 0);
    }

    /**
     * @param array<string, mixed> $run
     * @param array{action: string, reason: string, at: int|float, held: string|null} $step
     * @return int|null the status of the answer; 0 when there was none; null when nothing was sent
     */
    protected function take(array $run, array $step, float $now): ?int
    {
        if ($step['action'] !== Plan::START && $this->wouldNameAnotherDefinition($run, $now)) {
            return null;
        }

        return match ($step['action']) {
            Plan::ABANDON => $this->abandon($run, $now),
            Plan::START => $this->start($run, $now),
            Plan::INSPECT => $this->ask($run, $step['reason'], $now),
            default => $this->wake($run, $step['reason'], $now),
        };
    }

    /**
     * Rule 3: a wake, an inspect and an abandon name the definition that started the run. When the last
     * snapshot that named the run shows another one, nothing is sent and a person is told. A snapshot that
     * shows none leaves nothing to compare.
     *
     * @param array<string, mixed> $run
     */
    protected function wouldNameAnotherDefinition(array $run, float $now): bool
    {
        if ($run['shown_definition'] === null || $run['shown_definition'] === $run['definition']) {
            return false;
        }

        $this->alerts->raise(
            Alerts::ANOTHER_DEFINITION,
            $run['run_id'],
            "The platform holds the run under '{$run['definition']}' and its last snapshot shows '{$run['shown_definition']}': nothing is sent to it.",
            $now,
            $run,
        );

        return true;
    }

    /**
     * Rules 1 and 4: the start of a firing, with the run ID and the use-by time it keeps on every re-send.
     * The use-by time is fixed when the start is first sent, so that a start which had to wait for the
     * endpoint still gets its whole lifetime.
     *
     * @param array<string, mixed> $run
     */
    protected function start(array $run, float $now): ?int
    {
        $start = (array) $this->orders->startOf($run['run_id']);

        if ($start['expires_at'] === null) {
            $start['expires_at'] = (int) $now + $this->settings->whole('startLifetime');
            $this->orders->update($start['invocation_id'], ['expires_at' => $start['expires_at']]);
        }

        if ($now >= $start['expires_at']) {
            // Rule 13: a start is never sent after its use-by time.
            $this->orders->update($start['invocation_id'], ['ended' => 'expired', 'due_at' => null]);

            return null;
        }

        $answer = $this->endpoint->send(Orders::START, [
            'protocol' => 1,
            'verb' => Orders::START,
            'invocationId' => $start['invocation_id'],
            'definition' => $run['definition'],
            'workflowId' => $run['workflow_id'],
            'runId' => $run['run_id'],
            'expiresAt' => $start['expires_at'],
        ]);
        $this->settle($start, $answer, $run, $now);

        return $answer->status;
    }

    /**
     * Rule 2: the wake carries the run ID and the attempt of the snapshot the platform holds.
     *
     * @param array<string, mixed> $run
     * @param string $purpose the timer of a wait, or the retry of an attempt
     */
    protected function wake(array $run, string $purpose, float $now): int
    {
        $wake = $this->orders->wakeFor($run['run_id'], $run['attempt'], $purpose)
            ?? $this->orders->issue(Orders::WAKE, $run['run_id'], $run['attempt'], $purpose, $now);

        $answer = $this->endpoint->send(Orders::WAKE, [
            'protocol' => 1,
            'verb' => Orders::WAKE,
            'invocationId' => $wake['invocation_id'],
            'definition' => $run['definition'],
            'workflowId' => $run['workflow_id'],
            'runId' => $run['run_id'],
            'attempt' => $run['attempt'],
        ]);
        $this->settle($wake, $answer, $run, $now);

        return $answer->status;
    }

    /**
     * The last column of the status table of appendix A, for an order.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $run
     */
    protected function settle(array $order, Answer $answer, array $run, float $now): void
    {
        $sends = $order['sends'] + 1;
        $fields = ['sends' => $sends, 'status' => $answer->status, 'accepted_at' => null, 'due_at' => null];

        switch ($answer->status) {
            case 202:
                // Verified, valid, queued: nothing has run and the platform assumes nothing yet.
                $fields['accepted_at'] = $now;
                break;
            case 503:
                $fields['due_at'] = $this->pause($answer, $sends, $now);
                break;
            case 404:
                // Sent again with a back-off while the version is in the manifest: the plan holds a stranded one.
                $fields['due_at'] = $now + $this->settings->backOff($sends);
                break;
            case 400:
            case 405:
                $fields['ended'] = "refused {$answer->status}";
                $this->alertOnRefusal($order['verb'], $order['invocation_id'], $run, $now);
                break;
            case 401:
                // Deliveries stop and the timer is kept.
                $fields['due_at'] = $now;
                $this->stopDeliveries($now);
                break;
            default:
                $fields = $this->afterNoUsableAnswer($order, $run, $now) + $fields;
        }

        $this->orders->update($order['invocation_id'], $fields);
    }

    /**
     * A 500, a 3xx, a timeout: nobody knows whether the order was queued. It is sent again a bounded number of
     * times. After the last of them the order is not ended (rule 13): a person is told, and the order counts
     * as accepted and silent. Rule 10 then asks about a wake and sends it again, and rule 12 asks about a start.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $run
     * @return array<string, mixed> column => value
     */
    protected function afterNoUsableAnswer(array $order, array $run, float $now): array
    {
        $uncertain = $order['uncertain'] + 1;

        if ($uncertain <= $this->settings->whole('uncertainResends')) {
            return ['uncertain' => $uncertain, 'due_at' => $now + $this->settings->backOff($uncertain)];
        }

        $this->alerts->raise(
            Alerts::UNANSWERED,
            $order['invocation_id'],
            "The endpoint gave no usable answer to a {$order['verb']} {$uncertain} times: it counts as accepted and silent.",
            $now,
            $run,
        );

        return ['uncertain' => $uncertain, 'accepted_at' => $now];
    }

    /**
     * Rule 10, "silence means ask", and every other reason the plan has for a question. The answer is read
     * like any other snapshot.
     *
     * @param array<string, mixed> $run
     */
    protected function ask(array $run, string $reason, float $now): int
    {
        $answer = $this->endpoint->send(Plan::INSPECT, [
            'protocol' => 1,
            'verb' => Plan::INSPECT,
            'definition' => $run['definition'],
            'workflowId' => $run['workflow_id'],
            'runId' => $run['run_id'],
        ]);

        match ($answer->status) {
            200 => $this->learn($run, $reason, $answer->fields, $now),
            400, 405 => $this->inspectRefused($run, $now),
            401 => $this->stopDeliveries($now),
            503 => $this->runs->update($run['run_id'], ['ask_after' => $this->pause($answer, 1, $now)]),
            default => $this->askAgain('ask', $run, $answer->status, $now),
        };

        return $answer->status;
    }

    /**
     * @param array<string, mixed> $run as it was held before the answer
     * @param array<string, mixed> $answer the body of a 200
     */
    protected function learn(array $run, string $reason, array $answer, float $now): void
    {
        $runId = $run['run_id'];
        $this->runs->update($runId, ['ask_after' => null, 'ask_status' => null, 'ask_failures' => 0]);

        if (!$answer['snapshotRead']) {
            // Rule 6: an answer that shows nothing is asked for again, later each time, whatever the question was.
            $this->runs->unread($runId, $now, again: true);

            return;
        }

        $this->runs->read($run['workflow_id'], $answer['run'], $answer['at'], $now);

        $held = (array) $this->runs->find($runId);
        $showsTheSame = $held['status'] === $run['status'] && $held['attempt'] === $run['attempt'];
        $this->runs->update($runId, [
            'asked_at' => $now,
            'reads' => $reason === 'periodic_read' && $showsTheSame ? $run['reads'] + 1 : $held['reads'],
        ]);

        $showsTheRun = ($answer['run']['runId'] ?? null) === $runId;
        if ($held['closed_as'] !== null) {
            return;
        }

        if ($run['abandon'] === 'asking') {
            $this->learnAfterAnAbandon($held, $showsTheRun, $answer['at']);
        } elseif (!$showsTheRun && $held['seen_at'] === null) {
            $this->learnOfARunNeverSeen($held, $answer, $now);
        }
    }

    /**
     * Appendix A, after a 409 to an abandon that showed no run: a run with that run ID that is shown now is
     * abandoned again with the attempt shown; when none is shown the run has ended, and nothing says how.
     *
     * @param array<string, mixed> $run
     */
    protected function learnAfterAnAbandon(array $run, bool $showsTheRun, int $at): void
    {
        if ($showsTheRun) {
            $this->runs->update($run['run_id'], ['abandon' => 'requested']);
        } else {
            $this->runs->close($run['run_id'], Runs::ENDED_UNKNOWN, $at);
        }
    }

    /**
     * The answer shows no run, or another run, where no snapshot ever named this one.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $answer
     */
    protected function learnOfARunNeverSeen(array $run, array $answer, float $now): void
    {
        $start = $this->orders->startOf($run['run_id']);

        if ($start === null) {
            // Rule 7: a run the application started that no snapshot ever named has ended. Rule 12: only a start
            // that expired in the queue and that no copy claimed is known never to have run.
            $neverRan = $answer['claimed'] === false && $this->segments->startExpired($run['run_id']);
            $this->runs->close($run['run_id'], $neverRan ? Runs::LOST : Runs::ENDED_UNKNOWN, $answer['at']);

            return;
        }

        if ($answer['claimed'] === false) {
            // Rule 13: no copy claimed the start, so the plan sends it again while its use-by time allows.
            $this->runs->update($run['run_id'], ['unclaimed_at' => $now]);
        }

        if ($start['expires_at'] === null || $now < $start['expires_at'] + $this->settings->get('startSilence')) {
            return;
        }

        // Rule 12. Unclaimed: no copy ran and none can any more. Claimed: it completed without a report, was
        // abandoned, or stopped before its first write. An entry asked for too late may have been dropped.
        $mayHaveRun = $answer['claimed'] !== false || $now > $start['expires_at'] + $this->settings->get('ledgerRetention');

        $this->runs->update($run['run_id'], ['start_asked' => 1]);
        $this->runs->close($run['run_id'], $mayHaveRun ? Runs::ENDED_UNKNOWN : Runs::LOST, $answer['at']);
    }

    /**
     * Rules 12 and 18: a 400 or a 405 shows nothing of the run. A run that another definition started holds
     * the workflow ID, or someone else holds the signing key: a person is told and the question is never sent
     * again. A start that stayed silent is not asked about any more; whatever else the platform wants to know
     * of the run waits for the length of a periodic read.
     *
     * @param array<string, mixed> $run
     */
    protected function inspectRefused(array $run, float $now): void
    {
        $this->alertOnRefusal(Plan::INSPECT, $run['run_id'], $run, $now);
        $this->runs->update($run['run_id'], [
            'start_asked' => 1,
            'unread_at' => null,
            'abandon' => $run['abandon'] === 'asking' ? 'refused' : $run['abandon'],
            'ask_after' => $now + $this->settings->get('periodicRead'),
            'ask_status' => null,
            'ask_failures' => 0,
        ]);
    }

    /**
     * The status table of appendix A, for an inspect or an abandon that got no usable answer. After a 404 the
     * question is sent again with a back-off while the version is in the manifest: the plan holds a stranded
     * one. After a 500, a 3xx or a timeout it is sent again with that back-off a bounded number of times in a
     * row. After the last of them a person is told, and the question is never forgotten (rule 13): it is asked
     * again at the cap of the back-off until an answer is read.
     *
     * @param string $question "ask" for an inspect, "abandon" for an abandon: what the run counts it under
     * @param array<string, mixed> $run
     */
    protected function askAgain(string $question, array $run, int $status, float $now): void
    {
        $lacksTheCode = $status === 404;
        $inARow = ($run["{$question}_status"] === 404) === $lacksTheCode ? $run["{$question}_failures"] + 1 : 1;
        $ranOut = !$lacksTheCode && $inARow > $this->settings->whole('uncertainResends');

        if ($ranOut) {
            $this->alertOnSilence($question === 'ask' ? Plan::INSPECT : Plan::ABANDON, $run, $now);
        }

        $this->runs->update($run['run_id'], [
            "{$question}_status" => $status,
            "{$question}_failures" => $inARow,
            "{$question}_after" => $now + ($ranOut ? $this->settings->longestBackOff() : $this->settings->backOff($inARow)),
        ]);
    }

    /**
     * Rule 5: only because an operator asked. Rule 2: with the attempt the snapshot shows, and with none for
     * a start the platform sent and no snapshot ever named. The operator is refused any other run no snapshot
     * named.
     *
     * @param array<string, mixed> $run
     */
    protected function abandon(array $run, float $now): int
    {
        $runId = $run['run_id'];
        $answer = $this->endpoint->send(Plan::ABANDON, [
            'protocol' => 1,
            'verb' => Plan::ABANDON,
            'definition' => $run['definition'],
            'workflowId' => $run['workflow_id'],
            'runId' => $runId,
        ] + ($run['seen_at'] === null ? [] : ['attempt' => $run['attempt']]));

        match ($answer->status) {
            200 => $this->abandoned($run, $answer->fields, $now),
            409 => $this->abandonRefused($run, $answer->fields, $now),
            400, 405 => $this->abandonNeverSentAgain($run, $now),
            401 => $this->stopDeliveries($now),
            503 => $this->runs->update($runId, ['abandon_after' => $this->pause($answer, 1, $now)]),
            default => $this->askAgain('abandon', $run, $answer->status, $now),
        };

        return $answer->status;
    }

    /**
     * Rule 7: a 200 closes the run named in the abandon as abandoned, whether or not a snapshot ever named it.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $answer
     */
    protected function abandoned(array $run, array $answer, float $now): void
    {
        $this->runs->close($run['run_id'], Runs::ABANDONED, $answer['at']);

        if ($answer['snapshotRead']) {
            $this->runs->read($run['workflow_id'], $answer['run'], $answer['at'], $now);
        }
    }

    /**
     * Rule 7: a 409 is read like any other snapshot, and the platform plans from its run, never from its code.
     * The run it shows is abandoned again with the attempt shown. When it shows none, the platform inspects.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $answer
     */
    protected function abandonRefused(array $run, array $answer, float $now): void
    {
        $runId = $run['run_id'];

        if ($answer['snapshotRead']) {
            $this->runs->read($run['workflow_id'], $answer['run'], $answer['at'], $now);
        }

        if (((array) $this->runs->find($runId))['closed_as'] !== null) {
            // The snapshot showed that the run has left its workflow ID: there is nothing left to abandon.
            return;
        }

        $tries = $run['abandon_tries'] + 1;
        $showsTheRun = $answer['snapshotRead'] && ($answer['run']['runId'] ?? null) === $runId;
        $mayTryAgain = $answer['code'] !== 'abandon_refused' && $tries < self::ABANDON_TRIES;

        $this->runs->update($runId, [
            'abandon_tries' => $tries,
            'abandon_after' => null,
            'abandon_status' => null,
            'abandon_failures' => 0,
            'abandon' => match (true) {
                !$mayTryAgain => 'refused',
                $showsTheRun => 'requested',
                default => 'asking',
            },
        ]);
    }

    /**
     * @param array<string, mixed> $run
     */
    protected function abandonNeverSentAgain(array $run, float $now): void
    {
        $this->alertOnRefusal(Plan::ABANDON, $run['run_id'], $run, $now);
        $this->runs->update($run['run_id'], ['abandon' => 'refused']);
    }

    /**
     * Rule 17: honour Retry-After, for every message to the endpoint.
     *
     * @return float the instant the message may be sent again
     */
    protected function pause(Answer $answer, int $sends, float $now): float
    {
        $until = $now + max(1, $answer->retryAfter ?? $this->settings->backOff($sends));
        $this->endpoint->pauseUntil($until);

        return $until;
    }

    /**
     * A 401: the endpoint no longer verifies the signature. Deliveries stop, timers are kept and a person is told.
     */
    protected function stopDeliveries(float $now): void
    {
        $this->endpoint->stop($now);
        $this->alerts->raise(
            Alerts::INVALID_SIGNATURE,
            (string) (int) $now,
            'The endpoint answered 401: deliveries are stopped until a ping succeeds.',
            $now,
        );
    }

    /**
     * A 500, a 3xx or a timeout to a question and to every one of its bounded re-sends: nobody knows what became of it.
     *
     * @param string $verb inspect or abandon
     * @param array<string, mixed> $run
     */
    protected function alertOnSilence(string $verb, array $run, float $now): void
    {
        $this->alerts->raise(
            Alerts::UNANSWERED,
            "{$verb}:{$run['run_id']}",
            "The endpoint gave no usable answer to an {$verb} or to any of its bounded re-sends: it is asked again at a slow pace.",
            $now,
            $run,
        );
    }

    /**
     * A 400 or a 405: the message is never sent again.
     *
     * @param array<string, mixed> $run
     */
    protected function alertOnRefusal(string $verb, string $subject, array $run, float $now): void
    {
        $this->alerts->raise(
            Alerts::REFUSED_BY_ENDPOINT,
            "{$verb}:{$subject}",
            "The endpoint refused a {$verb} as malformed, or because another definition started the run it names.",
            $now,
            $run,
        );
    }
}
