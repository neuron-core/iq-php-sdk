<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Evaluation;

use function exec;
use function function_exists;
use function getenv;
use function trim;

/**
 * Where an evaluation ran: the code it evaluated and the CI build that ran it.
 *
 * The platform compares a run with the last one of its branch, and the suite ID
 * ties the evaluators of one build together, so that a later step of the build can
 * ask the platform about them. Each value comes from the environment of the process,
 * GitHub Actions and GitLab CI by themselves and any other CI through the
 * NEURON_EVALUATION_* variables. Out of CI the branch and the commit come from git.
 */
class RunContext
{
    public function __construct(
        public readonly ?string $suiteId = null,
        public readonly ?string $branch = null,
        public readonly ?string $baseBranch = null,
        public readonly ?string $commit = null,
        public readonly ?string $ciUrl = null,
    ) {
    }

    /**
     * @param array<string, string>|null $environment null: the environment of the process
     */
    public static function detect(?array $environment = null): self
    {
        $env = $environment ?? getenv();
        $ci = self::githubActions($env) ?? self::gitlabCi($env) ?? [];

        $value = static function (string $key) use ($env, $ci): ?string {
            $override = $env['NEURON_EVALUATION_' . $key] ?? '';

            return $override !== '' ? $override : ($ci[$key] ?? null);
        };

        return new self(
            $value('SUITE_ID'),
            $value('BRANCH') ?? ($environment === null ? self::git('rev-parse --abbrev-ref HEAD') : null),
            $value('BASE_BRANCH'),
            $value('COMMIT') ?? ($environment === null ? self::git('rev-parse HEAD') : null),
            $value('CI_URL'),
        );
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string|null>|null
     */
    protected static function githubActions(array $env): ?array
    {
        if (($env['GITHUB_RUN_ID'] ?? '') === '') {
            return null;
        }

        $attempt = $env['GITHUB_RUN_ATTEMPT'] ?? '1';

        return [
            'SUITE_ID' => "github-{$env['GITHUB_RUN_ID']}-{$attempt}",
            // A pull request has its branch here, and GITHUB_REF_NAME is its merge ref
            'BRANCH' => ($env['GITHUB_HEAD_REF'] ?? '') !== '' ? $env['GITHUB_HEAD_REF'] : ($env['GITHUB_REF_NAME'] ?? null),
            'BASE_BRANCH' => ($env['GITHUB_BASE_REF'] ?? '') !== '' ? $env['GITHUB_BASE_REF'] : null,
            'COMMIT' => $env['GITHUB_SHA'] ?? null,
            'CI_URL' => isset($env['GITHUB_SERVER_URL'], $env['GITHUB_REPOSITORY'])
                ? "{$env['GITHUB_SERVER_URL']}/{$env['GITHUB_REPOSITORY']}/actions/runs/{$env['GITHUB_RUN_ID']}"
                : null,
        ];
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string|null>|null
     */
    protected static function gitlabCi(array $env): ?array
    {
        if (($env['GITLAB_CI'] ?? '') === '') {
            return null;
        }

        return [
            // The job, as a retry keeps the pipeline: a retry is an attempt of its own
            'SUITE_ID' => isset($env['CI_JOB_ID']) ? "gitlab-{$env['CI_JOB_ID']}" : null,
            'BRANCH' => $env['CI_MERGE_REQUEST_SOURCE_BRANCH_NAME'] ?? $env['CI_COMMIT_REF_NAME'] ?? null,
            'BASE_BRANCH' => $env['CI_MERGE_REQUEST_TARGET_BRANCH_NAME'] ?? null,
            'COMMIT' => $env['CI_COMMIT_SHA'] ?? null,
            'CI_URL' => $env['CI_PIPELINE_URL'] ?? null,
        ];
    }

    /**
     * A machine with no git, or a directory outside a repository, has no answer.
     */
    protected static function git(string $arguments): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $lines = [];
        $status = 1;
        exec("git {$arguments} 2>/dev/null", $lines, $status);
        $answer = trim($lines[0] ?? '');

        return $status === 0 && $answer !== '' && $answer !== 'HEAD' ? $answer : null;
    }
}
