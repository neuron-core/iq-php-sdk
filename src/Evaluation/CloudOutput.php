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
use NeuronCore\Cloud\Http\Transport;
use NeuronCore\Cloud\Http\TransportException;
use NeuronCore\Cloud\NeuronCloud;

use function array_map;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function error_log;
use function rtrim;
use function uniqid;

use const JSON_THROW_ON_ERROR;

/**
 * Sends an evaluation run to a Neuron Cloud platform instance.
 *
 * This is the Neuron Cloud SDK home for the evaluation-output channel: it turns every
 * evaluator report in an {@see EvaluationReport} into a separate payload consumed
 * by the platform's `POST /api/evaluation` endpoint.
 *
 * Build it from the SDK root so it shares the platform credentials and transport,
 * and hand the concrete instance to neuron-ai's evaluation output pipeline (neuron-ai
 * no longer builds drivers from scalar config — it takes a class string or instance):
 *
 *   $cloud->evaluationOutput();
 *
 * The platformUrl is the `/api` root; evaluation payloads are POSTed to
 * `{platformUrl}/evaluation`.
 */
class CloudOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $platformUrl,
        private readonly string $apiKey,
        private readonly ?string $environment = null,
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
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('[NeuronCloudOutput] Failed to encode payload: ' . $e->getMessage());

            return;
        }

        try {
            $this->transport->send(
                rtrim($this->platformUrl, '/') . '/evaluation',
                $json,
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Neuron-SDK' => NeuronCloud::SDK,
                ],
                'POST',
            );
        } catch (TransportException $e) {
            error_log('[NeuronCloudOutput] Failed to send evaluation: ' . $e->getMessage());
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
    private function failureToArray(AssertionFailure $failure): array
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

    private function formatOutput(mixed $output): mixed
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
