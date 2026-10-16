<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use DateTimeImmutable;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Evaluation\CloudOutput;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Evaluation\GreetingEvaluator;
use NeuronCore\Cloud\Tests\Stub\Evaluation\ProfileEvaluator;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\LocalPlatform;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function array_unique;
use function file_put_contents;
use function is_resource;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function sort;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * evaluationOutput(): the output of Neuron's evaluations that shares the API
 * key, the platform URL and the HTTP client of the service it comes from.
 */
class EvaluationOutputTest extends TestCase
{
    protected const API_KEY = 'local-api-key';

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

    public function test_the_service_gives_an_output_neurons_evaluations_accept(): void
    {
        $output = (new NeuronCloud(self::API_KEY))->evaluationOutput();

        self::assertInstanceOf(CloudOutput::class, $output);
        self::assertInstanceOf(EvaluationOutputInterface::class, $output);
    }

    public function test_the_output_posts_to_the_platform_url_of_the_service_with_its_api_key_through_its_http_client(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $cloud = new NeuronCloud(self::API_KEY, platformUrl: 'https://eu.platform.test', httpClient: $http);

        $cloud->evaluationOutput()->output(self::report());

        [$request] = $http->received();
        self::assertSame(
            ['https://eu.platform.test/v1/evaluations', 'Bearer ' . self::API_KEY, GreetingEvaluator::class, null],
            [$request->uri, $request->headers['Authorization'] ?? null, $request->body['name'] ?? null, $request->body['environment'] ?? null],
        );
    }

    public function test_the_environment_given_to_the_service_is_sent_with_every_evaluation(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        (new NeuronCloud(self::API_KEY, httpClient: $http))->evaluationOutput('ci')->output(self::report());

        self::assertSame('ci', $http->received()[0]->body['environment'] ?? null);
    }

    public function test_the_output_needs_no_persistence_no_dispatcher_and_no_registered_definition(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        (new NeuronCloud(self::API_KEY, httpClient: $http))->evaluationOutput()->output(self::report());

        self::assertCount(1, $http->received());
    }

    /**
     * The way an application uses it: evaluation.php builds the service and lists its output, and Neuron's own
     * command runs the evaluators and gives every output the report.
     */
    #[RequiresPhpExtension('curl')]
    public function test_neurons_evaluation_command_sends_the_results_of_every_evaluator_through_an_evaluation_php_that_lists_the_output(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $this->project = sys_get_temp_dir() . '/' . uniqid('neuron-cloud-evaluation-');
        mkdir($this->project);
        file_put_contents($this->project . '/evaluation.php', '<?php
            $cloud = new NeuronCore\Cloud\NeuronCloud(apiKey: ' . var_export(self::API_KEY, true) . ', platformUrl: ' . var_export($platform->url(), true) . ');

            return ["output" => [$cloud->evaluationOutput("ci")]];
        ');

        $printed = $this->evaluate(__DIR__ . '/Stub/Evaluation');

        $requests = $platform->requests();
        $sent = array_map(static fn (array $request): array => json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR), $requests);
        $evaluators = array_column($sent, 'name');
        sort($evaluators);
        self::assertSame([GreetingEvaluator::class, ProfileEvaluator::class], $evaluators, $printed);
        self::assertSame(['/v1/evaluations'], array_unique(array_column($requests, 'path')));
        self::assertSame(['Bearer ' . self::API_KEY], array_unique(array_map(static fn (array $request): string => $request['headers']['Authorization'], $requests)));
        self::assertSame(['ci'], array_unique(array_column($sent, 'environment')));
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

    /**
     * The report of one evaluator, as Neuron's evaluation command builds it.
     */
    protected static function report(): EvaluationReport
    {
        $at = new DateTimeImmutable('2026-10-11T10:00:00+00:00');
        $evaluator = new GreetingEvaluator();

        return new EvaluationReport(
            [new EvaluatorReport($evaluator::class, (new EvaluatorRunner())->run($evaluator), $at, $at, null, $evaluator->namespace())],
            $at,
            $at,
        );
    }
}
