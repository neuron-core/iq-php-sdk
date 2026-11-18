<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

use function dirname;
use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Checks a message against its JSON Schema in resources/protocol/1.
 */
final class ProtocolSchema
{
    /** The schemas carry no $id: this prefix only gives their relative references a base. */
    protected const BASE_URI = 'https://iq.test/protocol/1/';

    /**
     * @param string $message the file name of the schema, without its extension: 'report', 'announcement', 'start'...
     * @param array<array-key, mixed> $body
     */
    public static function assertValid(string $message, array $body): void
    {
        Assert::assertSame(
            [],
            self::violations($message, $body),
            "The body does not match the '{$message}' schema of protocol 1: " . json_encode($body, JSON_PRETTY_PRINT),
        );
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<string, string[]> what breaks the schema, by the place in the body; empty when the body matches
     */
    public static function violations(string $message, array $body): array
    {
        $validator = new Validator();
        $validator->resolver()->registerPrefix(self::BASE_URI, self::directory());

        // The validator tells a JSON object from a JSON array by its PHP type.
        $data = json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
        $error = $validator->validate($data, self::BASE_URI . $message . '.json')->error();

        return $error instanceof ValidationError ? (new ErrorFormatter())->format($error) : [];
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/resources/protocol/1';
    }
}
