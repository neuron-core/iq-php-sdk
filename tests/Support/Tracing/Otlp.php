<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Tracing;

use PHPUnit\Framework\Assert;

use function array_column;
use function array_key_first;
use function array_map;
use function implode;

/**
 * An OTLP export request as a test reads it: its spans in the order they
 * finished, each with its attributes as plain PHP values.
 */
final class Otlp
{
    /**
     * @param array<string, mixed> $export
     * @return list<array<string, mixed>>
     */
    public static function spans(array $export): array
    {
        $spans = [];

        foreach ($export['resourceSpans'] ?? [] as $resource) {
            foreach ($resource['scopeSpans'] ?? [] as $scope) {
                foreach ($scope['spans'] ?? [] as $span) {
                    $span['attributes'] = self::decoded($span['attributes'] ?? []);
                    $span['events'] = array_map(
                        static fn (array $event): array => ['attributes' => self::decoded($event['attributes'] ?? [])] + $event,
                        $span['events'] ?? [],
                    );
                    $spans[] = $span;
                }
            }
        }

        return $spans;
    }

    /**
     * The first span of that name.
     *
     * @param array<string, mixed> $export
     * @return array<string, mixed>
     */
    public static function span(array $export, string $name): array
    {
        foreach (self::spans($export) as $span) {
            if ($span['name'] === $name) {
                return $span;
            }
        }

        Assert::fail("The trace has no span '{$name}': it has " . implode(', ', self::names($export)) . '.');
    }

    /**
     * @param array<string, mixed> $export
     * @return list<string>
     */
    public static function names(array $export): array
    {
        return array_column(self::spans($export), 'name');
    }

    /**
     * @param array<string, mixed> $export
     * @return array<string, mixed> the attributes of the resource the spans belong to
     */
    public static function resource(array $export): array
    {
        return self::decoded($export['resourceSpans'][0]['resource']['attributes'] ?? []);
    }

    /**
     * @param array<int, array{key: string, value: array<string, mixed>}> $attributes
     * @return array<string, mixed>
     */
    public static function decoded(array $attributes): array
    {
        $decoded = [];

        foreach ($attributes as ['key' => $key, 'value' => $value]) {
            $decoded[$key] = self::value($value);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $any an OTLP AnyValue
     */
    protected static function value(array $any): mixed
    {
        $type = array_key_first($any);

        return match ($type) {
            'intValue' => (int) $any[$type],
            'arrayValue' => array_map(self::value(...), $any[$type]['values']),
            'kvlistValue' => self::decoded($any[$type]['values']),
            default => $any[$type],
        };
    }
}
