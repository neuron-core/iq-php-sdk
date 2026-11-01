<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Evaluation\CloudDataset;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Datasets\RefundRequestsEvaluator;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\LocalPlatform;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function file_put_contents;
use function is_resource;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * dataset(): a dataset of the platform for Neuron's evaluators, read with the
 * API key, the platform URL and the HTTP client of the service it comes from.
 */
class EvaluationDatasetTest extends TestCase
{
    protected const API_KEY = 'local-api-key';

    protected const ITEMS = [
        ['dataset_item_id' => 11, 'input' => ['order' => 123], 'expected_output' => ['contains' => 'Order 123']],
        ['dataset_item_id' => 12, 'input' => ['order' => 456], 'expected_output' => ['contains' => 'Order 456']],
    ];

    protected LocalPlatform $localPlatform;

    protected string $project;

    protected function tearDown(): void
    {
        if (isset($this->localPlatform)) {
            $this->localPlatform->stop();
        }

        if (isset($this->project)) {
            unlink($this->project . '/evaluation.php');
            rmdir($this->project);
        }
    }

    public function test_the_service_gives_a_dataset_neurons_evaluators_accept(): void
    {
        $dataset = (new NeuronCloud(self::API_KEY))->dataset('refund-requests');

        self::assertInstanceOf(CloudDataset::class, $dataset);
        self::assertInstanceOf(DatasetInterface::class, $dataset);
    }

    public function test_the_dataset_is_read_from_the_platform_url_of_the_service_with_its_api_key_through_its_http_client(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{"items":[]}'));
        $cloud = new NeuronCloud(self::API_KEY, platformUrl: 'https://eu.platform.test', httpClient: $http);

        $cloud->dataset('refund-requests')->load();

        [$request] = $http->received();
        self::assertSame(
            [HttpMethod::GET, 'https://eu.platform.test/v1/datasets/refund-requests', 'Bearer ' . self::API_KEY],
            [$request->method, $request->uri, $request->headers['Authorization'] ?? null],
        );
    }

    public function test_the_dataset_needs_no_persistence_no_dispatcher_and_no_registered_definition(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{"items":[{"input":"a"}]}'));

        $items = (new NeuronCloud(self::API_KEY, httpClient: $http))->dataset('refund-requests')->load();

        self::assertSame([['input' => 'a']], $items);
    }

    /**
     * The way an application without a container uses it: evaluation.php builds the service and its resolver gives
     * it to the evaluator, and Neuron's own command runs that evaluator on the dataset over real HTTP. The results of
     * the run go back to the platform through the output of the same service.
     */
    #[RequiresPhpExtension('curl')]
    public function test_neurons_evaluation_command_runs_an_evaluator_given_the_service_by_evaluation_php_on_a_dataset_of_the_platform(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $platform->keepDataset(RefundRequestsEvaluator::DATASET, self::ITEMS);
        $this->project = sys_get_temp_dir() . '/' . uniqid('neuron-cloud-evaluation-');
        mkdir($this->project);
        file_put_contents($this->project . '/evaluation.php', '<?php
            use NeuronCore\Cloud\NeuronCloud;
            use NeuronCore\Cloud\Tests\Stub\Datasets\RefundRequestsEvaluator;

            $cloud = new NeuronCloud(apiKey: ' . var_export(self::API_KEY, true) . ', platformUrl: ' . var_export($platform->url(), true) . ');

            return [
                "output" => [$cloud->evaluationOutput("ci")],
                "resolver" => fn (string $class): object => $class === RefundRequestsEvaluator::class ? new $class($cloud) : new $class(),
            ];
        ');

        $printed = $this->evaluate(__DIR__ . '/Stub/Datasets');

        $requests = $platform->requests();
        self::assertSame(
            [['GET', '/v1/datasets/' . RefundRequestsEvaluator::DATASET], ['POST', '/v1/evaluations']],
            array_map(static fn (array $request): array => [$request['method'], $request['path']], $requests),
            $printed,
        );
        self::assertSame('Bearer ' . self::API_KEY, $requests[0]['headers']['Authorization'] ?? null);
        $evaluation = json_decode($requests[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [RefundRequestsEvaluator::class, null, self::ITEMS, [true, true]],
            [$evaluation['name'], $evaluation['error'], array_column($evaluation['results'], 'input'), array_column($evaluation['results'], 'passed')],
        );
    }

    /**
     * Run Neuron's evaluation command on a directory of evaluators, from the directory that holds evaluation.php.
     *
     * @return string what the command printed
     */
    protected function evaluate(string $evaluators): string
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../vendor/bin/neuron', 'evaluation', $evaluators],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
        );
        self::assertTrue(is_resource($process), 'The evaluation command could not be started.');

        $printed = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        return $printed;
    }
}
