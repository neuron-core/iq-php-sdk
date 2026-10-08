<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\Registry;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class RegistryTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const NAME = 'order-fulfilment';

    #[DataProvider('namesTheProtocolCarries')]
    public function test_a_name_the_protocol_can_carry_is_registered(string $name): void
    {
        $registry = new Registry();
        $definition = self::definition(1, $name);

        $registry->register($definition);

        self::assertSame($definition, $registry->pinned($name, '1-abc'));
    }

    #[DataProvider('namesTheProtocolCannotCarry')]
    public function test_a_name_the_protocol_cannot_carry_is_refused(string $name): void
    {
        $registry = new Registry();

        $reason = self::reasonThrownBy(static function () use ($registry, $name): void {
            $registry->register(self::definition(1, $name));
        });

        self::assertSame(Code::InvalidRequest, $reason);
    }

    #[DataProvider('versionsARunIdCarries')]
    public function test_a_version_a_run_id_can_carry_is_registered(int $version): void
    {
        $registry = new Registry();
        $definition = self::definition($version);

        $registry->register($definition);

        self::assertSame($definition, $registry->pinned(self::NAME, "{$version}-abc"));
    }

    #[DataProvider('versionsARunIdCannotCarry')]
    public function test_a_version_a_run_id_cannot_carry_is_refused(int $version): void
    {
        $registry = new Registry();

        $reason = self::reasonThrownBy(static function () use ($registry, $version): void {
            $registry->register(self::definition($version));
        });

        self::assertSame(Code::InvalidRequest, $reason);
    }

    #[DataProvider('leasesUnderOneSecond')]
    public function test_a_lease_under_one_second_is_refused(int $leaseTimeout): void
    {
        $registry = new Registry();

        $reason = self::reasonThrownBy(static function () use ($registry, $leaseTimeout): void {
            $registry->register(self::definition(1, leaseTimeout: $leaseTimeout));
        });

        self::assertSame(Code::InvalidRequest, $reason);
    }

    public function test_a_lease_of_one_second_is_registered(): void
    {
        $registry = new Registry();
        $definition = self::definition(1, leaseTimeout: 1);

        $registry->register($definition);

        self::assertSame($definition, $registry->pinned(self::NAME, '1-abc'));
    }

    public function test_a_refused_definition_is_not_registered(): void
    {
        $registry = new Registry();
        self::thrownBy(static function () use ($registry): void {
            $registry->register(self::definition(0));
        });

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->pinned(self::NAME, 'abc'));

        self::assertSame(Code::UnknownDefinition, $reason);
    }

    #[DataProvider('mintedRunIds')]
    public function test_a_minted_run_id_pins_the_version_at_its_front(string $runId, int $version): void
    {
        $registry = self::registryOf(1, 2, 12, 999999);

        self::assertSame($version, $registry->pinned(self::NAME, $runId)->version);
    }

    #[DataProvider('runIdsTheSdkDidNotMint')]
    public function test_a_run_id_the_sdk_did_not_mint_counts_as_the_lowest_version(string $runId): void
    {
        $registry = self::registryOf(3, 2, 5);

        self::assertSame(2, $registry->pinned(self::NAME, $runId)->version);
    }

    public function test_a_minted_run_id_of_a_version_this_process_lacks_is_an_unknown_version(): void
    {
        $registry = self::registryOf(1, 3);

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->pinned(self::NAME, '2-abc'));

        self::assertSame(Code::UnknownVersion, $reason);
    }

    public function test_a_name_this_process_lacks_is_an_unknown_definition(): void
    {
        $registry = self::registryOf(1);

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->pinned('nightly-reconciliation', '1-abc'));

        self::assertSame(Code::UnknownDefinition, $reason);
    }

    public function test_the_versions_of_one_name_are_not_those_of_another(): void
    {
        $registry = self::registryOf(1);
        $registry->register(self::definition(2, 'nightly-reconciliation'));

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->pinned(self::NAME, '2-abc'));

        self::assertSame(Code::UnknownVersion, $reason);
    }

    public function test_registering_a_version_again_replaces_it(): void
    {
        $registry = self::registryOf(1, 2);
        $replacement = self::definition(1);

        $registry->register($replacement);

        self::assertSame($replacement, $registry->pinned(self::NAME, '1-abc'));
    }

    public function test_the_store_of_a_run_is_read_with_the_version_its_run_id_pins(): void
    {
        $registry = self::registryOf(1, 2, 3);

        self::assertSame(2, $registry->reader(self::NAME, '2-abc')->version);
    }

    public function test_the_store_of_a_version_this_process_lacks_is_read_with_the_current_version(): void
    {
        $registry = self::registryOf(2, 5, 3);

        self::assertSame(5, $registry->reader(self::NAME, '4-abc')->version);
    }

    public function test_the_store_of_a_run_the_sdk_did_not_mint_is_read_with_the_lowest_version(): void
    {
        $registry = self::registryOf(3, 2, 5);

        self::assertSame(2, $registry->reader(self::NAME, '0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12')->version);
    }

    public function test_no_store_is_read_for_a_name_this_process_lacks(): void
    {
        $registry = self::registryOf(1);

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->reader('nightly-reconciliation', '1-abc'));

        self::assertSame(Code::UnknownDefinition, $reason);
    }

    public function test_a_new_run_starts_on_the_highest_registered_version(): void
    {
        $registry = self::registryOf(2, 12, 3);

        self::assertSame(12, $registry->current(self::NAME)->version);
    }

    public function test_no_run_starts_for_a_name_this_process_lacks(): void
    {
        $registry = self::registryOf(1);

        $reason = self::reasonThrownBy(static fn (): Definition => $registry->current('nightly-reconciliation'));

        self::assertSame(Code::UnknownDefinition, $reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesTheProtocolCarries(): array
    {
        return [
            'one letter' => ['a'],
            'one digit' => ['7'],
            'hyphens' => ['order-fulfilment'],
            'dots and underscores' => ['billing.invoice_v2'],
            'a digit first' => ['9lives'],
            '64 characters' => [str_repeat('a', 64)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesTheProtocolCannotCarry(): array
    {
        return [
            'empty' => [''],
            'an uppercase letter' => ['Order'],
            'a space' => ['order fulfilment'],
            'a hyphen first' => ['-order'],
            'a dot first' => ['.order'],
            'an underscore first' => ['_order'],
            'a colon' => ['order:fulfilment'],
            'a slash' => ['order/fulfilment'],
            'a letter outside ASCII' => ['commandé'],
            'a line break at the end' => ["order\n"],
            '65 characters' => [str_repeat('a', 65)],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function versionsARunIdCarries(): array
    {
        return [
            'the lowest' => [1],
            'the highest, six digits' => [999999],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function versionsARunIdCannotCarry(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'seven digits' => [1000000],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function leasesUnderOneSecond(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function mintedRunIds(): array
    {
        return [
            'the example of the protocol' => ['2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12', 2],
            'the shortest' => ['1-a', 1],
            'two digits' => ['12-01JB2M8Q3W', 12],
            'six digits' => ['999999-01JB2M8Q3W', 999999],
            'underscores and hyphens after the first character' => ['2-a_b-c', 2],
            '120 characters after the version' => ['2-' . str_repeat('a', 120), 2],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function runIdsTheSdkDidNotMint(): array
    {
        return [
            'a UUID, as Neuron mints them' => ['0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12'],
            'a UUID made of digits' => ['12345678-1234-7234-8234-123456789012'],
            'no version' => ['abc'],
            'version zero' => ['0-abc'],
            'a leading zero' => ['02-abc'],
            'seven digits' => ['1234567-abc'],
            'nothing after the version' => ['2-'],
            'a hyphen right after the version' => ['2--abc'],
            'an underscore for the hyphen' => ['2_abc'],
            '121 characters after the version' => ['2-' . str_repeat('a', 121)],
            'a line break at the end' => ["2-abc\n"],
        ];
    }

    protected static function registryOf(int ...$versions): Registry
    {
        $registry = new Registry();
        foreach ($versions as $version) {
            $registry->register(self::definition($version));
        }

        return $registry;
    }

    protected static function definition(int $version, string $name = self::NAME, int $leaseTimeout = 600): Definition
    {
        return new Definition($name, $version, static fn (): Workflow => new Workflow(), $leaseTimeout);
    }
}
