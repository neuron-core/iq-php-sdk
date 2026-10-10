<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Assert;

use function array_filter;
use function array_map;
use function array_values;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function hash_hmac;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * What crossed the wire between the application and the platform simulator,
 * in both directions and in order, as the simulator logged it: each message
 * as it was sent, and the answer it got.
 */
final class WireLog
{
    /** Sent by the application, received by the platform. */
    public const SYNC = 'sync';

    public const ANNOUNCEMENT = 'announcement';

    public const REPORT = 'report';

    /** Sent by the platform to the endpoint of the application. */
    public const START = 'start';

    public const WAKE = 'wake';

    public const INSPECT = 'inspect';

    public const ABANDON = 'abandon';

    public const PING = 'ping';

    protected const OF_THE_APPLICATION = [self::SYNC, self::ANNOUNCEMENT, self::REPORT];

    /**
     * @param array<int, array<string, mixed>> $exchanges as Simulator\Wire::all() gives them
     */
    public function __construct(public readonly array $exchanges)
    {
    }

    /**
     * The messages of one kind, oldest first, each with the fields of its body, its headers, the status of
     * its answer, the fields of that answer and its body as it was sent.
     *
     * @param string $message one of the constants of this class
     * @param string|null $workflowId only the messages about this workflow ID
     * @return array<int, array{fields: array<string, mixed>, headers: array<string, string>, status: int, answer: array<string, mixed>, body: string}>
     */
    public function of(string $message, ?string $workflowId = null): array
    {
        $messages = [];

        foreach ($this->exchanges as $exchange) {
            $fields = (array) json_decode($exchange['body'], true);

            if ($exchange['message'] === $message && ($workflowId === null || ($fields['workflowId'] ?? null) === $workflowId)) {
                $messages[] = [
                    'fields' => $fields,
                    'headers' => $exchange['headers'],
                    'status' => $exchange['status'],
                    'answer' => (array) json_decode($exchange['answer'], true),
                    'body' => $exchange['body'],
                ];
            }
        }

        return $messages;
    }

    /**
     * @return array<string, int> how many messages of each kind the platform sent the endpoint
     */
    public function sentByThePlatform(): array
    {
        $sent = [];

        foreach ($this->exchanges as $exchange) {
            if (!in_array($exchange['message'], self::OF_THE_APPLICATION, true)) {
                $sent[$exchange['message']] = ($sent[$exchange['message']] ?? 0) + 1;
            }
        }

        return $sent;
    }

    /**
     * Every message either side sent, and every answer it got, must match the JSON Schema of its kind.
     */
    public function assertProtocolRespected(): void
    {
        Assert::assertNotSame([], $this->exchanges, 'Nothing crossed the wire.');

        foreach ($this->exchanges as $exchange) {
            ProtocolSchema::assertValid($exchange['message'], self::decoded($exchange['body']));
            self::assertAnswerRespectsTheProtocol($exchange);
        }
    }

    /**
     * The half of the protocol the platform answers for, when a test plays the application and scripts its
     * endpoint: every message the platform sent, and every answer it gave, must match its JSON Schema.
     */
    public function assertThePlatformRespectedTheProtocol(): void
    {
        foreach ($this->exchanges as $exchange) {
            if (in_array($exchange['message'], self::OF_THE_APPLICATION, true)) {
                self::assertAnswerRespectsTheProtocol($exchange);
            } else {
                ProtocolSchema::assertValid($exchange['message'], self::decoded($exchange['body']));
            }
        }
    }

    /**
     * Every message of the platform must carry a Standard Webhooks signature made with the signing key over
     * its ID, its timestamp and its body as sent. Computed here once more, apart from the SDK and the simulator.
     *
     * @param string $signingKey "whsec_" followed by the base64 of the key bytes
     */
    public function assertSignedWith(string $signingKey): void
    {
        $key = (string) base64_decode(substr($signingKey, strlen('whsec_')), true);
        $sent = array_values(array_filter(
            $this->exchanges,
            static fn (array $exchange): bool => !in_array($exchange['message'], self::OF_THE_APPLICATION, true),
        ));

        Assert::assertNotSame([], $sent, 'The platform sent nothing.');

        foreach ($sent as $exchange) {
            $headers = $exchange['headers'];
            $signed = "{$headers['webhook-id']}.{$headers['webhook-timestamp']}.{$exchange['body']}";

            Assert::assertSame(
                'v1,' . base64_encode(hash_hmac('sha256', $signed, $key, true)),
                $headers['webhook-signature'],
                "The {$exchange['message']} of the platform is not signed as appendix A says.",
            );
        }
    }

    /**
     * @return string[] every exchange as one string: what a test searches for something that must not have crossed
     */
    public function crossed(): array
    {
        return array_map(
            static fn (array $exchange): string => json_encode($exchange, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->exchanges,
        );
    }

    public function count(): int
    {
        return count($this->exchanges);
    }

    /**
     * @param array<string, mixed> $exchange
     */
    protected static function assertAnswerRespectsTheProtocol(array $exchange): void
    {
        ['message' => $message, 'status' => $status, 'answer' => $answer] = $exchange;
        $isOfTheApplication = in_array($message, self::OF_THE_APPLICATION, true);

        if ($status === 202) {
            Assert::assertSame('{}', $answer, "The 202 answer to a {$message} has an empty object as its body.");

            return;
        }

        if ($isOfTheApplication && $status >= 500) {
            // The platform recorded nothing and says nothing: any such answer counts as undelivered.
            return;
        }

        if ($status === 0) {
            // The endpoint gave the platform no answer at all: it was down, or took too long.
            return;
        }

        $schema = match (true) {
            $status !== 200 => $isOfTheApplication ? 'platform-error' : 'error',
            $message === self::SYNC => 'sync-answer',
            $isOfTheApplication => 'recorded',
            $message === self::PING => 'ping-answer',
            default => 'inspect-answer',
        };

        ProtocolSchema::assertValid($schema, self::decoded($answer));
    }

    /**
     * @return array<array-key, mixed>
     */
    protected static function decoded(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertTrue(is_array($decoded) && explode('{', $json, 2)[0] === '', "A message or an answer is not a JSON object: {$json}");

        return $decoded;
    }
}
