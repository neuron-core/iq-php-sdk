<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use JsonException;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schemas of protocol 1 and Format must draw the same line: every row of FormatTest is put to both.
 */
class FormatSchemaTest extends TestCase
{
    protected const INSPECT = ['protocol' => 1, 'verb' => 'inspect', 'definition' => 'order-fulfilment', 'workflowId' => 'order:123'];

    protected const START = self::INSPECT + ['verb' => 'start', 'invocationId' => 'inv_01JB2M', 'runId' => '1-01JB2M8Q3W', 'expiresAt' => 1791368100];

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('identifiers')]
    public function test_the_schema_accepts_an_identifier_exactly_when_format_does(string $schema, array $message, string $field, mixed $value, bool $expected): void
    {
        try {
            $accepted = ProtocolSchema::violations($schema, [$field => $value] + $message) === [];
        } catch (JsonException) {
            // Bytes that are not UTF-8 never reach a schema: no JSON body holds them.
            $accepted = false;
        }

        self::assertSame($expected, $accepted);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, mixed, bool}>
     */
    public static function identifiers(): iterable
    {
        $rows = [
            'definition' => ['inspect', self::INSPECT, 'definition', FormatTest::definitions()],
            'workflowId' => ['inspect', self::INSPECT, 'workflowId', FormatTest::workflowIds()],
            'runId' => ['inspect', self::INSPECT, 'runId', FormatTest::runIds()],
            'minted runId' => ['start', ['verb' => 'start'] + self::START, 'runId', FormatTest::mintedRunIds()],
            'invocationId' => ['start', ['verb' => 'start'] + self::START, 'invocationId', FormatTest::invocationIds()],
        ];

        foreach ($rows as $identifier => [$schema, $message, $field, $values]) {
            foreach ($values as $name => [$value, $expected]) {
                yield "{$identifier}: {$name}" => [$schema, $message, $field, $value, $expected];
            }
        }
    }
}
