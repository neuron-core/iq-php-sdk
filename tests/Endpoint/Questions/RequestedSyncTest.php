<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use Closure;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Runtime\Signature;
use NeuronCore\Cloud\Tests\Stub\Units\RecordingPersistence;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function count;
use function sys_get_temp_dir;
use function time;

/**
 * The platform asks the endpoint for a sync, and takes the answer in as the
 * sync a deploy posts: the answer is that body, refused where sync() would
 * throw before it sent anything.
 */
class RequestedSyncTest extends EndpointTestCase
{
    protected const ENDPOINT = 'https://shop.example.com/neuron-cloud';

    public function test_a_requested_sync_is_answered_200_with_every_registered_version_its_lease_the_sdk_and_the_endpoint(): void
    {
        $this->deployment();

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame(200, $answer->statusCode);
        self::assertSame(
            [
                'protocols' => [1],
                'sdk' => 'neuron-cloud-php/1.0.0',
                'endpoint' => self::ENDPOINT,
                'definitions' => [
                    ['definition' => 'order-fulfilment', 'version' => 1, 'leaseSeconds' => 600],
                    ['definition' => 'order-fulfilment', 'version' => 2, 'leaseSeconds' => 900],
                    ['definition' => 'nightly-reconciliation', 'version' => 1, 'leaseSeconds' => 600],
                ],
            ],
            self::fieldsOf($answer),
        );
        ProtocolSchema::assertValid('sync', self::fieldsOf($answer));
    }

    public function test_the_answer_is_the_body_sync_posts(): void
    {
        $bench = $this->deployment();

        $answer = $this->deliver(['verb' => 'sync']);
        $bench->cloud->sync();

        self::assertSame($bench->platform->received()[0]->body, self::fieldsOf($answer));
    }

    public function test_a_requested_sync_puts_nothing_on_the_queue_and_sends_the_platform_nothing(): void
    {
        $bench = $this->deployment();

        $this->deliver(['verb' => 'sync']);

        self::assertSame([[], 0], [$this->work(), count($bench->platform->received())]);
    }

    public function test_a_requested_sync_asks_nothing_of_the_store(): void
    {
        $store = new RecordingPersistence();
        $this->deployment()->cloud->setPersistence(static fn (): PersistenceInterface => $store);

        $this->deliver(['verb' => 'sync']);

        self::assertSame([], $store->operations());
    }

    public function test_a_requested_sync_takes_nothing_from_the_rest_of_its_body(): void
    {
        $this->deployment();

        $answer = $this->deliver([
            'verb' => 'sync',
            'endpoint' => 'https://elsewhere.example.com/neuron-cloud',
            'definitions' => [],
            'definition' => 'nightly-reconciliation',
        ]);

        self::assertSame(200, $answer->statusCode);
        self::assertSame(self::ENDPOINT, self::fieldsOf($answer)['endpoint']);
        self::assertCount(3, self::fieldsOf($answer)['definitions']);
    }

    public function test_the_answer_follows_the_endpoint_url_set_when_the_platform_asks(): void
    {
        $this->deployment()->cloud->setEndpointUrl('https://shop.example.com/v2/neuron-cloud');

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame('https://shop.example.com/v2/neuron-cloud', self::fieldsOf($answer)['endpoint']);
    }

    public function test_a_requested_sync_signed_with_another_key_is_answered_401_and_shows_nothing_of_the_deployment(): void
    {
        $cloud = $this->deployment()->cloud;
        $body = '{"protocol":1,"verb":"sync"}';
        $timestamp = time();

        $answer = $cloud->handle('POST', [
            'webhook-id' => 'msg_1',
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (string) (new Signature('whsec_' . 'YW5vdGhlciBrZXkgb2YgYW5vdGhlciBwbGF0Zm9ybQ=='))->sign('msg_1', $timestamp, $body),
        ], $body);

        self::assertSame([401, '{"code":"invalid_signature"}'], [$answer->statusCode, $answer->body]);
    }

