<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Evaluation;

use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\EvaluatorDiscovery;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Datasets\RefundRequestsEvaluator;
use NeuronCore\Cloud\Tests\Stub\Evaluation\GreetingEvaluator;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use PHPUnit\Framework\TestCase;

use function array_map;
use function fopen;
use function json_encode;
use function ob_get_clean;
use function ob_start;

use const JSON_THROW_ON_ERROR;

/**
 * A dataset of the platform, as Neuron's evaluations use it: an evaluator
 * built with the service returns it from getDataset(), and Neuron's runner
 * loads it.
 */
class CloudDatasetTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const ITEMS = [
        ['input' => ['order' => 123], 'expected_output' => ['contains' => 'Order 123']],
        ['input' => ['order' => 456], 'expected_output' => ['contains' => 'declined']],
    ];

    public function test_neurons_runner_runs_the_evaluator_on_every_item_the_platform_keeps_in_order(): void
    {
        $evaluator = new RefundRequestsEvaluator(self::cloud(self::keeping(self::ITEMS)));

        $results = (new EvaluatorRunner())->run($evaluator);

        self::assertSame(
            [[self::ITEMS[0], true], [self::ITEMS[1], false]],
            array_map(static fn (EvaluatorResult $result): array => [$result->getInput(), $result->isPassed()], $results->getResults()),
        );
    }

    public function test_the_items_are_read_once_when_the_runner_loads_the_dataset_and_not_before(): void
    {
        $http = self::keeping(self::ITEMS);
        $evaluator = new RefundRequestsEvaluator(self::cloud($http));

        $evaluator->getDataset();
        $read = $http->received();
        (new EvaluatorRunner())->run($evaluator);

        self::assertSame([], $read);
        self::assertCount(1, $http->received());
    }

    public function test_an_empty_dataset_runs_nothing(): void
    {
        $results = (new EvaluatorRunner())->run(new RefundRequestsEvaluator(self::cloud(self::keeping([]))));

        self::assertSame(0, $results->getTotalCount());
    }

    public function test_a_dataset_the_platform_does_not_give_stops_the_evaluator_before_any_item_runs(): void
    {
        $evaluator = new RefundRequestsEvaluator(self::cloud(new ScriptedHttpClient(new HttpResponse(401, '{"code":"unauthorized"}'))));

        $refused = self::thrownBy(static fn (): EvaluationResults => (new EvaluatorRunner())->run($evaluator));

        self::assertSame(Code::Unauthorized, $refused?->reason);
    }

    /**
     * Neuron's command builds every evaluator with its resolver, here the container of an application that holds the
     * service: one whose dataset cannot be read is reported as an evaluator that could not run, and the others run.
     */
    public function test_neurons_command_reports_an_evaluator_whose_dataset_cannot_be_read_and_runs_the_others(): void
    {
        $cloud = self::cloud(new ScriptedHttpClient(new HttpResponse(404, '{"message":"Not found."}')));
        $output = self::recordingOutput();
        $command = new EvaluationCommand(
            self::configListing($output),
            self::discovering([RefundRequestsEvaluator::class, GreetingEvaluator::class]),
            resolver: static fn (string $class): object => $class === RefundRequestsEvaluator::class ? new $class($cloud) : new $class(),
        );
        $command->setErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $exitCode = $command->run(['neuron', 'evaluators']);
        ob_get_clean();

        [$refunds, $greetings] = $output->report?->getEvaluatorReports() ?? [];
        self::assertSame(1, $exitCode);
        self::assertStringStartsWith(CloudException::class . ': The platform answered 404 to /v1/datasets/refund-requests.', (string) $refunds?->getError());
        self::assertSame(0, $refunds?->getResults()->getTotalCount());
        self::assertSame([null, 2], [$greetings?->getError(), $greetings?->getResults()->getTotalCount()]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected static function keeping(array $items): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, json_encode(
            ['slug' => RefundRequestsEvaluator::DATASET, 'name' => 'Refund requests', 'description' => null, 'items' => $items],
            JSON_THROW_ON_ERROR,
        )));
    }

    protected static function cloud(ScriptedHttpClient $http): NeuronCloud
    {
        return new NeuronCloud('test-api-key', platformUrl: 'https://platform.test', httpClient: $http);
    }

    /**
     * @return EvaluationOutputInterface&object{report: ?EvaluationReport}
     */
    protected static function recordingOutput(): EvaluationOutputInterface
    {
        return new class () implements EvaluationOutputInterface {
            public ?EvaluationReport $report = null;

            public function output(EvaluationReport $report): void
            {
                $this->report = $report;
            }
        };
    }

    /**
     * An evaluation.php that lists one output.
     */
    protected static function configListing(EvaluationOutputInterface $output): ConfigLoader
    {
        return new class ($output) extends ConfigLoader {
            public function __construct(protected EvaluationOutputInterface $output)
            {
            }

            public function load(): array
            {
                return ['output' => [$this->output]];
            }
        };
    }

    /**
     * @param array<int, class-string> $evaluators
     */
    protected static function discovering(array $evaluators): EvaluatorDiscovery
    {
        return new class ($evaluators) extends EvaluatorDiscovery {
            /**
             * @param array<int, class-string> $evaluators
             */
            public function __construct(protected array $evaluators)
            {
            }

            public function discover(string $path): array
            {
                return $this->evaluators;
            }
        };
    }
}
