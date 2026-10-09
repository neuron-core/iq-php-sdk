<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Sync;

use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\SyncTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function sys_get_temp_dir;

/**
 * What NeuronCloud::sync() refuses by itself, before anything reaches the
 * platform: a store that does not hold across processes, no store at all,
 * a list with no definition, an endpoint the platform would not call and a
 * lease it would not accept.
 */
class SyncRefusedBeforeItIsSentTest extends SyncTestCase
{
    #[DataProvider('storesOfOneProcess')]
    public function test_a_store_whose_writes_do_not_hold_across_processes_is_unsuitable_persistence(PersistenceInterface $store): void
    {
        $platform = self::platformAccepting();
        $cloud = self::shop($platform)->setPersistence(static fn (): PersistenceInterface => $store);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertSame(Code::UnsuitablePersistence, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    #[DataProvider('storesOfOneProcess')]
    public function test_the_refusal_of_a_store_names_its_class(PersistenceInterface $store): void
    {
        $cloud = self::shop(self::platformAccepting())->setPersistence(static fn (): PersistenceInterface => $store);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertStringContainsString($store::class, (string) $refusal?->getMessage());
    }

    public function test_sync_before_a_persistence_is_set_is_not_configured(): void
    {
        $platform = self::platformAccepting();
        $cloud = (new NeuronCloud(self::API_KEY, httpClient: $platform))->register('order-fulfilment', self::workflow(...));

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertSame(Code::NotConfigured, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    public function test_a_deployment_that_registered_nothing_is_an_invalid_request(): void
    {
        $platform = self::platformAccepting();

        $refusal = self::thrownBy(static fn (): mixed => self::deployment($platform)->sync(self::ENDPOINT));

        self::assertSame(Code::InvalidRequest, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    public function test_the_refusal_of_an_empty_list_says_what_it_would_do_and_its_likeliest_cause(): void
    {
        $refusal = self::thrownBy(static fn (): mixed => self::deployment(self::platformAccepting())->sync(self::ENDPOINT));

        self::assertStringContainsString('an empty list would remove every version the platform holds', (string) $refusal?->getMessage());
        self::assertStringContainsString('register()', (string) $refusal?->getMessage());
    }

    #[DataProvider('endpointsThePlatformWouldNotCall')]
    public function test_an_endpoint_that_is_not_https_is_an_invalid_request(string $endpoint): void
    {
        $platform = self::platformAccepting();

        $refusal = self::thrownBy(static fn (): mixed => self::shop($platform)->sync($endpoint));

        self::assertSame(Code::InvalidRequest, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    public function test_the_refusal_of_an_endpoint_names_the_url(): void
    {
        $refusal = self::thrownBy(static fn (): mixed => self::shop(self::platformAccepting())->sync('http://shop.example.com/neuron-cloud'));

        self::assertStringContainsString("'http://shop.example.com/neuron-cloud'", (string) $refusal?->getMessage());
    }

    public function test_the_refusal_of_an_endpoint_does_not_quote_the_user_and_the_password_of_the_url(): void
    {
        $cloud = self::shop(self::platformAccepting());

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync('http://deploy:S3CR3T@shop.example.com/neuron-cloud'));

        self::assertStringContainsString("'http://shop.example.com/neuron-cloud'", (string) $refusal?->getMessage());
        self::assertStringNotContainsString('S3CR3T', (string) $refusal?->getMessage());
        self::assertStringNotContainsString('deploy', (string) $refusal?->getMessage());
    }

    #[DataProvider('leasesThePlatformWouldNotAccept')]
    public function test_a_registered_lease_under_sixty_seconds_is_an_invalid_request(int $lease): void
    {
        $platform = self::platformAccepting();
        $cloud = self::shop($platform)->register('trial', self::workflow(...), leaseTimeout: $lease);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertSame(Code::InvalidRequest, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    public function test_the_refusal_of_a_lease_names_the_definition_and_the_lease(): void
    {
        $cloud = self::shop(self::platformAccepting())->register('trial', self::workflow(...), leaseTimeout: 45);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertInstanceOf(CloudException::class, $refusal);
        self::assertStringContainsString("'trial'", $refusal->getMessage());
        self::assertStringContainsString('45 seconds', $refusal->getMessage());
    }

    public function test_the_refusal_of_a_lease_names_the_version_that_carries_it(): void
    {
        $cloud = self::deployment(self::platformAccepting())
            ->register('order-fulfilment', self::workflow(...), version: 1, leaseTimeout: 600)
            ->register('order-fulfilment', self::workflow(...), version: 2, leaseTimeout: 30);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertStringContainsString("Version 2 of 'order-fulfilment'", (string) $refusal?->getMessage());
    }

    public function test_an_older_version_with_a_short_lease_is_refused_like_the_current_one(): void
    {
        $platform = self::platformAccepting();
        $cloud = self::deployment($platform)
            ->register('order-fulfilment', self::workflow(...), version: 1, leaseTimeout: 30)
            ->register('order-fulfilment', self::workflow(...), version: 2, leaseTimeout: 600);

        $refusal = self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        self::assertSame(Code::InvalidRequest, $refusal?->reason);
        self::assertSame([], $platform->received());
    }

    public function test_a_deployment_refused_for_its_lease_can_be_published_once_the_lease_is_registered_again(): void
    {
        $platform = self::platformAccepting();
        $cloud = self::deployment($platform)->register('order-fulfilment', self::workflow(...), leaseTimeout: 30);
        self::thrownBy(static fn (): mixed => $cloud->sync(self::ENDPOINT));

        $cloud->register('order-fulfilment', self::workflow(...), leaseTimeout: 60)->sync(self::ENDPOINT);

        self::assertCount(1, $platform->received());
    }

    /**
     * @return array<string, array{PersistenceInterface}>
     */
    public static function storesOfOneProcess(): array
    {
        return [
            'InMemoryPersistence' => [new InMemoryPersistence()],
            'FilePersistence' => [new FilePersistence(sys_get_temp_dir())],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpointsThePlatformWouldNotCall(): array
    {
        return [
            'http' => ['http://shop.example.com/neuron-cloud'],
            'no scheme' => ['shop.example.com/neuron-cloud'],
            'a scheme-relative URL' => ['//shop.example.com/neuron-cloud'],
            'another scheme' => ['ftp://shop.example.com/neuron-cloud'],
            'a path' => ['/neuron-cloud'],
            'nothing' => [''],
            'https in capitals' => ['HTTPS://shop.example.com/neuron-cloud'],
            'https after a space' => [' https://shop.example.com/neuron-cloud'],
            'https inside the URL only' => ['http://shop.example.com/?next=https://shop.example.com'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function leasesThePlatformWouldNotAccept(): array
    {
        return [
            'one second under' => [59],
            'the shortest register() takes' => [1],
        ];
    }
}
