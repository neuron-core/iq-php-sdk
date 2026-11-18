<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Protocol;

use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The JSON Schemas of resources/protocol/1 against the examples of the wire protocol.
 */
class SchemaTest extends TestCase
{
    protected const RUN = '{"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment", "status": "suspended", "attempt": 2,'
        . ' "leaseExpiresAt": null, "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791457200}}';

    /** A run that carries no tag, or a label of code outside the SDK that is not a definition name. */
    protected const RUN_OF_NO_DEFINITION = '{"runId": "nightly-import", "definition": null, "status": "running", "attempt": 1, "leaseExpiresAt": null}';

    protected const REPORT = '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
        . ' "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",'
        . ' "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 2, "run": ' . self::RUN . ','
        . ' "claimed": null, "snapshotRead": true, "at": 1791453604}';

    protected const START_REPORT = '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start", "origin": "platform",'
        . ' "definition": "nightly-reconciliation", "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W",'
        . ' "outcome": "completed", "code": null, "errorClass": null, "executedAttempt": 1, "run": null,'
        . ' "claimed": true, "snapshotRead": true, "at": 1791453604}';

    #[DataProvider('schemas')]
    public function test_a_schema_is_of_draft_2020_12(string $message): void
    {
        $source = (string) file_get_contents(ProtocolSchema::directory() . "/{$message}.json");

        self::assertSame('https://json-schema.org/draft/2020-12/schema', json_decode($source, true, flags: JSON_THROW_ON_ERROR)['$schema']);
    }

    #[DataProvider('schemas')]
    public function test_a_schema_closes_no_object_against_unknown_fields(string $message): void
    {
        $source = (string) file_get_contents(ProtocolSchema::directory() . "/{$message}.json");

        self::assertStringNotContainsString('additionalProperties', $source, 'Receivers ignore fields they do not know.');
        self::assertStringNotContainsString('unevaluatedProperties', $source, 'Receivers ignore fields they do not know.');
    }

