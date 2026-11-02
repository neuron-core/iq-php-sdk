<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Evaluation;

use DateTimeImmutable;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Evaluation\CloudOutput;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Tests\Stub\Evaluation\GreetingEvaluator;
use NeuronCore\Cloud\Tests\Stub\Evaluation\ProfileEvaluator;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CapturesErrorLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_diff_key;
use function array_keys;
use function array_map;
use function substr_count;

/**
 * The output that sends the results of Neuron's evaluations to the platform:
 * one message for each evaluator of the run.
 */
class CloudOutputTest extends TestCase
{
    use CapturesErrorLog;

    protected const PLATFORM_URL = 'https://platform.test';

    protected const API_KEY = 'test-api-key';

    protected const STARTED_AT = '2026-10-11T10:00:00.123456+00:00';

    protected const FINISHED_AT = '2026-10-11T10:00:02.500000+00:00';

    public function test_every_evaluator_of_a_run_is_posted_on_its_own_to_the_evaluations_path_with_the_api_key(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator(), new ProfileEvaluator()));

        self::assertSame(
            [
                [HttpMethod::POST, self::PLATFORM_URL . '/v1/evaluations', 'Bearer ' . self::API_KEY, GreetingEvaluator::class],
                [HttpMethod::POST, self::PLATFORM_URL . '/v1/evaluations', 'Bearer ' . self::API_KEY, ProfileEvaluator::class],
            ],
            array_map(
                static fn (HttpRequest $request): array => [$request->method, $request->uri, $request->headers['Authorization'] ?? null, $request->body['name'] ?? null],
                $platform->received(),
            ),
        );
    }

    public function test_a_message_names_its_evaluator_its_namespace_the_environment_and_when_it_ran(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform, 'ci')->output(self::reportOf(new GreetingEvaluator()));

        $sent = self::sent($platform);
        self::assertSame(
            [GreetingEvaluator::class, 'support', 'ci', null, self::STARTED_AT, self::FINISHED_AT],
            [$sent['name'], $sent['namespace'], $sent['environment'], $sent['error'], $sent['started_at'], $sent['finished_at']],
        );
        self::assertSame(
            ['run_id', 'name', 'namespace', 'environment', 'error', 'started_at', 'finished_at', 'summary', 'results'],
            array_keys($sent),
        );
    }

    public function test_the_environment_is_null_unless_one_was_given(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator()));

        self::assertNull(self::sent($platform)['environment']);
    }

    public function test_each_message_has_a_run_id_of_its_own(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator(), new GreetingEvaluator()));

        [$first, $second] = array_map(static fn (HttpRequest $request): string => $request->body['run_id'], $platform->received());
        self::assertStringStartsWith('eval_', $first);
        self::assertNotSame($first, $second);
    }

    public function test_the_summary_counts_the_items_and_the_assertions_that_passed_and_failed(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator()));

        $summary = self::sent($platform)['summary'];
        self::assertSame(
            [2, 1, 1, 0.5, 2, 1, 1, 0.5, 0, true],
            [
                $summary['total'],
                $summary['passed'],
                $summary['failed'],
                $summary['success_rate'],
                $summary['total_assertions'],
                $summary['assertions_passed'],
                $summary['assertions_failed'],
                $summary['assertion_success_rate'],
                $summary['cached_runs'],
                $summary['has_failures'],
            ],
        );
        self::assertEqualsWithDelta(2.376544, $summary['total_execution_time'], 0.000001);
        self::assertSame(['StringContains'], array_keys($summary['score_statistics_by_label']));
    }

    public function test_every_item_is_sent_with_its_input_its_output_and_how_its_assertions_went(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator()));

        [$passed, $failed] = self::sent($platform)['results'];
        self::assertSame(
            [GreetingEvaluator::class, 0, true, ['name' => 'Ada', 'expected' => 'Ada'], 'Hello, Ada!', false, null, 1, 0, []],
            [
                $passed['evaluator_class'],
                $passed['index'],
                $passed['passed'],
                $passed['input'],
                $passed['output'],
                $passed['cached_run'],
                $passed['error'],
                $passed['assertions_passed'],
                $passed['assertions_failed'],
                $passed['failures'],
            ],
        );
        self::assertSame([1, false, 0, 1], [$failed['index'], $failed['passed'], $failed['assertions_passed'], $failed['assertions_failed']]);
        self::assertSame(
            [['label' => 'StringContains', 'value' => 0.0, 'passed' => false, 'assertion' => 'StringContains', 'message' => "Expected 'Hello, Bob!' to contain 'Zed'"]],
            array_map(static fn (array $score): array => array_diff_key($score, ['line' => true]), $failed['scores']),
        );
    }

    public function test_a_failed_assertion_is_sent_with_its_name_its_message_and_where_it_was_made(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new GreetingEvaluator()));

        [$failure] = self::sent($platform)['results'][1]['failures'];
        self::assertSame(
            [GreetingEvaluator::class, 'StringContains', "Expected 'Hello, Bob!' to contain 'Zed'"],
            [$failure['evaluator_class'], $failure['assertion'], $failure['message']],
        );
        self::assertSame(['evaluator_class', 'assertion', 'message', 'line', 'context'], array_keys($failure));
    }

    public function test_an_output_that_is_not_a_scalar_is_sent_as_the_json_of_it(): void
    {
        $platform = self::takingIn();

        self::cloudOutput($platform)->output(self::reportOf(new ProfileEvaluator()));

        self::assertSame('{"name":"Ada","languages":["en","it"]}', self::sent($platform)['results'][0]['output']);
    }

    public function test_an_evaluator_that_could_not_run_is_sent_with_its_error_and_no_result(): void
    {
        $platform = self::takingIn();
        $report = new EvaluationReport(
            [new EvaluatorReport(GreetingEvaluator::class, new EvaluationResults([]), self::at(self::STARTED_AT), self::at(self::FINISHED_AT), 'The agent could not be built.')],
            self::at(self::STARTED_AT),
            self::at(self::FINISHED_AT),
        );

        self::cloudOutput($platform)->output($report);

        $sent = self::sent($platform);
        self::assertSame(['The agent could not be built.', [], 0], [$sent['error'], $sent['results'], $sent['summary']['total']]);
        self::assertNull($sent['summary']['score_statistics']);
    }

    public function test_a_platform_that_refuses_an_evaluation_is_logged_and_the_next_evaluator_is_still_sent(): void
    {
        $platform = new ScriptedHttpClient(new HttpResponse(503, ''));
        $output = self::cloudOutput($platform);

        $logged = self::loggedBy(static fn () => $output->output(self::reportOf(new GreetingEvaluator(), new ProfileEvaluator())));

        self::assertCount(2, $platform->received());
        self::assertSame(2, substr_count($logged, '[NeuronCloudOutput] Failed to send evaluation: The platform answered 503 to /v1/evaluations.'));
    }

    public function test_a_platform_that_gives_no_answer_is_logged_with_what_the_client_threw(): void
    {
        $output = self::cloudOutput(new ScriptedHttpClient(new RuntimeException('Could not resolve host: platform.test')));

        $logged = self::loggedBy(static fn () => $output->output(self::reportOf(new GreetingEvaluator())));

        self::assertStringContainsString('The platform gave no answer to /v1/evaluations. Could not resolve host: platform.test', $logged);
    }

    /**
     * Neuron runs the outputs of evaluation.php one after the other: this one never keeps the next from running.
     */
    public function test_in_a_pipeline_of_outputs_a_platform_that_is_away_does_not_stop_the_output_that_follows(): void
    {
        $told = [];
        $local = new class ($told) implements EvaluationOutputInterface {
            /** @param string[] $told */
            public function __construct(protected array &$told)
            {
            }

            public function output(EvaluationReport $report): void
            {
                $this->told = array_column(
                    array_map(static fn (EvaluatorReport $evaluator): array => ['class' => $evaluator->getEvaluatorClass()], $report->getEvaluatorReports()),
                    'class',
                );
            }
        };
        $pipeline = new OutputPipeline([self::cloudOutput(new ScriptedHttpClient(new RuntimeException('Connection refused'))), $local]);

        self::loggedBy(static fn () => $pipeline->output(self::reportOf(new GreetingEvaluator())));

        self::assertSame([GreetingEvaluator::class], $told);
    }

    protected static function takingIn(): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, '{}'));
    }

    protected static function cloudOutput(ScriptedHttpClient $platform, ?string $environment = null): CloudOutput
    {
        return new CloudOutput(new PlatformClient($platform, self::PLATFORM_URL, self::API_KEY), $environment);
    }

    /**
     * The report of a run of these evaluators, as Neuron's evaluation command builds it.
     */
    protected static function reportOf(EvaluatorInterface ...$evaluators): EvaluationReport
    {
        return new EvaluationReport(
            array_map(static fn (EvaluatorInterface $evaluator): EvaluatorReport => new EvaluatorReport(
                $evaluator::class,
                (new EvaluatorRunner())->run($evaluator),
                self::at(self::STARTED_AT),
                self::at(self::FINISHED_AT),
                null,
                $evaluator->namespace(),
            ), $evaluators),
            self::at(self::STARTED_AT),
            self::at(self::FINISHED_AT),
        );
    }

    protected static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    /**
     * @return array<string, mixed> the body of the first message the platform received
     */
    protected static function sent(ScriptedHttpClient $platform): array
    {
        return (array) $platform->received()[0]->body;
    }
}
