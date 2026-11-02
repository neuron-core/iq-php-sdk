<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Evaluation;

use NeuronCore\Cloud\Evaluation\RunContext;
use PHPUnit\Framework\TestCase;

class RunContextTest extends TestCase
{
    public function test_a_push_on_github_names_the_branch_the_commit_and_the_build(): void
    {
        $context = RunContext::detect([
            'GITHUB_RUN_ID' => '7812',
            'GITHUB_RUN_ATTEMPT' => '2',
            'GITHUB_REF_NAME' => 'main',
            'GITHUB_HEAD_REF' => '',
            'GITHUB_BASE_REF' => '',
            'GITHUB_SHA' => 'abc',
            'GITHUB_SERVER_URL' => 'https://github.com',
            'GITHUB_REPOSITORY' => 'acme/shop',
        ]);

        self::assertEquals(new RunContext('github-7812-2', 'main', null, 'abc', 'https://github.com/acme/shop/actions/runs/7812'), $context);
    }

    public function test_a_pull_request_on_github_names_its_own_branch_and_its_target(): void
    {
        $context = RunContext::detect([
            'GITHUB_RUN_ID' => '7812',
            'GITHUB_REF_NAME' => '41/merge',
            'GITHUB_HEAD_REF' => 'fix-refunds',
            'GITHUB_BASE_REF' => 'main',
        ]);

        self::assertSame(['github-7812-1', 'fix-refunds', 'main'], [$context->suiteId, $context->branch, $context->baseBranch]);
    }

    public function test_a_merge_request_on_gitlab_names_its_own_branch_and_its_target(): void
    {
        $context = RunContext::detect([
            'GITLAB_CI' => 'true',
            'CI_JOB_ID' => '901',
            'CI_COMMIT_REF_NAME' => 'refs/merge-requests/4',
            'CI_MERGE_REQUEST_SOURCE_BRANCH_NAME' => 'fix-refunds',
            'CI_MERGE_REQUEST_TARGET_BRANCH_NAME' => 'main',
            'CI_COMMIT_SHA' => 'abc',
            'CI_PIPELINE_URL' => 'https://gitlab.test/pipelines/55',
        ]);

        self::assertEquals(new RunContext('gitlab-901', 'fix-refunds', 'main', 'abc', 'https://gitlab.test/pipelines/55'), $context);
    }

    public function test_a_branch_pipeline_on_gitlab_has_no_target(): void
    {
        $context = RunContext::detect(['GITLAB_CI' => 'true', 'CI_JOB_ID' => '901', 'CI_COMMIT_REF_NAME' => 'main']);

        self::assertSame(['main', null], [$context->branch, $context->baseBranch]);
    }

    public function test_another_ci_names_everything_and_wins_over_what_is_detected(): void
    {
        $context = RunContext::detect([
            'GITHUB_RUN_ID' => '7812',
            'GITHUB_REF_NAME' => 'main',
            'NEURON_EVALUATION_SUITE_ID' => 'nightly-3',
            'NEURON_EVALUATION_BRANCH' => 'release',
            'NEURON_EVALUATION_BASE_BRANCH' => 'main',
            'NEURON_EVALUATION_COMMIT' => 'def',
            'NEURON_EVALUATION_CI_URL' => 'https://ci.test/3',
        ]);

        self::assertEquals(new RunContext('nightly-3', 'release', 'main', 'def', 'https://ci.test/3'), $context);
    }

    public function test_a_blank_override_is_no_override(): void
    {
        $context = RunContext::detect(['GITHUB_RUN_ID' => '7812', 'GITHUB_REF_NAME' => 'main', 'NEURON_EVALUATION_BRANCH' => '']);

        self::assertSame('main', $context->branch);
    }

    public function test_a_github_build_run_again_is_a_suite_of_its_own(): void
    {
        $first = RunContext::detect(['GITHUB_RUN_ID' => '7812', 'GITHUB_RUN_ATTEMPT' => '1']);
        $second = RunContext::detect(['GITHUB_RUN_ID' => '7812', 'GITHUB_RUN_ATTEMPT' => '2']);

        self::assertNotSame($first->suiteId, $second->suiteId);
    }

    public function test_no_ci_names_nothing(): void
    {
        self::assertEquals(new RunContext(), RunContext::detect([]));
    }

    public function test_out_of_ci_the_process_reads_the_branch_and_the_commit_from_git(): void
    {
        $context = RunContext::detect();

        self::assertNotNull($context->commit);
    }
}
