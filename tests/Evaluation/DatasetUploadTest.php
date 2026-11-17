<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Evaluation;

use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use NeuronCore\Cloud\Tests\Support\Units\LocalPlatform;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_map;
use function count;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * uploadDataset() and vendor/bin/neuron-cloud-dataset: the items of a dataset
 * of the application, sent to a dataset the platform keeps.
 */
class DatasetUploadTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const API_KEY = 'test-api-key';

    protected const PLATFORM_URL = 'https://platform.test';

    protected const ITEMS = [
        ['input' => 'Can I get a refund?', 'expected_output' => 'refund', 'metadata' => ['topic' => 'billing']],
        ['input' => ['order' => 123]],
    ];

    protected ?LocalPlatform $localPlatform = null;

    protected ?string $file = null;

    protected function tearDown(): void
    {
        $this->localPlatform?->stop();

        if ($this->file !== null) {
            unlink($this->file);
        }
    }

    public function test_the_items_are_posted_to_the_items_of_the_slug_with_the_api_key_and_the_name(): void
    {
        $http = self::answering(['slug' => 'refund-requests', 'created' => true, 'added' => 2, 'updated' => 0, 'unchanged' => 0]);

        $upload = self::cloud($http)->uploadDataset('refund-requests', new ArrayDataset(self::ITEMS), 'Refund requests');

        [$request] = $http->received();
        self::assertSame(
            [HttpMethod::POST, self::PLATFORM_URL . '/v1/datasets/refund-requests/items', 'Bearer ' . self::API_KEY],
            [$request->method, $request->uri, $request->headers['Authorization'] ?? null],
        );
        self::assertSame(['name' => 'Refund requests', 'items' => self::ITEMS], $request->body);
        self::assertSame('refund-requests: created, 2 added, 0 updated, 0 unchanged', $upload->summary());
    }

    public function test_a_dataset_longer_than_one_request_carries_goes_in_several_and_their_counts_add_up(): void
    {
        $http = self::answering(['slug' => 'refund-requests', 'created' => false, 'added' => 3, 'updated' => 2, 'unchanged' => 1]);
        $items = array_fill(0, PlatformClient::UPLOAD_ITEMS * 2 + 5, ['input' => 'A']);

        $upload = self::cloud($http)->uploadDataset('refund-requests', new ArrayDataset($items));

        self::assertSame(
            [PlatformClient::UPLOAD_ITEMS, PlatformClient::UPLOAD_ITEMS, 5],
            array_map(static fn (HttpRequest $request): int => count($request->body['items']), $http->received()),
        );
        self::assertSame([false, 9, 6, 3], [$upload->created, $upload->added, $upload->updated, $upload->unchanged]);
    }

    public function test_an_empty_dataset_is_sent_once_so_that_the_platform_creates_it(): void
    {
        $http = self::answering(['slug' => 'refund-requests', 'created' => true, 'added' => 0, 'updated' => 0, 'unchanged' => 0]);

        $upload = self::cloud($http)->uploadDataset('refund-requests', new ArrayDataset([]));

        self::assertCount(1, $http->received());
        self::assertSame([], $http->received()[0]->body['items']);
        self::assertTrue($upload->created);
    }

    public function test_items_the_platform_refuses_throw_invalid_request_with_what_it_said(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(422, (string) json_encode([
            'message' => 'Item 2 has the key "output": an item holds only "input", "expected_output" and "metadata".',
            'errors' => ['items' => ['Item 2 has the key "output".']],
        ])));

        $thrown = self::thrownBy(fn () => self::cloud($http)->uploadDataset('refund-requests', new ArrayDataset(self::ITEMS)));

        self::assertSame(Code::InvalidRequest, $thrown?->reason);
        self::assertSame(
            'The platform answered 422 to /v1/datasets/refund-requests/items. Item 2 has the key "output": an item holds only "input", "expected_output" and "metadata".',
            $thrown->getMessage(),
        );
    }

    public function test_an_answer_without_the_counts_is_not_taken_for_a_write(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{"recorded":true}'));

        self::assertSame(
            Code::ReportUndelivered,
            self::reasonThrownBy(fn () => self::cloud($http)->uploadDataset('refund-requests', new ArrayDataset(self::ITEMS))),
        );
    }

    public function test_the_slug_is_sent_as_one_segment_of_the_path(): void
    {
        $http = self::answering(['slug' => 'a/b', 'created' => true, 'added' => 0, 'updated' => 0, 'unchanged' => 0]);

        self::cloud($http)->uploadDataset('a/b', new ArrayDataset([]));

        self::assertSame(self::PLATFORM_URL . '/v1/datasets/a%2Fb/items', $http->received()[0]->uri);
    }

    #[RequiresPhpExtension('curl')]
    public function test_the_command_sends_a_json_file_and_prints_what_the_platform_did(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $this->file = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-dataset-');
        file_put_contents($this->file, (string) json_encode(self::ITEMS));

        [$exit, $printed] = $this->command(
            'NEURON_CLOUD_API_KEY=' . self::API_KEY . ' NEURON_CLOUD_PLATFORM_URL=' . escapeshellarg($platform->url()),
            ['refund-requests', $this->file, '--name=Refund requests'],
        );

        self::assertSame([0, 'refund-requests: created, 2 added, 0 updated, 0 unchanged'], [$exit, $printed]);
        [$request] = $platform->requests();
        self::assertSame(['POST', '/v1/datasets/refund-requests/items'], [$request['method'], $request['path']]);
        self::assertSame(
            ['name' => 'Refund requests', 'items' => self::ITEMS],
            json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_the_command_with_no_api_key_exits_2(): void
    {
        [$exit, $printed] = $this->command('env -u NEURON_CLOUD_API_KEY', ['refund-requests', __FILE__]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('NEURON_CLOUD_API_KEY', $printed);
    }

    public function test_the_command_without_a_slug_and_a_file_exits_2_with_its_usage(): void
    {
        [$exit, $printed] = $this->command('NEURON_CLOUD_API_KEY=' . self::API_KEY, ['refund-requests']);

        self::assertSame(2, $exit);
        self::assertStringStartsWith('Usage: neuron-cloud-dataset <slug> <file.json>', $printed);
    }

    public function test_the_command_with_a_file_that_is_not_there_exits_2(): void
    {
        [$exit, $printed] = $this->command('NEURON_CLOUD_API_KEY=' . self::API_KEY, ['refund-requests', '/no/such/dataset.json']);

        self::assertSame([2, 'Dataset file not found: /no/such/dataset.json'], [$exit, $printed]);
    }

    public function test_the_command_with_a_file_that_is_not_a_json_array_exits_2_and_sends_nothing(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $this->file = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-dataset-');
        file_put_contents($this->file, '{"input": "a"');

        [$exit, $printed] = $this->command(
            'NEURON_CLOUD_API_KEY=' . self::API_KEY . ' NEURON_CLOUD_PLATFORM_URL=' . escapeshellarg($platform->url()),
            ['refund-requests', $this->file],
        );

        self::assertSame([2, 'Invalid JSON in dataset file: Syntax error'], [$exit, $printed]);
        self::assertSame([], $platform->requests());
    }

    /**
     * @param array<string, mixed> $answer
     */
    protected static function answering(array $answer): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, (string) json_encode($answer)));
    }

    protected static function cloud(ScriptedHttpClient $http): NeuronCloud
    {
        return new NeuronCloud(self::API_KEY, platformUrl: self::PLATFORM_URL, httpClient: $http);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string} the exit code, and what the command printed
     */
    protected function command(string $environment, array $arguments): array
    {
        $exit = 0;
        $lines = [];
        $command = $environment . ' ' . PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../../bin/neuron-cloud-dataset')
            . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1';

        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }
}
