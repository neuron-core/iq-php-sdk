<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronCore\Cloud\Runtime\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class FormatTest extends TestCase
{
    #[DataProvider('definitions')]
    public function test_a_definition_is_a_short_lower_case_name(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Format::isDefinition($value));
    }

    #[DataProvider('workflowIds')]
    public function test_a_workflow_id_follows_the_rule_of_neuron(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Format::isWorkflowId($value));
    }

    #[DataProvider('runIds')]
    public function test_a_run_id_follows_the_rule_of_neuron(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Format::isRunId($value));
    }

    #[DataProvider('mintedRunIds')]
    public function test_a_minted_run_id_is_a_version_a_dash_and_a_unique_part(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Format::isMintedRunId($value));
    }

    #[DataProvider('invocationIds')]
    public function test_an_invocation_id_is_at_most_128_letters_digits_underscores_and_dashes(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Format::isInvocationId($value));
    }

    public function test_every_minted_run_id_is_a_run_id_of_neuron(): void
    {
        $longest = '999999-' . str_repeat('a', 120);

        self::assertTrue(Format::isMintedRunId($longest));
        self::assertTrue(Format::isRunId($longest));
    }

    public function test_the_version_of_a_minted_run_id_is_the_number_at_its_front(): void
    {
        self::assertSame(2, Format::mintedVersion('2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12'));
        self::assertSame(999999, Format::mintedVersion('999999-a'));
    }

    #[DataProvider('runIdsStartedOutsideTheSdk')]
    public function test_a_run_id_that_was_not_minted_carries_no_version(string $runId): void
    {
        self::assertNull(Format::mintedVersion($runId));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function definitions(): array
    {
        return [
            'a name with a dash' => ['order-fulfilment', true],
            'a name with a dot and an underscore' => ['billing.nightly_run', true],
            'a name that starts with a digit' => ['2fa', true],
            'a name of 64 characters' => [str_repeat('a', 64), true],
            'a name of 65 characters' => [str_repeat('a', 65), false],
            'an empty name' => ['', false],
            'a name with an upper-case letter' => ['Order', false],
            'a name with a space' => ['order fulfilment', false],
            'a name that starts with a dash' => ['-order', false],
            'a name followed by a line break' => ["order\n", false],
            'a number' => [42, false],
            'nothing' => [null, false],
        ];
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function workflowIds(): array
    {
        return [
            'a business key' => ['order:123', true],
            'one character' => ['a', true],
            'a single underscore in front' => ['_order', true],
            'two underscores further on' => ['order__123', true],
            '255 multibyte characters' => [str_repeat('é', 255), true],
            '256 characters' => [str_repeat('a', 256), false],
            'an empty ID' => ['', false],
            'two underscores in front' => ['__neuron_cloud:123', false],
            'a control character' => ["order\x07123", false],
            'a delete character' => ["order\x7F", false],
            'a trailing line break' => ["order:123\n", false],
            'bytes that are not UTF-8' => ["order\xFF", false],
            'a number' => [123, false],
            'nothing' => [null, false],
        ];
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function runIds(): array
    {
        return [
            'a minted run ID' => ['2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12', true],
            'a run ID of the application' => ['nightly_2026-10-07', true],
            '128 characters' => [str_repeat('a', 128), true],
            '129 characters' => [str_repeat('a', 129), false],
            'an empty ID' => ['', false],
            'a dash in front' => ['-abc', false],
            'a colon' => ['order:123', false],
            'a trailing line break' => ["abc\n", false],
            'a number' => [1, false],
            'nothing' => [null, false],
        ];
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function mintedRunIds(): array
    {
        return [
            'version 1' => ['1-01JB2M8Q3W', true],
            'the highest version' => ['999999-a', true],
            'a unique part of 120 characters' => ['1-' . str_repeat('a', 120), true],
            'a unique part of 121 characters' => ['1-' . str_repeat('a', 121), false],
            'a version of seven digits' => ['1000000-a', false],
            'version 0' => ['0-abc', false],
            'a version with a leading zero' => ['01-abc', false],
            'no version' => ['01JB2M8Q3W', false],
            'no unique part' => ['1-', false],
            'a unique part that starts with a dash' => ['1--abc', false],
            'a number' => [1, false],
            'nothing' => [null, false],
        ];
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function invocationIds(): array
    {
        return [
            'an ID of the platform' => ['inv_01JB7K4T9QW2', true],
            '128 characters' => [str_repeat('a', 128), true],
            '129 characters' => [str_repeat('a', 129), false],
            'an empty ID' => ['', false],
            'an underscore in front' => ['_inv', false],
            'a dot' => ['inv.1', false],
            'nothing' => [null, false],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function runIdsStartedOutsideTheSdk(): array
    {
        return [
            'a UUID' => ['0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12'],
            'a word' => ['nightly'],
            'a version with a leading zero' => ['01-abc'],
            'a version of seven digits' => ['1000000-abc'],
        ];
    }
}
