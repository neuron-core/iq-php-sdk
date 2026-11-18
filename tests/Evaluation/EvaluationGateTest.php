<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Evaluation;

use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\Evaluation\EvaluationGate;
use NeuronCore\IQ\Evaluation\RunContext;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function escapeshellarg;
use function exec;
use function implode;
use function json_encode;

use const PHP_BINARY;

class EvaluationGateTest extends TestCase
{
    /** @var list<string> */
    protected array $lines = [];

    public function test_it_asks_about_the_suite_of_the_build_with_the_api_key(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, (string) json_encode(['verdict' => 'passed'])));

        $this->gate($http, 'github 7/1')->run();

        [$request] = $http->received();
        self::assertSame(['https://platform.test/v1/evaluations/suites/github%207%2F1', 'Bearer key'], [$request->uri, $request->headers['Authorization'] ?? null]);
    }

    public function test_a_suite_that_broke_nothing_exits_0(): void
    {
        $exit = $this->gate($this->answering(['verdict' => 'passed', 'evaluators' => [
            ['name' => 'App\RefundEvaluator', 'status' => 'clean', 'compared' => 12, 'total' => 12, 'new_failures' => 0, 'confirmed_failures' => 0, 'fixed' => 2, 'url' => 'https://platform.test/runs/1'],
        ]]))->run();

        self::assertSame(0, $exit);
        self::assertSame(
            ['App\RefundEvaluator: clean, 12 of 12 compared, 0 new, 0 confirmed, 2 fixed https://platform.test/runs/1', 'Nothing new broke.'],
            $this->lines,
        );
    }

    public function test_a_suite_that_broke_something_exits_1_and_says_what(): void
    {
        $exit = $this->gate($this->answering(['verdict' => 'failed', 'failures' => [
            ['evaluator' => 'App\RefundEvaluator', 'index' => 3, 'confirmed' => true, 'reruns' => 2, 'reruns_passed' => 0, 'reasons' => ['Expected a refund.']],
            ['evaluator' => 'App\RefundEvaluator', 'index' => 4, 'confirmed' => false, 'reruns' => 2, 'reruns_passed' => 1, 'error' => 'It threw.', 'reasons' => []],
        ]]))->run();

        self::assertSame(1, $exit);
        self::assertSame([
            '  App\RefundEvaluator item 3 (confirmed, 0 of 2 re-runs passed): Expected a refund.',
            '  App\RefundEvaluator item 4 (not confirmed, 1 of 2 re-runs passed): It threw.',
            'Something new broke, or an evaluator could not run.',
        ], $this->lines);
    }

    public function test_a_suite_missing_an_evaluator_exits_2(): void
    {
        self::assertSame(2, $this->gate($this->answering(['verdict' => 'incomplete']))->run());
        self::assertSame(['The results of some evaluators did not arrive.'], $this->lines);
    }

    public function test_a_verdict_it_does_not_know_exits_2(): void
    {
        self::assertSame(2, $this->gate($this->answering(['verdict' => 'maybe']))->run());
    }

    public function test_a_suite_that_compared_part_of_its_items_says_so(): void
    {
        $this->gate($this->answering(['verdict' => 'passed', 'evaluators' => [['name' => 'E', 'status' => 'clean', 'compared' => 1, 'total' => 2]]]))->run();

        self::assertSame('Nothing new broke in what could be compared.', $this->lines[1]);
    }

    public function test_a_suite_that_compared_nothing_passes_and_says_why(): void
    {
        $exit = $this->gate($this->answering(['verdict' => 'passed', 'evaluators' => [
            ['name' => 'App\RefundEvaluator', 'status' => 'not_compared', 'gap' => 'agent_not_rerun'],
        ]]))->run();

        self::assertSame(0, $exit);
        self::assertSame([
            'App\RefundEvaluator: not_compared, 0 of 0 compared, 0 new, 0 confirmed, 0 fixed: every output came from the cache',
            'Nothing new broke in what could be compared.',
        ], $this->lines);
    }

    public function test_text_from_a_model_cannot_write_commands_to_the_ci_or_move_its_cursor(): void
    {
        $this->gate($this->answering(['verdict' => 'failed', 'failures' => [
            ['evaluator' => 'E', 'index' => 1, 'reasons' => ["x\n::add-mask::secret\n\e[2Jy"]],
        ]]))->run();

        self::assertSame('  E item 1 (not confirmed, 0 of 0 re-runs passed): x\\n::add-mask::secret\\n\\033[2Jy', $this->lines[0]);
    }

    public function test_it_shows_twenty_failures_at_most(): void
    {
        $failures = array_fill(0, 21, ['evaluator' => 'E', 'index' => 1, 'reasons' => ['No.']]);

        $this->gate($this->answering(['verdict' => 'failed', 'failures' => $failures]))->run();

        self::assertCount(20 + 1, $this->lines);
    }

    public function test_a_suite_the_platform_does_not_know_exits_2(): void
    {
        $exit = $this->gate(new ScriptedHttpClient(new HttpResponse(404, '{"message":"Not found."}')))->run();

        self::assertSame(2, $exit);
        self::assertStringContainsString('holds no evaluation of this suite', implode("\n", $this->lines));
    }

    public function test_an_answer_with_no_verdict_exits_2(): void
    {
        self::assertSame(2, $this->gate(new ScriptedHttpClient(new HttpResponse(200, '{}')))->run());
    }

    public function test_a_failed_answer_that_holds_a_verdict_exits_2(): void
    {
        self::assertSame(2, $this->gate(new ScriptedHttpClient(new HttpResponse(500, '{"verdict":"passed"}')))->run());
    }

    public function test_a_build_with_no_suite_asks_nothing_and_exits_2(): void
    {
        $http = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        self::assertSame(2, $this->gate($http, null)->run());
        self::assertCount(0, $http->received());
    }

    public function test_the_command_with_no_api_key_exits_2(): void
    {
        $exit = 0;
        $lines = [];

        exec('env -u IQ_API_KEY ' . PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../../bin/iq-gate') . ' 2>&1', $lines, $exit);

        self::assertSame(2, $exit);
        self::assertStringContainsString('IQ_API_KEY', implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $answer
     */
    protected function answering(array $answer): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse(200, (string) json_encode($answer)));
    }

    protected function gate(ScriptedHttpClient $http, ?string $suiteId = 'github-7-1'): EvaluationGate
    {
        return new EvaluationGate(
            new IQ('key', platformUrl: 'https://platform.test', httpClient: $http),
            new RunContext($suiteId),
            function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }
}
