<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Sync;

use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\SyncResult;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use PHPUnit\Framework\Attributes\DataProvider;

use function sys_get_temp_dir;

/**
 * App Sync, the last step of a deploy, against the fake platform. A sync needs
 * a store every process shares: these tests run on SQLite.
 */
class AppSyncTest extends IQTestCase
{
    use CatchesIQExceptions;

    protected const ENDPOINT = 'https://shop.example.com/iq.php';

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    public function test_sync_publishes_every_registered_version_with_its_lease_the_sdk_and_the_endpoint(): void
    {
        $bench = $this->deployment();

        $bench->iq->sync(self::ENDPOINT);

        self::assertSame(
            [
                'protocols' => [1],
                'sdk' => 'iq-php/1.0.0',
                'endpoint' => self::ENDPOINT,
                'definitions' => [
                    ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 600],
                    ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 900],
                    ['definition' => 'nightly-reconciliation', 'version' => 1, 'leaseSeconds' => 600],
                ],
            ],
            $this->published()->body,
        );
        $bench->platform->assertSynced();
    }

    public function test_sync_posts_to_the_sync_path_with_the_api_key_and_fifteen_seconds(): void
    {
        $this->deployment()->iq->sync(self::ENDPOINT);

        $request = $this->published();
        self::assertSame([HttpMethod::POST, TestBench::PLATFORM_URL . '/v1/sync'], [$request->method, $request->uri]);
        self::assertSame('Bearer ' . TestBench::API_KEY, $request->headers['Authorization']);
        self::assertSame('iq-php/1.0.0', $request->headers['User-Agent']);
        self::assertSame(15.0, $request->timeout);
    }

    public function test_sync_says_whether_the_platform_changed_anything(): void
    {
        $bench = $this->deployment();

        $first = $bench->iq->sync(self::ENDPOINT);
        $again = $bench->iq->sync(self::ENDPOINT);

        self::assertSame([true, false], [$first->modified, $again->modified]);
    }

    public function test_sync_lists_the_older_versions_no_run_is_open_on_as_retirable(): void
    {
        $result = $this->deployment()->iq->sync(self::ENDPOINT);

        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
        self::assertSame([], $result->stranded);
    }

    public function test_sync_refuses_an_in_memory_store_before_it_publishes_anything(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->iq->register('order-fulfilment', static fn (): Workflow => new Workflow());

        $reason = self::reasonThrownBy(static fn (): SyncResult => $bench->iq->sync(self::ENDPOINT));

        self::assertSame(Code::UnsuitablePersistence, $reason);
        self::assertSame([], $bench->platform->received());
    }

    public function test_sync_refuses_a_file_store_before_it_publishes_anything(): void
    {
        $bench = $this->deployment();
        $bench->iq->setPersistence(static fn (): PersistenceInterface => new FilePersistence(sys_get_temp_dir()));

        $reason = self::reasonThrownBy(static fn (): SyncResult => $bench->iq->sync(self::ENDPOINT));

        self::assertSame(Code::UnsuitablePersistence, $reason);
        self::assertSame([], $bench->platform->received());
    }

    public function test_sync_before_a_persistence_is_set_is_refused_as_not_configured(): void
    {
        $platform = $this->benchOn(TestBench::IN_MEMORY)->platform;
        $iq = new IQ(TestBench::API_KEY, httpClient: $platform);

        $reason = self::reasonThrownBy(static fn (): SyncResult => $iq->sync(self::ENDPOINT));

        self::assertSame(Code::NotConfigured, $reason);
        self::assertSame([], $platform->received());
    }

    public function test_sync_given_no_url_publishes_the_one_set_with_set_endpoint_url(): void
    {
        $bench = $this->deployment();
        $bench->iq->setEndpointUrl(self::ENDPOINT);

        $bench->iq->sync();

        self::assertSame(self::ENDPOINT, $this->published()->body['endpoint'] ?? null);
    }

    public function test_a_url_given_to_sync_is_published_instead_of_the_one_set(): void
    {
        $bench = $this->deployment();
        $bench->iq->setEndpointUrl('https://old.example.com/iq');

        $bench->iq->sync(self::ENDPOINT);

        self::assertSame(self::ENDPOINT, $this->published()->body['endpoint'] ?? null);
    }

    public function test_sync_given_no_url_while_none_is_set_is_refused_as_not_configured_before_anything_is_published(): void
    {
        $bench = $this->deployment();

        $reason = self::reasonThrownBy(static fn (): SyncResult => $bench->iq->sync());

        self::assertSame(Code::NotConfigured, $reason);
        self::assertSame([], $bench->platform->received());
    }

    public function test_an_endpoint_url_the_platform_cannot_call_is_taken_when_it_is_set_and_refused_when_sync_uses_it(): void
    {
        $bench = $this->deployment();
        $bench->iq->setEndpointUrl('http://shop.example.com/iq.php');

        $reason = self::reasonThrownBy(static fn (): SyncResult => $bench->iq->sync());

        self::assertSame(Code::InvalidRequest, $reason);
        self::assertSame([], $bench->platform->received());
    }

    #[DataProvider('refusals')]
    public function test_a_sync_the_platform_refuses_throws_the_matching_code(int $status, Code $expected): void
    {
        $bench = $this->deployment();
        $bench->platform->failNext(1, $status);

        $reason = self::reasonThrownBy(static fn (): SyncResult => $bench->iq->sync(self::ENDPOINT));

        self::assertSame($expected, $reason);
    }

    /**
     * @return array<string, array{int, Code}> the status the platform answers, and the reason sync() throws
     */
    public static function refusals(): array
    {
        return [
            'a 422: the platform could not ping the endpoint' => [422, Code::EndpointUnverified],
            'a 401: the API key is wrong' => [401, Code::Unauthorized],
            'a 403: the API key was revoked' => [403, Code::Unauthorized],
            'a 400: the platform cannot read the sync' => [400, Code::InvalidRequest],
            'a 503: the platform is down' => [503, Code::ReportUndelivered],
        ];
    }

    /**
     * A deployment with two versions of one definition and one of another, on a store every process shares.
     */
    protected function deployment(): TestBench
    {
        $bench = $this->benchOn(TestBench::SQLITE);

        $bench->iq
            ->register('order-fulfilment', static fn (): Workflow => new Workflow())
            ->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 2, leaseTimeout: 900)
            ->register('nightly-reconciliation', static fn (): Workflow => new Workflow());

        return $bench;
    }

    protected function published(): HttpRequest
    {
        return $this->bench->platform->received()[0];
    }
}
