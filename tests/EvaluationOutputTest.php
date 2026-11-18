<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests;

use DateTimeImmutable;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Score;
use NeuronAI\Evaluation\Usage\ModelUsage;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronCore\IQ\Evaluation\IQOutput;
use NeuronCore\IQ\Evaluation\RunContext;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\Evaluation\GreetingEvaluator;
use NeuronCore\IQ\Tests\Stub\Evaluation\ProfileEvaluator;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\IQ\Tests\Support\Units\LocalPlatform;
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
use function putenv;
use function rmdir;
use function sort;
use function stream_get_contents;
use function substr;
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
        $output = (new IQ(self::API_KEY))->evaluationOutput();

        self::assertInstanceOf(IQOutput::class, $output);
        self::assertInstanceOf(EvaluationOutputInterface::class, $output);
    }

    public function test_the_output_posts_to_the_platform_url_of_the_service_with_its_api_key_through_its_http_client(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $iq = new IQ(self::API_KEY, platformUrl: 'https://eu.platform.test', httpClient: $http);

        $iq->evaluationOutput()->output(self::report());

        [$request] = $http->received();
        self::assertSame(
            ['https://eu.platform.test/v1/evaluations', 'Bearer ' . self::API_KEY, GreetingEvaluator::class, null],
            [$request->uri, $request->headers['Authorization'] ?? null, $request->body['name'] ?? null, $request->body['environment'] ?? null],
        );
    }

    public function test_the_environment_given_to_the_service_is_sent_with_every_evaluation(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput('ci')->output(self::report());

        self::assertSame('ci', $http->received()[0]->body['environment'] ?? null);
    }

    public function test_the_output_needs_no_persistence_no_dispatcher_and_no_registered_definition(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput()->output(self::report());

        self::assertCount(1, $http->received());
    }

    public function test_the_output_sends_what_each_step_of_an_item_took_and_consumed_with_the_platforms_provider_name(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $results = new EvaluationResults([new EvaluatorResult(
            GreetingEvaluator::class,
            0,
            true,
            ['name' => 'Ada'],
            'Hello',
            1.5,
            1,
            0,
            assertionScores: [new Score('quality', 0.9, true, 'Judge', 'Clear and polite.', 12)],
            runTime: 1.2,
            evaluationTime: 0.3,
            runUsage: [new ModelUsage(Gemini::class, 'gemini-2.5-pro', 2, 100, 20, 40, 0, 5)],
            judgeUsage: [new ModelUsage(Anthropic::class, 'claude-opus-5-5', 1, 30, 4)],
        )], ['reads cached outputs']);
        $at = new DateTimeImmutable();

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput()->output(
            new EvaluationReport([new EvaluatorReport(GreetingEvaluator::class, $results, $at, $at)], $at, $at),
        );

        $body = $http->received()[0]->body;
        $item = $body['results'][0];
        self::assertSame(['reads cached outputs'], $body['summary']['warnings']);
        self::assertSame([1.2, 0.3], [$item['run_time'], $item['evaluation_time']]);
        self::assertSame(
            [['provider' => 'gcp.gemini', 'model' => 'gemini-2.5-pro', 'calls' => 2, 'input_tokens' => 100, 'output_tokens' => 20, 'cached_input_tokens' => 40, 'cache_write_tokens' => 0, 'reasoning_tokens' => 5]],
            $item['run_usage'],
        );
        self::assertSame('anthropic', $item['judge_usage'][0]['provider']);
        self::assertSame([['label' => 'quality', 'value' => 0.9, 'passed' => true, 'assertion' => 'Judge', 'message' => 'Clear and polite.', 'line' => 12]], $item['scores']);
    }

    public function test_every_evaluator_of_a_report_is_sent_with_the_code_and_the_build_it_ran_on(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $context = new RunContext('github-7-1', 'feature', 'main', 'abc123', 'https://ci.test/7');

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput(context: $context)->output(self::reportOfTwo());

        foreach ($http->received() as $request) {
            self::assertSame(
                ['github-7-1', 'feature', 'main', 'abc123', 'https://ci.test/7'],
                [$request->body['suite_id'], $request->body['branch'], $request->body['base_branch'], $request->body['commit'], $request->body['ci_url']],
            );
        }
        self::assertCount(2, $http->received());
        self::assertSame(2, $http->received()[0]->body['suite_size']);
    }

    public function test_the_output_reads_the_build_from_the_environment_when_it_is_given_none(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        putenv('NEURON_EVALUATION_SUITE_ID=nightly-3');

        try {
            (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput()->output(self::report());
        } finally {
            putenv('NEURON_EVALUATION_SUITE_ID');
        }

        self::assertSame('nightly-3', $http->received()[0]->body['suite_id']);
    }

    public function test_evaluators_with_no_build_to_name_them_share_a_suite_of_their_own(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput(context: new RunContext())->output(self::reportOfTwo());

        [$first, $second] = $http->received();
        self::assertSame($first->body['suite_id'], $second->body['suite_id']);
        self::assertStringStartsWith('suite_', $first->body['suite_id']);
    }

    public function test_a_string_cut_in_the_middle_of_a_character_does_not_lose_the_report(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $at = new DateTimeImmutable();
        $cut = substr('caffè', 0, 5);
        $results = new EvaluationResults([new EvaluatorResult(GreetingEvaluator::class, 0, true, ['name' => $cut], $cut, 1.0, 0, 0)]);

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput()->output(
            new EvaluationReport([new EvaluatorReport(GreetingEvaluator::class, $results, $at, $at)], $at, $at),
        );

        self::assertSame("caff\u{FFFD}", $http->received()[0]->body['results'][0]['output']);
    }

    public function test_the_output_sends_each_rerun_of_an_item_with_what_it_consumed(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));
        $at = new DateTimeImmutable();
        $rerun = fn (bool $passed, ?string $error = null): EvaluatorResult => new EvaluatorResult(
            GreetingEvaluator::class,
            0,
            $passed,
            [],
            null,
            1.0,
            $passed ? 3 : 0,
            1,
            error: $error,
            runTime: 0.9,
            evaluationTime: 0.1,
            runUsage: [new ModelUsage(Anthropic::class, 'claude-opus-5-5', 1, 10, 2)],
            judgeUsage: [new ModelUsage(Gemini::class, 'gemini-2.5-pro', 1, 5, 1)],
        );
        $result = (new EvaluatorResult(GreetingEvaluator::class, 0, false, [], null, 1.0, 0, 0))->withReruns([$rerun(true), $rerun(false, 'It threw')]);

        (new IQ(self::API_KEY, httpClient: $http))->evaluationOutput()->output(
            new EvaluationReport([new EvaluatorReport(GreetingEvaluator::class, new EvaluationResults([$result]), $at, $at)], $at, $at),
        );

        $reruns = $http->received()[0]->body['results'][0]['reruns'];
        self::assertSame(
            [[true, null, 0.9, 0.1], [false, 'It threw', 0.9, 0.1]],
            array_map(static fn (array $attempt): array => [$attempt['passed'], $attempt['error'], $attempt['run_time'], $attempt['evaluation_time']], $reruns),
        );
        self::assertSame('anthropic', $reruns[0]['run_usage'][0]['provider']);
        self::assertSame('gcp.gemini', $reruns[0]['judge_usage'][0]['provider']);
        self::assertSame([3, 1], [$reruns[0]['assertions_passed'], $reruns[0]['assertions_failed']]);
    }

    /**
     * The way an application uses it: evaluation.php builds the service and lists its output, and Neuron's own
     * command runs the evaluators and gives every output the report.
     */
    #[RequiresPhpExtension('curl')]
    public function test_neurons_evaluation_command_sends_the_results_of_every_evaluator_through_an_evaluation_php_that_lists_the_output(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $this->project = sys_get_temp_dir() . '/' . uniqid('iq-evaluation-');
        mkdir($this->project);
        file_put_contents($this->project . '/evaluation.php', '<?php
            $iq = new NeuronCore\IQ\IQ(apiKey: ' . var_export(self::API_KEY, true) . ', platformUrl: ' . var_export($platform->url(), true) . ');

            return ["output" => [$iq->evaluationOutput("ci")]];
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
    protected static function reportOfTwo(): EvaluationReport
    {
        $at = new DateTimeImmutable('2026-10-11T10:00:00+00:00');
        $report = fn (string $class): EvaluatorReport => new EvaluatorReport($class, new EvaluationResults([]), $at, $at);

        return new EvaluationReport([$report(GreetingEvaluator::class), $report(ProfileEvaluator::class)], $at, $at);
    }

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
