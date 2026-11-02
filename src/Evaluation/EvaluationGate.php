<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Evaluation;

use Closure;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\NeuronCloud;

use function addcslashes;
use function array_slice;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * A step of a CI build that fails it when the evaluations of the build broke
 * something new, where the exit code of `neuron evaluation` fails on any failure.
 *
 * The platform decides: it knows the earlier runs of the branch. An item that failed
 * for the first time and passed a re-run is reported and does not fail the build.
 */
class EvaluationGate
{
    public const PASSED = 0;

    public const FAILED = 1;

    /** No answer: the build must not read it as a pass, nor as something the evaluators found. */
    public const UNKNOWN = 2;

    protected const MAX_FAILURES_SHOWN = 20;

    /** Why an evaluator compared nothing, as the platform names it. */
    protected const GAPS = [
        'not_measured' => 'it holds no verdict',
        'no_baseline' => 'no earlier run to compare with',
        'agent_not_rerun' => 'every output came from the cache',
        'checks_changed' => 'its checks changed',
        'dataset_changed' => 'its dataset changed',
        'nothing_comparable' => 'no item held a verdict on both sides',
    ];

    protected Closure $write;

    /**
     * @param callable(string): void $write receives the report, line by line
     */
    public function __construct(
        protected NeuronCloud $cloud,
        protected RunContext $context,
        callable $write,
    ) {
        // What it reports comes from models and tools, and must not reach the CI as commands or escape sequences
        $this->write = static function (string $line) use ($write): void {
            $write(addcslashes($line, "\0..\37\177"));
        };
    }

    public function run(): int
    {
        if ($this->context->suiteId === null) {
            ($this->write)('No suite to read: this is no CI build the SDK knows. Set NEURON_EVALUATION_SUITE_ID where the evaluations run and here.');

            return self::UNKNOWN;
        }

        try {
            $suite = $this->cloud->evaluationSuite($this->context->suiteId);
        } catch (CloudException $e) {
            ($this->write)("No answer about suite {$this->context->suiteId}: {$e->getMessage()}");

            return self::UNKNOWN;
        }

        foreach ($this->evaluators($suite) as $evaluator) {
            ($this->write)($this->evaluatorLine($evaluator));
        }

        foreach ($this->failures($suite) as $failure) {
            ($this->write)($this->failureLine($failure));
        }

        [$exitCode, $closing] = match ($suite['verdict'] ?? null) {
            'passed' => [self::PASSED, $this->closingLine($suite)],
            'failed' => [self::FAILED, 'Something new broke, or an evaluator could not run.'],
            'incomplete' => [self::UNKNOWN, 'The results of some evaluators did not arrive.'],
            default => [self::UNKNOWN, 'The platform gave no verdict.'],
        };
        ($this->write)($closing);

        return $exitCode;
    }

    /**
     * @param array<string, mixed> $suite
     */
    protected function closingLine(array $suite): string
    {
        foreach ($this->evaluators($suite) as $evaluator) {
            if (($evaluator['gap'] ?? null) !== null || ($evaluator['compared'] ?? 0) < ($evaluator['total'] ?? 0)) {
                return 'Nothing new broke in what could be compared.';
            }
        }

        return 'Nothing new broke.';
    }

    /**
     * @param array<string, mixed> $suite
     * @return list<array<string, mixed>>
     */
    protected function evaluators(array $suite): array
    {
        return is_array($suite['evaluators'] ?? null) ? $suite['evaluators'] : [];
    }

    /**
     * @param array<string, mixed> $suite
     * @return array<int, array<string, mixed>>
     */
    protected function failures(array $suite): array
    {
        $failures = is_array($suite['failures'] ?? null) ? $suite['failures'] : [];

        return array_slice($failures, 0, self::MAX_FAILURES_SHOWN);
    }

    /**
     * @param array<string, mixed> $evaluator
     */
    protected function evaluatorLine(array $evaluator): string
    {
        $line = sprintf(
            '%s: %s, %d of %d compared, %d new, %d confirmed, %d fixed',
            $evaluator['name'] ?? 'Evaluator',
            $evaluator['status'] ?? 'unknown',
            $evaluator['compared'] ?? 0,
            $evaluator['total'] ?? 0,
            $evaluator['new_failures'] ?? 0,
            $evaluator['confirmed_failures'] ?? 0,
            $evaluator['fixed'] ?? 0,
        );

        $gap = self::GAPS[$evaluator['gap'] ?? ''] ?? null;
        $line = $gap === null ? $line : "{$line}: {$gap}";

        return is_string($evaluator['url'] ?? null) ? "{$line} {$evaluator['url']}" : $line;
    }

    /**
     * @param array<string, mixed> $failure
     */
    protected function failureLine(array $failure): string
    {
        $reasons = is_array($failure['reasons'] ?? null) ? $failure['reasons'] : [];

        return rtrim(sprintf(
            '  %s item %d (%s, %s of %s re-runs passed): %s',
            $failure['evaluator'] ?? 'Evaluator',
            $failure['index'] ?? 0,
            ($failure['confirmed'] ?? false) ? 'confirmed' : 'not confirmed',
            $failure['reruns_passed'] ?? 0,
            $failure['reruns'] ?? 0,
            $reasons[0] ?? ($failure['error'] ?? ''),
        ));
    }
}
