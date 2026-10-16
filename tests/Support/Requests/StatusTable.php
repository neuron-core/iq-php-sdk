<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Requests;

use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Assert;

use function array_keys;
use function in_array;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The table of statuses and codes of the wire protocol, as a rule every
 * answer of the endpoint follows: its status is in the table, its code is one
 * the table gives that status, and it says nothing else.
 */
final class StatusTable
{
    /** The codes of the refusals that carry a code and nothing more, by status. */
    protected const REFUSALS = [
        400 => ['invalid_request', 'unsupported_protocol'],
        401 => ['invalid_signature'],
        404 => ['unknown_definition', 'unknown_version'],
        405 => ['invalid_request'],
        500 => ['internal_error'],
        503 => ['dispatch_failed', 'unavailable'],
    ];

    /** The statuses of a request refused as it was sent: a test sends such a request broken on purpose. */
    protected const REFUSED_AS_SENT = [400, 401, 405];

    protected const SNAPSHOT_FIELDS = ['run', 'claimed', 'snapshotRead', 'at'];

    /** The schema of a message whose verb does not name it: sync.json is the body of the sync the SDK posts. */
    protected const MESSAGE_SCHEMAS = ['sync' => 'requested-sync'];

    public static function assertRespectedBy(Exchange $exchange): void
    {
        $status = $exchange->answer->statusCode;
        $fields = json_decode($exchange->answer->body, true, flags: JSON_THROW_ON_ERROR);
        $message = json_decode($exchange->body, true);

        Assert::assertIsArray($fields, 'Every answer of the endpoint is a JSON object.');
        self::assertHeadersOf($exchange);

        if ($status === 202) {
            Assert::assertSame('{}', $exchange->answer->body, 'A 202 promises nothing.');
        } elseif ($status === 200 && self::verbOf($message) === 'ping') {
            Assert::assertSame(['protocols' => [1]], $fields);
            ProtocolSchema::assertValid('ping-answer', $fields);
        } elseif ($status === 200 && self::verbOf($message) === 'sync') {
            ProtocolSchema::assertValid('sync', $fields);
        } elseif ($status === 200) {
            self::assertSnapshot(self::SNAPSHOT_FIELDS, $fields);
        } elseif ($status === 409 && self::verbOf($message) === 'sync') {
            Assert::assertSame(['code' => 'sync_refused'], $fields, 'A sync the deployment cannot publish is refused with its code and nothing else.');
            ProtocolSchema::assertValid('error', $fields);
        } elseif ($status === 409) {
            self::assertSnapshot(['code', ...self::SNAPSHOT_FIELDS], $fields);
        } elseif (isset(self::REFUSALS[$status])) {
            self::assertRefusal($status, $fields);
        } else {
            Assert::fail("The table of statuses of the wire protocol has no {$status}.");
        }

        if (!in_array($status, self::REFUSED_AS_SENT, true)) {
            Assert::assertIsArray($message, 'Only a JSON object is taken for a message.');
            $verb = (string) self::verbOf($message);
            ProtocolSchema::assertValid(self::MESSAGE_SCHEMAS[$verb] ?? $verb, $message);
        }
    }

    protected static function verbOf(mixed $message): mixed
    {
        return is_array($message) ? $message['verb'] ?? null : null;
    }

    /**
     * @param string[] $expected the names of the fields, in order
     * @param array<string, mixed> $fields
     */
    protected static function assertSnapshot(array $expected, array $fields): void
    {
        Assert::assertSame($expected, array_keys($fields));
        ProtocolSchema::assertValid(isset($fields['code']) ? 'error' : 'inspect-answer', $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected static function assertRefusal(int $status, array $fields): void
    {
        Assert::assertSame(['code'], array_keys($fields), 'A refusal says its code and nothing else.');
        Assert::assertContains($fields['code'], self::REFUSALS[$status], "The table gives a {$status} another code.");
        ProtocolSchema::assertValid('error', $fields);
    }

    protected static function assertHeadersOf(Exchange $exchange): void
    {
        $headers = $exchange->answer->headers;
        $retryAfter = $headers['Retry-After'] ?? null;
        unset($headers['Retry-After']);

        Assert::assertSame(['Content-Type' => 'application/json'], $headers);

        if ($exchange->answer->statusCode === 503) {
            Assert::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', (string) $retryAfter, 'A 503 says in how many seconds to send again.');
        } else {
            Assert::assertNull($retryAfter, 'Only a 503 asks to be sent again.');
        }
    }
}