    #[DataProvider('examples')]
    public function test_an_example_of_the_protocol_matches_its_schema(string $message, string $body): void
    {
        ProtocolSchema::assertValid($message, json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('examples')]
    public function test_a_field_the_receiver_does_not_know_is_let_through(string $message, string $body): void
    {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        ProtocolSchema::assertValid($message, $decoded + ['aFieldOfALaterProtocol' => ['any' => 'value']]);
    }

    #[DataProvider('violations')]
    public function test_a_body_that_breaks_the_protocol_does_not_match_its_schema(string $message, string $body): void
    {
        self::assertNotSame([], ProtocolSchema::violations($message, json_decode($body, true, flags: JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function schemas(): array
    {
        return [
            'sync' => ['sync'],
            'sync-answer' => ['sync-answer'],
            'announcement' => ['announcement'],
            'report' => ['report'],
            'start' => ['start'],
            'wake' => ['wake'],
            'inspect' => ['inspect'],
            'abandon' => ['abandon'],
            'ping' => ['ping'],
            'requested-sync' => ['requested-sync'],
            'inspect-answer' => ['inspect-answer'],
            'ping-answer' => ['ping-answer'],
            'error' => ['error'],
            'recorded' => ['recorded'],
            'platform-error' => ['platform-error'],
            'definitions' => ['definitions'],
        ];
    }

    /**
     * @return array<string, array{string, string}> a message and a body, as the design's wire protocol shows them
     */
    public static function examples(): array
    {
        return [
            'the sync request' => ['sync', '{"protocols": [1], "sdk": "iq-php/1.0.0", "endpoint": "https://shop.example.com/iq",'
                . ' "definitions": [{"definition": "order-fulfilment", "version": 2, "leaseSeconds": 600},'
                . ' {"definition": "order-fulfilment", "version": 1, "leaseSeconds": 600}]}'],
            'the sync answer' => ['sync-answer', '{"protocol": 1, "modified": true,'
                . ' "versions": [{"definition": "order-fulfilment", "version": 2, "openRuns": 14},'
                . ' {"definition": "order-fulfilment", "version": 1, "openRuns": 0}],'
                . ' "stranded": [{"definition": "trial", "version": 3, "openRuns": 2}]}'],
            'the announcement of a wake' => ['announcement', '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
                . ' "definition": "order-fulfilment", "workflowId": "order:123",'
                . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1, "leaseSeconds": 600, "at": 1791453603}'],
            'the announcement of a start, which has no attempt' => ['announcement', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start",'
                . ' "origin": "application", "definition": "order-fulfilment", "workflowId": "order:123",'
                . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": null, "leaseSeconds": 600, "at": 1791453603}'],
            'the report of a wake that suspended' => ['report', self::REPORT],
            'the report of a start that completed' => ['report', self::START_REPORT],
            'the report of a refused wake' => ['report', '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
                . ' "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",'
                . ' "outcome": "refused", "code": "stale_attempt", "errorClass": null, "executedAttempt": null, "run": ' . self::RUN . ','
                . ' "claimed": null, "snapshotRead": true, "at": 1791453604}'],
            'the report of a wake that names another definition than the one that started the run' => ['report', self::changed(self::REPORT, [
                'outcome' => 'rejected',
                'code' => 'wrong_definition',
                'executedAttempt' => null,
            ])],
            'the report of a start that was aborted' => ['report', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start", "origin": "platform",'
                . ' "definition": "nightly-reconciliation", "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W",'
                . ' "outcome": "aborted", "code": "not_executed", "errorClass": "PDOException", "executedAttempt": null, "run": null,'
                . ' "claimed": null, "snapshotRead": false, "at": 1791453604}'],
            'a start' => ['start', '{"protocol": 1, "verb": "start", "invocationId": "inv_01JB2M", "definition": "nightly-reconciliation",'
                . ' "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W", "expiresAt": 1791368100}'],
            'a wake' => ['wake', '{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB7K4T9QW2", "definition": "order-fulfilment",'
                . ' "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1}'],
            'an inspect' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e"}'],
            'an inspect without a run ID' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order:123"}'],
            'an abandon' => ['abandon', '{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e", "attempt": 2}'],
            'an abandon of a start nothing was heard of' => ['abandon', '{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e"}'],
            'a ping' => ['ping', '{"protocol": 1, "verb": "ping"}'],
            'a requested sync' => ['requested-sync', '{"protocol": 1, "verb": "sync"}'],
            'the answer to an inspect that found a run' => ['inspect-answer', '{"run": ' . self::RUN . ', "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'the answer to an inspect that found a run no definition started' => ['inspect-answer', '{"run": ' . self::RUN_OF_NO_DEFINITION . ', "claimed": null, "snapshotRead": true, "at": 1791453604}'],
            'the report of a start refused by a run no definition started' => ['report', self::changed(self::START_REPORT, [
                'outcome' => 'refused',
                'code' => 'run_in_flight',
                'executedAttempt' => null,
                'run' => json_decode(self::RUN_OF_NO_DEFINITION, true, flags: JSON_THROW_ON_ERROR),
                'claimed' => true,
            ])],
            'the answer to an inspect that found none' => ['inspect-answer', '{"run": null, "claimed": false, "snapshotRead": true, "at": 1791453604}'],
            'the answer to an inspect when the store cannot be read' => ['inspect-answer', '{"run": null, "claimed": null, "snapshotRead": false, "at": 1791453604}'],
            'the 409 answer to an abandon' => ['inspect-answer', '{"code": "abandon_refused", "run": ' . self::RUN . ', "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'the answer to a ping' => ['ping-answer', '{"protocols": [1]}'],
            'the error body of a 401' => ['error', '{"code": "invalid_signature"}'],
            'the error body of a requested sync the deployment could not publish' => ['error', '{"code": "sync_refused"}'],
            'the error body of a 409' => ['error', '{"code": "stale_run", "run": null, "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'the answer of the platform to an announcement or a report it recorded' => ['recorded', '{"recorded": true}'],
            'the answer of the platform to a message it refuses for good' => ['platform-error', '{"code": "invalid_request"}'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'a sync that publishes an http endpoint' => ['sync', '{"protocols": [1], "sdk": "iq-php/1.0.0", "endpoint": "http://shop.example.com/iq", "definitions": []}'],
            'a sync that does not speak protocol 1' => ['sync', '{"protocols": [2], "sdk": "iq-php/1.0.0", "endpoint": "https://shop.example.com/iq", "definitions": []}'],
            'a sync answer without its stranded versions' => ['sync-answer', '{"protocol": 1, "modified": true, "versions": []}'],
            'a definition name with a space' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "Order Fulfilment", "workflowId": "order:123"}'],
            'a workflow ID that begins with two underscores' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "__iq:123"}'],
            'a workflow ID with a control character' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order\u0007123"}'],
            'an empty workflow ID' => ['inspect', '{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": ""}'],
            'another protocol' => ['ping', '{"protocol": 2, "verb": "ping"}'],
            'a requested sync of another protocol' => ['requested-sync', '{"protocol": 2, "verb": "sync"}'],
            'a start whose run ID carries no version' => ['start', '{"protocol": 1, "verb": "start", "invocationId": "inv_01JB2M", "definition": "nightly-reconciliation",'
                . ' "workflowId": "reconciliation:2026-10-07", "runId": "01JB2M8Q3W", "expiresAt": 1791368100}'],
            'a start without a use-by time' => ['start', '{"protocol": 1, "verb": "start", "invocationId": "inv_01JB2M", "definition": "nightly-reconciliation",'
                . ' "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W"}'],
            'a wake without its fence' => ['wake', '{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB7K4T9QW2", "definition": "order-fulfilment",'
                . ' "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12"}'],
            'an abandon without a run ID' => ['abandon', '{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:123"}'],
            'an announcement of an observe' => ['announcement', '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "observe", "origin": "platform",'
                . ' "definition": "order-fulfilment", "workflowId": "order:123",'
                . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1, "leaseSeconds": 600, "at": 1791453603}'],
            'an announcement of a wake without its fence' => ['announcement', '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
                . ' "definition": "order-fulfilment", "workflowId": "order:123",'
                . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": null, "leaseSeconds": 600, "at": 1791453603}'],
            'an announcement of a start with an attempt' => ['announcement', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start",'
                . ' "origin": "application", "definition": "order-fulfilment", "workflowId": "order:123",'
                . ' "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 3, "leaseSeconds": 600, "at": 1791453603}'],
            'a report without its snapshotRead' => ['report', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start", "origin": "platform",'
                . ' "definition": "nightly-reconciliation", "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W",'
                . ' "outcome": "completed", "code": null, "errorClass": null, "executedAttempt": 1, "run": null, "claimed": true, "at": 1791453604}'],
            'a refused report with an SDK code' => ['report', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start", "origin": "platform",'
                . ' "definition": "nightly-reconciliation", "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W",'
                . ' "outcome": "refused", "code": "expired", "errorClass": null, "executedAttempt": null, "run": null,'
                . ' "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a failed report without the class of its exception' => ['report', '{"protocol": 1, "invocationId": "inv_01JB2M", "verb": "start", "origin": "platform",'
                . ' "definition": "nightly-reconciliation", "workflowId": "reconciliation:2026-10-07", "runId": "1-01JB2M8Q3W",'
                . ' "outcome": "failed", "code": null, "errorClass": null, "executedAttempt": 1, "run": null,'
                . ' "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a report of a wake that says claimed' => ['report', '{"protocol": 1, "invocationId": "inv_01JB7K4T9QW2", "verb": "wake", "origin": "platform",'
                . ' "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",'
                . ' "outcome": "completed", "code": null, "errorClass": null, "executedAttempt": 2, "run": null,'
                . ' "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a report of a wake rejected with a code of a start' => ['report', self::changed(self::REPORT, [
                'outcome' => 'rejected',
                'code' => 'expired',
                'executedAttempt' => null,
            ])],
            'a report of a start rejected as wrong_definition' => ['report', self::changed(self::START_REPORT, [
                'outcome' => 'rejected',
                'code' => 'wrong_definition',
                'executedAttempt' => null,
            ])],
            'a report of an answer that was rejected' => ['report', self::changed(self::REPORT, [
                'verb' => 'answer',
                'outcome' => 'rejected',
                'code' => 'expired',
                'executedAttempt' => null,
            ])],
            'a report of a signal that was rejected' => ['report', self::changed(self::REPORT, [
                'verb' => 'signal',
                'outcome' => 'rejected',
                'code' => 'expired',
                'executedAttempt' => null,
            ])],
            'a report of a wake aborted as wrong_definition' => ['report', self::changed(self::REPORT, [
                'outcome' => 'aborted',
                'code' => 'wrong_definition',
                'executedAttempt' => null,
            ])],
            'a failed report that executed under no attempt' => ['report', self::changed(self::START_REPORT, [
                'outcome' => 'failed',
                'errorClass' => 'PDOException',
                'executedAttempt' => null,
            ])],
            'an aborted report that executed under an attempt' => ['report', self::changed(self::START_REPORT, [
                'outcome' => 'aborted',
                'code' => 'not_executed',
                'errorClass' => 'PDOException',
            ])],
            'a rejected report that executed under an attempt' => ['report', self::changed(self::START_REPORT, ['outcome' => 'rejected', 'code' => 'already_claimed'])],
            'an observed report that executed under an attempt' => ['report', self::changed(self::START_REPORT, ['outcome' => 'observed'])],
            'a report refused with a reason only an abandon can meet' => ['report', self::changed(self::REPORT, [
                'outcome' => 'refused',
                'code' => 'completed',
                'executedAttempt' => null,
            ])],
            'a report refused with a reason that cannot occur under the SDK' => ['report', self::changed(self::REPORT, [
                'outcome' => 'refused',
                'code' => 'no_run',
                'executedAttempt' => null,
            ])],
            'a failed report with the name PHP gives an anonymous class' => ['report', self::changed(self::START_REPORT, [
                'outcome' => 'failed',
                'errorClass' => "RuntimeException@anonymous\0/var/www/src/Charge.php:21$0",
            ])],
            'a run that is not suspended with a wait' => ['inspect-answer', '{"run": {"runId": "2-0192f3a8-7c1e", "definition": "order-fulfilment", "status": "failed", "attempt": 2,'
                . ' "leaseExpiresAt": null, "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791457200}},'
                . ' "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a suspended run without its wait' => ['inspect-answer', '{"run": {"runId": "2-0192f3a8-7c1e", "definition": "order-fulfilment", "status": "suspended", "attempt": 2,'
                . ' "leaseExpiresAt": null}, "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a run with a status Neuron does not have' => ['inspect-answer', '{"run": {"runId": "2-0192f3a8-7c1e", "definition": "order-fulfilment", "status": "paused", "attempt": 2,'
                . ' "leaseExpiresAt": null}, "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a run without its definition' => ['inspect-answer', '{"run": {"runId": "2-0192f3a8-7c1e", "status": "failed", "attempt": 2, "leaseExpiresAt": null},'
                . ' "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a run whose definition is not a definition name' => ['inspect-answer', '{"run": {"runId": "2-0192f3a8-7c1e", "definition": "Order Fulfilment", "status": "failed", "attempt": 2,'
                . ' "leaseExpiresAt": null}, "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'a report whose run names no definition' => ['report', self::changed(self::REPORT, [
                'run' => ['runId' => '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12', 'status' => 'failed', 'attempt' => 2, 'leaseExpiresAt' => null],
            ])],
            'a ping answer that does not speak protocol 1' => ['ping-answer', '{"protocols": []}'],
            'an error body with a code the endpoint never answers' => ['error', '{"code": "report_undelivered"}'],
            'an error body without a code' => ['error', '{"message": "no"}'],
            'a 409 body without its run' => ['error', '{"code": "stale_run"}'],
            'a 409 body with a reason that cannot occur under the SDK' => ['error', '{"code": "not_completed", "run": null, "claimed": true, "snapshotRead": true, "at": 1791453604}'],
            'an answer of the platform that recorded nothing' => ['recorded', '{"recorded": false}'],
            'a platform error with a code of the endpoint' => ['platform-error', '{"code": "invalid_signature"}'],
        ];
    }

    /**
     * @param array<string, mixed> $fields the fields that differ
     */
    protected static function changed(string $body, array $fields): string
    {
        return json_encode($fields + json_decode($body, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
    }
}