    /**
     * @param Closure(TestBench): void $breakIt
     */
    #[DataProvider('deploymentsSyncWouldRefuse')]
    public function test_a_deployment_sync_would_refuse_answers_409_sync_refused_and_says_nothing_else(Closure $breakIt): void
    {
        $bench = $this->deployment();
        $breakIt($bench);

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame([409, '{"code":"sync_refused"}'], [$answer->statusCode, $answer->body]);
        self::assertSame(['Content-Type' => 'application/json'], $answer->headers);
        self::assertSame([], $bench->platform->received());
    }

    public function test_a_deployment_that_set_no_endpoint_url_answers_409_sync_refused(): void
    {
        $this->deployment(endpointUrl: null);

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame([409, ['code' => 'sync_refused']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    public function test_a_deployment_that_registered_nothing_answers_409_sync_refused(): void
    {
        $this->benchOn(TestBench::SQLITE)->cloud->setEndpointUrl(self::ENDPOINT);

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame([409, ['code' => 'sync_refused']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    public function test_a_deployment_given_no_persistence_answers_409_sync_refused(): void
    {
        $platform = $this->benchOn(TestBench::SQLITE)->platform;
        $bare = (new NeuronCloud(TestBench::API_KEY, $platform->signingKey, TestBench::PLATFORM_URL, $platform))
            ->register('order-fulfilment', static fn (): Workflow => new Workflow())
            ->setEndpointUrl(self::ENDPOINT);

        $answer = $platform->deliver($bare, ['verb' => 'sync']);

        self::assertSame([409, ['code' => 'sync_refused']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    public function test_a_store_that_cannot_be_built_is_answered_500_internal_error_and_says_nothing_of_why(): void
    {
        $this->deployment()->cloud->setPersistence(static fn (): PersistenceInterface => throw new RuntimeException(self::STORE_FAILURE));

        $answer = $this->deliver(['verb' => 'sync']);

        self::assertSame([500, '{"code":"internal_error"}'], [$answer->statusCode, $answer->body]);
    }

    public function test_a_requested_sync_of_another_protocol_is_answered_400_unsupported_protocol(): void
    {
        $this->deployment();

        $answer = $this->deliver(['verb' => 'sync', 'protocol' => 2]);

        self::assertSame([400, ['code' => 'unsupported_protocol']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    /**
     * @return array<string, array{Closure(TestBench): void}> what the deployment lacks, set after it was built
     */
    public static function deploymentsSyncWouldRefuse(): array
    {
        return [
            'the endpoint URL is not https' => [static function (TestBench $bench): void {
                $bench->cloud->setEndpointUrl('http://shop.example.com/neuron-cloud');
            }],
            'the store is a file store' => [static function (TestBench $bench): void {
                $bench->cloud->setPersistence(static fn (): PersistenceInterface => new FilePersistence(sys_get_temp_dir()));
            }],
            'the store is an in-memory store' => [static function (TestBench $bench): void {
                $bench->cloud->setPersistence(static fn (): PersistenceInterface => new InMemoryPersistence());
            }],
            'a lease is under 60 seconds' => [static function (TestBench $bench): void {
                $bench->cloud->register('nightly-reconciliation', static fn (): Workflow => new Workflow(), leaseTimeout: 59);
            }],
        ];
    }

    /**
     * A deployment with two versions of one definition and one of another, on a store every process shares.
     *
     * @param string|null $endpointUrl what setEndpointUrl() is given; null: it is not called
     */
    protected function deployment(?string $endpointUrl = self::ENDPOINT): TestBench
    {
        $bench = $this->benchOn(TestBench::SQLITE);

        $bench->cloud
            ->register('order-fulfilment', static fn (): Workflow => new Workflow())
            ->register('order-fulfilment', static fn (): Workflow => new Workflow(), version: 2, leaseTimeout: 900)
            ->register('nightly-reconciliation', static fn (): Workflow => new Workflow());

        if ($endpointUrl !== null) {
            $bench->cloud->setEndpointUrl($endpointUrl);
        }

        return $bench;
    }
}
