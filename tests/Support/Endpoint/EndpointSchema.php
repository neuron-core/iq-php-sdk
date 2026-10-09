<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Endpoint;

use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Assert;

use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Checks one exchange of the endpoint against the JSON Schemas of protocol 1:
 * the message it took, and the answer it gave.
 */
final class EndpointSchema
{
    /** The statuses of a message the endpoint refused as it is: a test sends such a message broken on purpose. */
    protected const REFUSED_AS_SENT = [400, 401];

    /**
     * @param array<string, mixed> $message what the platform sent
     */
    public static function assertRespected(array $message, HttpResponse $answer): void
    {
        $body = json_decode($answer->body, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($body, 'Every answer of the endpoint is a JSON object.');

        if (!in_array($answer->statusCode, self::REFUSED_AS_SENT, true)) {
            ProtocolSchema::assertValid((string) $message['verb'], $message);
        }

        if ($answer->statusCode === 202) {
            Assert::assertSame([], $body, 'A 202 promises nothing.');

            return;
        }

        ProtocolSchema::assertValid(self::schemaOf($message, $answer), $body);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function schemaOf(array $message, HttpResponse $answer): string
    {
        if ($answer->statusCode !== 200) {
            return 'error';
        }

        return $message['verb'] === 'ping' ? 'ping-answer' : 'inspect-answer';
    }
}
