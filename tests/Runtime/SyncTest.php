<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runtime;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Runtime\Definition;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Runtime\Registry;
use NeuronCore\Cloud\Runtime\Sync;
use NeuronCore\Cloud\SyncResult;
use NeuronCore\Cloud\Tests\Stub\Units\RecordingPersistence;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function json_encode;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

class SyncTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const ENDPOINT = 'https://shop.example.com/neuron-cloud.php';

    public function test_the_list_it_publishes_is_every_registered_version_with_its_lease(): void
    {
        $http = self::answering(['protocol' => 1, 'modified' => true, 'versions' => [], 'stranded' => []]);

        self::sync($http)->publish(self::ENDPOINT);

        self::assertSame(
            [
                'protocols' => [1],
                'sdk' => 'neuron-cloud-php/1.0.0',
                'endpoint' => self::ENDPOINT,
                'definitions' => [
                    ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 600],
                    ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 120],
                ],
            ],
            $http->received()[0]->body,
        );
    }

    public function test_the_manifest_is_the_body_it_publishes_and_building_it_sends_nothing(): void
    {
        $http = self::answering(['protocol' => 1, 'modified' => true, 'versions' => [], 'stranded' => []]);
        $sync = self::sync($http);

        $manifest = $sync->manifest(self::ENDPOINT);
        $sentWhileBuilt = count($http->received());
        $sync->publish(self::ENDPOINT);

        self::assertSame([0, $manifest], [$sentWhileBuilt, $http->received()[0]->body]);
    }

    #[DataProvider('storesOfOneProcess')]
    public function test_the_manifest_is_refused_for_a_store_whose_writes_do_not_hold_across_processes(PersistenceInterface $store): void
    {
        $http = self::answering(['protocol' => 1, 'modified' => true, 'versions' => [], 'stranded' => []]);

        $reason = self::reasonThrownBy(static fn (): array => self::sync($http, $store)->manifest(self::ENDPOINT));

        self::assertSame(Code::UnsuitablePersistence, $reason);
    }

    #[DataProvider('whetherAnythingChanged')]
    public function test_the_result_says_whether_the_sync_changed_anything(bool $modified): void
    {
        $http = self::answering(['protocol' => 1, 'modified' => $modified, 'versions' => [], 'stranded' => []]);

        self::assertSame($modified, self::sync($http)->publish(self::ENDPOINT)->modified);
    }

    public function test_an_older_version_with_no_open_run_is_retirable(): void
    {
        $result = self::resultOf(versions: [
            ['definition' => 'order-fulfilment', 'version' => 2, 'openRuns' => 14],
            ['definition' => 'order-fulfilment', 'version' => 1, 'openRuns' => 0],
        ]);

        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
    }

    public function test_an_older_version_with_open_runs_is_not_retirable(): void
    {
        $result = self::resultOf(versions: [
            ['definition' => 'order-fulfilment', 'version' => 2, 'openRuns' => 0],
            ['definition' => 'order-fulfilment', 'version' => 1, 'openRuns' => 3],
        ]);

        self::assertSame([], $result->retirable);
    }

    public function test_the_version_new_runs_start_on_is_never_retirable(): void
    {
        $result = self::resultOf(versions: [['definition' => 'order-fulfilment', 'version' => 2, 'openRuns' => 0]]);

        self::assertSame([], $result->retirable);
    }

    public function test_a_stranded_version_is_listed_with_the_runs_that_remain_on_it(): void
    {
        $result = self::resultOf(stranded: [
            ['definition' => 'trial', 'version' => 3, 'openRuns' => 2],
            ['definition' => 'trial', 'version' => 1, 'openRuns' => 5],
        ]);

        self::assertSame(['trial' => [3 => 2, 1 => 5]], $result->stranded);
    }

    #[DataProvider('storesOfOneProcess')]
    public function test_a_store_whose_writes_do_not_hold_across_processes_is_refused_before_anything_is_published(PersistenceInterface $store): void
    {
        $http = self::answering(['protocol' => 1, 'modified' => true, 'versions' => [], 'stranded' => []]);

        $reason = self::reasonThrownBy(static fn (): SyncResult => self::sync($http, $store)->publish(self::ENDPOINT));

        self::assertSame(Code::UnsuitablePersistence, $reason);
        self::assertSame([], $http->received());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function whetherAnythingChanged(): array
    {
        return ['something changed' => [true], 'nothing changed' => [false]];
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
     * What a sync of versions 2 and 1 of "order-fulfilment" returns for that answer of the platform.
     *
     * @param array<int, array<string, mixed>> $versions
     * @param array<int, array<string, mixed>> $stranded
     */
    protected static function resultOf(array $versions = [], array $stranded = []): SyncResult
    {
        $http = self::answering(['protocol' => 1, 'modified' => true, 'versions' => $versions, 'stranded' => $stranded]);

        return self::sync($http)->publish(self::ENDPOINT);
    }

    /**
     * @param array<string, mixed> $answer
     */
    protected static function answering(array $answer): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, json_encode($answer, JSON_THROW_ON_ERROR)));
    }

    /**
     * A sync of versions 2 and 1 of "order-fulfilment".
     *
     * @param PersistenceInterface|null $store null: a store every process shares
     */
    protected static function sync(ScriptedHttpClient $http, ?PersistenceInterface $store = null): Sync
    {
        $store ??= new RecordingPersistence();
        $registry = new Registry();
        $registry->register(new Definition('order-fulfilment', 2, static fn (): Workflow => new Workflow(), 600));
        $registry->register(new Definition('order-fulfilment', 1, static fn (): Workflow => new Workflow(), 120));

        return new Sync($registry, static fn (): PersistenceInterface => $store, new PlatformClient($http, 'https://platform.test', 'test-api-key'));
    }
}
