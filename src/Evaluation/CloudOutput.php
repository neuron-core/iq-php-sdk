<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Evaluation;

use DateTimeImmutable;
use JsonException;
use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Score;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Tools\ToolCall;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Runtime\PlatformClient;

use function array_map;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function error_log;
use function uniqid;

use const JSON_THROW_ON_ERROR;

/**
 * Sends an evaluation run to Neuron Cloud.
 *
 * This is the Neuron Cloud SDK home for the evaluation-output channel: it turns every
 * evaluator report in an {@see EvaluationReport} into a separate payload, posted to
 * `{platformUrl}/v1/evaluations` with the API key and the HTTP client of the SDK.
 *
 * Build it from the SDK root, and hand the instance to neuron-ai's evaluation output
 * pipeline in `evaluation.php` (neuron-ai takes a class string or an instance):
 *
 *   'output' => [ConsoleOutput::class, $cloud->evaluationOutput()],
 *
 * Best-effort: a payload that fails to encode or upload is logged, never thrown, so
 * the other outputs of the pipeline and the exit code of the run are what they would be.
 */
class CloudOutput implements EvaluationOutputInterface
{
    /**
     * @internal built by {@see \NeuronCore\Cloud\NeuronCloud::evaluationOutput()}
     */
    public function __construct(
        protected readonly PlatformClient $platform,
        protected readonly ?string $environment = null,
    ) {
    }

    public function output(EvaluationReport $report): void
    {
        foreach ($report->getEvaluatorReports() as $evaluatorReport) {
            $this->outputReport($evaluatorReport);
        }
    }

    protected function outputReport(EvaluatorReport $report): void
    {
        $results = $report->getResults();
        $payload = [
            'run_id' => uniqid('eval_', true),
            'name' => $report->getEvaluatorClass(),
            'namespace' => $report->getNamespace(),
            'environment' => $this->environment,
            'error' => $report->getError(),
            'started_at' => $this->timestamp($report->getStartedAt()),
            'finished_at' => $this->timestamp($report->getFinishedAt()),
            'summary' => $this->summaryToArray($report),
            'results' => array_map(
                fn (EvaluatorResult $result): array => [
                    'evaluator_class' => $result->getEvaluatorClass(),
                    'index' => $result->getIndex(),
                    'passed' => $result->isPassed(),
                    'input' => $result->getInput(),
                    'output' => $this->formatOutput($result->getOutput()),
                    'execution_time' => $result->getExecutionTime(),
                    'cached_run' => $result->isCachedRun(),
                    'error' => $result->getError(),
                    'assertions_passed' => $result->getAssertionsPassed(),
                    'assertions_failed' => $result->getAssertionsFailed(),
                    'assertion_scores' => $result->getAssertionScores(),
                    'scores' => array_map(
                        static fn (Score $score): array => [
                            'label' => $score->label,
                            'value' => $score->value,
                            'passed' => $score->passed,
                        ],
                        $result->getScoreRecords(),
                    ),
                    'failures' => array_map(
                        fn (AssertionFailure $failure): array => $this->failureToArray($failure),
                        $result->getAssertionFailures(),
                    ),
                ],
                $results->getResults(),
            ),
        ];

        try {
            $this->platform->evaluation($payload);
        } catch (CloudException $e) {
            // The cause says why no answer came: a network error, or a value JSON cannot carry.
            error_log('[NeuronCloudOutput] Failed to send evaluation: ' . $e->getMessage() . ' ' . $e->getPrevious()?->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function summaryToArray(EvaluatorReport $report): array
    {
        $results = $report->getResults();
        $scores = $results->getAllAssertionScores();

        return [
            'total' => $results->getTotalCount(),
            'passed' => $results->getPassedCount(),
            'failed' => $results->getFailedCount(),
            'success_rate' => $results->getSuccessRate(),
            'total_execution_time' => $report->getDuration(),
            'average_execution_time' => $results->getAverageExecutionTime(),
            'total_assertions' => $results->getTotalAssertions(),
            'assertions_passed' => $results->getTotalAssertionsPassed(),
            'assertions_failed' => $results->getTotalAssertionsFailed(),
            'assertion_success_rate' => $results->getAssertionSuccessRate(),
            'cached_runs' => $results->getCachedRunCount(),
            'score_statistics' => $scores !== [] ? [
                'average_score' => $results->getAverageAssertionScore(),
                'min_score' => $results->getMinAssertionScore(),
                'max_score' => $results->getMaxAssertionScore(),
            ] : null,
            'score_statistics_by_label' => $results->getScoreStatisticsByLabel(),
            'has_failures' => $report->hasFailures(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function failureToArray(AssertionFailure $failure): array
    {
        return [
            'evaluator_class' => $failure->getEvaluatorClass(),
            'assertion' => $failure->getAssertionMethod(),
            'message' => $failure->getMessage(),
            'line' => $failure->getLineNumber(),
            'context' => $failure->getContext(),
        ];
    }

    protected function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.uP');
    }

    protected function formatOutput(mixed $output): mixed
    {
        // A Trajectory is not JsonSerializable (all state protected) — a plain
        // json_encode would ship `{}`. Project it through its public API instead.
        if ($output instanceof Trajectory) {
            return [
                'type' => 'trajectory',
                'transcript' => $output->toTranscript(),
                'turns' => $output->count(),
                'tool_calls' => array_map(
                    static fn (ToolCall $tool): string => $tool->getName(),
                    $output->toolCalls(),
                ),
                'usage' => $output->usage()->jsonSerialize(),
            ];
        }

        if (is_string($output) || is_int($output) || is_float($output) || is_bool($output) || $output === null) {
            return $output;
        }

        if (is_array($output) || is_object($output)) {
            try {
                return json_encode($output, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 'Unable to serialize output';
            }
        }

        return (string) $output;
    }
}
