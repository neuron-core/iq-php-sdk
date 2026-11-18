<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use Opis\JsonSchema\Validator;

use function array_walk_recursive;
use function dirname;
use function is_array;
use function is_float;
use function json_decode;
use function ltrim;

/**
 * The JSON Schemas of protocol 1, as the platform checks what it receives
 * against them: a request of the application, or an answer of its endpoint.
 */
final class Schemas
{
    /** The schemas carry no $id: this prefix only gives their relative references a base. */
    protected const BASE_URI = 'https://iq.simulator/protocol/1/';

    protected Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
        $this->validator->resolver()->registerPrefix(self::BASE_URI, dirname(__DIR__, 3) . '/resources/protocol/1');
    }

    /**
     * @param string $schema the file name of the schema, without its extension
     * @return array<string, mixed>|null the fields of the body; null when the body is not a message of that schema
     */
    public function read(string $schema, string $body): ?array
    {
        $fields = json_decode($body, true);

        if (!is_array($fields) || (ltrim($body, " \t\n\r")[0] ?? '') !== '{' || self::holdsAFraction($fields)) {
            return null;
        }

        return $this->validator->validate(json_decode($body), self::BASE_URI . $schema . '.json')->isValid() ? $fields : null;
    }

    /**
     * An integer is written without a fraction or an exponent, which a JSON Schema cannot say, and protocol 1
     * carries no other number: 1.0 and 1e0 decode to a float.
     *
     * @param array<array-key, mixed> $fields
     */
    protected static function holdsAFraction(array $fields): bool
    {
        $found = false;
        array_walk_recursive($fields, static function (mixed $value) use (&$found): void {
            $found = $found || is_float($value);
        });

        return $found;
    }
}
