<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

/**
 * Rule 18: what the platform tells a person, in a list a test can read. An
 * alert is raised once for the thing it is about.
 */
final class Alerts
{
    /** An announcement or a report of a platform order the platform never issued: the signing key may have leaked. */
    public const UNISSUED_ORDER = 'unissued_order';

    /** A wake reported "rejected: wrong_definition". */
    public const WRONG_DEFINITION = 'wrong_definition';

    /** Rule 3: the last snapshot of a run shows another definition than the one the platform would name. */
    public const ANOTHER_DEFINITION = 'another_definition';

    /** A 400 or a 405 answer of the endpoint: the message is never sent again. */
    public const REFUSED_BY_ENDPOINT = 'refused_by_endpoint';

    /** A 401 answer of the endpoint: deliveries stop until a ping succeeds. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** A report whose "at" is too far from the platform's clock. */
    public const REPORT_CLOCK = 'report_clock';

    /** Rule 9: the retry policy gave up, and the run is left failed. */
    public const RETRIES_EXHAUSTED = 'retries_exhausted';

    /** Rule 11: a run that is running with no lease was started outside the SDK. */
    public const RUNNING_WITHOUT_LEASE = 'running_without_lease';

    /** An order or a question that got no usable answer as often as its bounded re-sends allow. */
    public const UNANSWERED = 'unanswered';

    /** An answer of the endpoint that breaks its JSON Schema. */
    public const PROTOCOL_VIOLATION = 'protocol_violation';

    /** Rule 16: a sync that names another host than the one the environment is on, with no owner's consent. */
    public const ENDPOINT_MOVE = 'endpoint_move';

    public function __construct(protected Database $database)
    {
    }

    /**
     * @param string $subject what the alert is about: a second alert of the same kind about it is not raised
     * @param array<string, mixed>|null $run the run it concerns, as the platform holds it
     */
    public function raise(string $kind, string $subject, string $detail, float $now, ?array $run = null): void
    {
        $this->database->write(
            'INSERT OR IGNORE INTO alerts (at, kind, subject, run_id, workflow_id, detail) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $now, $kind, $subject, $run['run_id'] ?? null, $run['workflow_id'] ?? null, $detail],
        );
    }

    /**
     * @return array<int, array<string, mixed>> in the order they were raised
     */
    public function all(): array
    {
        return $this->database->rows('SELECT id, at, kind, subject, run_id AS runId, workflow_id AS workflowId, detail FROM alerts ORDER BY id');
    }
}
