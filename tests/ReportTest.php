<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function get_object_vars;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class ReportTest extends TestCase
{
    protected const RUN_ID = '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    /** The report of the wire protocol, as the design shows it. */
    protected const REPORT_OF_THE_PROTOCOL = '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
        . ' "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",'
        . ' "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 2,'
        . ' "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "status": "suspended", "attempt": 2, "leaseExpiresAt": null,'
        . ' "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791457200}},'
        . ' "claimed": null, "snapshotRead": true, "at": 1791453604}';

    protected const SUSPENDED_RUN = [
        'runId' => self::RUN_ID,
        'status' => 'suspended',
        'attempt' => 2,
        'leaseExpiresAt' => null,
        'wait' => ['interruptId' => 2, 'type' => 'sleep_until', 'eventName' => null, 'wakeAt' => 1791457200],
    ];

    public function test_the_array_form_is_the_report_of_the_protocol_field_by_field(): void
    {
        $report = self::report(Verb::Wake, Outcome::Suspended, executedAttempt: 2, run: self::SUSPENDED_RUN);

        self::assertSame(json_decode(self::REPORT_OF_THE_PROTOCOL, true, flags: JSON_THROW_ON_ERROR), $report->toArray());
    }

    public function test_a_report_has_one_property_for_each_field_of_the_protocol(): void
    {
        $report = self::report(Verb::Wake, Outcome::Suspended, executedAttempt: 2, run: self::SUSPENDED_RUN);

        self::assertSame(
            ['invocationId', 'verb', 'origin', 'definition', 'workflowId', 'runId', 'outcome', 'code', 'errorClass', 'executedAttempt', 'run', 'claimed', 'snapshotRead', 'at'],
            array_keys(get_object_vars($report)),
        );
    }

    public function test_the_outcomes_are_those_of_the_protocol(): void
    {
        self::assertSame(
            ['completed', 'suspended', 'failed', 'refused', 'rejected', 'aborted', 'observed'],
            array_column(Outcome::cases(), 'value'),
        );
    }

    public function test_the_codes_are_those_of_the_protocol_and_no_other(): void
    {
        $ofTheProtocol = [
            // Answers of the endpoint
            'invalid_signature', 'invalid_request', 'unsupported_protocol', 'unknown_definition', 'unknown_version',
            'dispatch_failed', 'unavailable', 'abandon_refused', 'internal_error',
            // Reports: rejected, then aborted
            'expired', 'invalid_start', 'already_claimed', 'wrong_definition', 'not_executed', 'announce_failed',
            // Answers of the platform
            'endpoint_unverified', 'unauthorized',
            // Thrown locally only
            'report_undelivered', 'unsuitable_persistence', 'not_configured', 'segment_used',
        ];

        self::assertEqualsCanonicalizing($ofTheProtocol, array_column(Code::cases(), 'value'));
    }

    #[DataProvider('reports')]
    public function test_the_array_form_matches_the_schema_of_the_protocol(Report $report): void
    {
        ProtocolSchema::assertValid('report', $report->toArray());
    }

    /**
     * @return array<string, array{Report}> a report of every outcome, as a job or an observe builds it
     */
    public static function reports(): array
    {
        $failedRun = ['runId' => self::RUN_ID, 'status' => 'failed', 'attempt' => 1, 'leaseExpiresAt' => null];

        return [
            'a start that completed' => [self::report(Verb::Start, Outcome::Completed, executedAttempt: 1, claimed: true)],
            'a wake that suspended' => [self::report(Verb::Wake, Outcome::Suspended, executedAttempt: 2, run: self::SUSPENDED_RUN)],
            'a start that failed' => [self::report(Verb::Start, Outcome::Failed, null, NodeFailed::class, 1, $failedRun, true)],
            'an answer Neuron refused' => [self::report(Verb::Answer, Outcome::Refused, RefusalReason::StaleAttempt->value, run: self::SUSPENDED_RUN)],
            'a start the SDK rejected' => [self::report(Verb::Start, Outcome::Rejected, Code::AlreadyClaimed->value, claimed: true)],
            'a wake the SDK rejected' => [self::report(Verb::Wake, Outcome::Rejected, Code::WrongDefinition->value, run: $failedRun)],
            'a signal that was aborted' => [self::report(Verb::Signal, Outcome::Aborted, Code::UnknownDefinition->value, snapshotRead: false)],
            'a start whose job died' => [self::report(Verb::Start, Outcome::Observed, run: $failedRun, claimed: true)],
        ];
    }

    /**
     * @param array<string, mixed>|null $run
     */
    protected static function report(
        Verb $verb,
        Outcome $outcome,
        ?string $code = null,
        ?string $errorClass = null,
        ?int $executedAttempt = null,
        ?array $run = null,
        ?bool $claimed = null,
        bool $snapshotRead = true,
    ): Report {
        return new Report(
            'inv_01JB7K4T9QW2',
            $verb,
            'platform',
            'order-fulfilment',
            'order:123',
            self::RUN_ID,
            $outcome,
            $code,
            $errorClass,
            $executedAttempt,
            $run,
            $claimed,
            $snapshotRead,
            1791453604,
        );
    }
}
