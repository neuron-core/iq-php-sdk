<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Sync;

use NeuronAI\HttpClient\HttpMethod;
use NeuronCore\Cloud\Tests\Support\EndpointFlows\SyncTestCase;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function array_map;

/**
 * What NeuronCloud::sync() sends the platform: appendix A, "Application to
 * platform" and "Authentication", and resources/protocol/1/sync.json.
 */
class SyncRequestTest extends SyncTestCase
{
    #[DataProvider('platformUrls')]
    public function test_sync_posts_to_the_sync_path_of_the_platform_url(string $platformUrl, string $expected): void
    {
        $platform = self::platformAccepting();

        self::deployment($platform, $platformUrl)->register('order-fulfilment', self::workflow(...))->sync(self::ENDPOINT);

        self::assertSame($expected, self::published($platform)->uri);
    }

    public function test_sync_is_a_post(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        self::assertSame(HttpMethod::POST, self::published($platform)->method);
    }

    public function test_sync_carries_the_api_key_as_a_bearer_token(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        self::assertSame('Bearer ' . self::API_KEY, self::published($platform)->headers['Authorization']);
    }

    public function test_sync_names_the_sdk_and_its_version_as_its_user_agent(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        self::assertMatchesRegularExpression('~^neuron-cloud-php/\d+\.\d+\.\d+$~D', self::published($platform)->headers['User-Agent']);
    }

    public function test_sync_gives_the_platform_fifteen_seconds(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        self::assertSame(15.0, self::published($platform)->timeout);
    }

    public function test_the_body_lists_the_protocols_the_sdk_speaks_in_place_of_a_protocol(): void
    {
        $body = self::bodyOfASync();

        self::assertSame([1], $body['protocols']);
        self::assertArrayNotHasKey('protocol', $body);
    }

    public function test_the_body_names_the_sdk_as_the_user_agent_does(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        $request = self::published($platform);
        self::assertSame($request->headers['User-Agent'], ((array) $request->body)['sdk']);
    }

    public function test_the_body_carries_the_endpoint_as_it_was_given(): void
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync('https://shop.example.com:8443/neuron-cloud.php?tenant=7');

        self::assertSame('https://shop.example.com:8443/neuron-cloud.php?tenant=7', ((array) self::published($platform)->body)['endpoint']);
    }

    public function test_the_body_has_the_four_fields_of_a_sync_and_no_other(): void
    {
        self::assertEqualsCanonicalizing(['protocols', 'sdk', 'endpoint', 'definitions'], array_keys(self::bodyOfASync()));
    }

    public function test_every_registered_version_of_every_definition_is_listed_with_its_lease(): void
    {
        $platform = self::platformAccepting();
        $cloud = self::deployment($platform)
            ->register('order-fulfilment', self::workflow(...), version: 1, leaseTimeout: 120)
            ->register('order-fulfilment', self::workflow(...), version: 2, leaseTimeout: 900)
            ->register('nightly-reconciliation', self::workflow(...), version: 7, leaseTimeout: 3600);

        $cloud->sync(self::ENDPOINT);

        self::assertSame(
            [
                ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 120],
                ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 900],
                ['definition' => 'nightly-reconciliation', 'version' => 7, 'leaseSeconds' => 3600],
            ],
            ((array) self::published($platform)->body)['definitions'],
        );
    }

    public function test_a_registration_is_listed_with_its_name_its_version_and_its_lease_and_nothing_else(): void
    {
        [$registration] = self::bodyOfASync()['definitions'];

        self::assertEqualsCanonicalizing(['definition', 'version', 'leaseSeconds'], array_keys($registration));
    }

    public function test_the_definitions_are_listed_name_by_name_and_version_by_version_in_the_order_they_were_first_registered(): void
    {
        $platform = self::platformAccepting();
        $cloud = self::deployment($platform)
            ->register('order-fulfilment', self::workflow(...), version: 3)
            ->register('nightly-reconciliation', self::workflow(...), version: 2)
            ->register('order-fulfilment', self::workflow(...), version: 1)
            ->register('a-trial', self::workflow(...), version: 1)
            ->register('nightly-reconciliation', self::workflow(...), version: 1);

        $cloud->sync(self::ENDPOINT);

        self::assertSame(
            ['order-fulfilment 3', 'order-fulfilment 1', 'nightly-reconciliation 2', 'nightly-reconciliation 1', 'a-trial 1'],
            array_map(
                static fn (array $registration): string => "{$registration['definition']} {$registration['version']}",
                ((array) self::published($platform)->body)['definitions'],
            ),
        );
    }

    public function test_a_version_registered_twice_is_listed_once_at_its_first_place_with_its_last_lease(): void
    {
        $platform = self::platformAccepting();
        $cloud = self::deployment($platform)
            ->register('order-fulfilment', self::workflow(...), version: 1, leaseTimeout: 120)
            ->register('order-fulfilment', self::workflow(...), version: 2)
            ->register('order-fulfilment', self::workflow(...), version: 1, leaseTimeout: 300);

        $cloud->sync(self::ENDPOINT);

        self::assertSame(
            [
                ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 300],
                ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 600],
            ],
            ((array) self::published($platform)->body)['definitions'],
        );
    }

    public function test_a_lease_of_sixty_seconds_is_the_shortest_that_is_published(): void
    {
        $platform = self::platformAccepting();

        self::deployment($platform)->register('order-fulfilment', self::workflow(...), leaseTimeout: 60)->sync(self::ENDPOINT);

        self::assertSame(60, ((array) self::published($platform)->body)['definitions'][0]['leaseSeconds']);
    }

    public function test_the_body_matches_the_schema_of_a_sync(): void
    {
        $body = self::bodyOfASync();

        self::assertSame([], ProtocolSchema::violations('sync', $body));
    }

    public function test_a_sync_is_sent_once_whatever_the_platform_answers(): void
    {
        $platform = self::platformAnswering(503, '');

        self::reasonThrownBy(static fn (): mixed => self::shop($platform)->sync(self::ENDPOINT));

        self::assertCount(1, $platform->received());
    }

    /**
     * @return array<string, array{string, string}> the platform URL the root object was given, and where a sync goes
     */
    public static function platformUrls(): array
    {
        return [
            'no trailing slash' => ['https://platform.test', 'https://platform.test/v1/sync'],
            'a trailing slash' => ['https://platform.test/', 'https://platform.test/v1/sync'],
            'a path' => ['https://platform.test/eu', 'https://platform.test/eu/v1/sync'],
            'a path and a trailing slash' => ['https://platform.test/eu/', 'https://platform.test/eu/v1/sync'],
        ];
    }

    /**
     * @return array<string, mixed> what a sync of the shop sends
     */
    protected static function bodyOfASync(): array
    {
        $platform = self::platformAccepting();

        self::shop($platform)->sync(self::ENDPOINT);

        return (array) self::published($platform)->body;
    }
}
