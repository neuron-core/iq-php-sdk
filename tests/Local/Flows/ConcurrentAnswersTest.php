<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Flows;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderPlaced;
use NeuronCore\Cloud\Tests\Stub\Flows\ShipOrderNode;
use NeuronCore\Cloud\Tests\Support\Flows\ApplicationCall;
use NeuronCore\Cloud\Tests\Support\Flows\LocalProcessBench;
use NeuronCore\Cloud\Tests\Support\Flows\LocalProcessTestCase;
use NeuronCore\Cloud\Verb;

use function array_column;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function range;

/**
 * Answers to one wait that separate requests submit and run at the same
 * moment: Neuron accepts one, and every other request is told that the run
 * no longer waits for its answer.
 */
class ConcurrentAnswersTest extends LocalProcessTestCase
{
    protected const WORKFLOW_ID = 'order:123';

    protected const REQUESTS = 12;

    protected const ROUNDS = 3;

    /** How a request whose answer lost is told: by the check of submitInputs(), or by Neuron when it admits the answer. */
    protected const REFUSALS = [
        'thrown: ' . InputTranslationException::class,
        'thrown: stale_attempt',
        'thrown: stale_run',
        'thrown: conflict',
        'thrown: executing',
        'thrown: not_awaited',
    ];

    public function test_of_many_requests_that_answer_one_wait_at_once_one_is_accepted_and_the_others_are_refused(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $results = $this->requestsAnswerAtOnce("order:{$round}");

            $endings = $this->tally($results);
            self::assertSame(1, $endings['returned: ' . WorkflowStatus::Suspended->value] ?? 0, 'One answer continues the run, to its timer.');
            self::assertSame([], array_values(array_diff(array_keys($endings), ['returned: suspended', ...self::REFUSALS])));
        }
    }

    public function test_of_many_requests_that_answer_one_wait_at_once_one_continues_the_run_to_its_next_node(): void
    {
        $results = $this->requestsAnswerAtOnce(self::WORKFLOW_ID);

        [$winner] = array_values(array_filter(array_column($results, 'returned')));
        self::assertSame(1, $this->bench->executions->count(ShipOrderNode::class));
        self::assertSame([2, WorkflowStatus::Suspended], [$this->bench->run(self::WORKFLOW_ID)?->executionAttempt, $this->bench->run(self::WORKFLOW_ID)?->status]);
        self::assertContains($winner['payment'], array_map(static fn (int $request): array => ['amount' => $request], range(1, self::REQUESTS)));
    }

    public function test_the_platform_hears_of_one_answer_that_ran_a_segment_however_many_were_submitted_at_once(): void
    {
        $this->requestsAnswerAtOnce(self::WORKFLOW_ID);

        $this->bench->work();

        $answers = array_values(array_filter(
            $this->bench->platform->reports(),
            static fn (array $report): bool => $report['verb'] === Verb::Answer->value,
        ));
        $ran = array_values(array_filter($answers, static fn (array $report): bool => $report['executedAttempt'] !== null));
        $others = array_values(array_filter($answers, static fn (array $report): bool => $report['executedAttempt'] === null));
        self::assertSame([[Outcome::Suspended->value, 2]], array_map(static fn (array $report): array => [$report['outcome'], $report['executedAttempt']], $ran));
        self::assertSame(
            [],
            array_values(array_diff(array_column($others, 'outcome'), [Outcome::Refused->value])),
            'Every other answer that reached Neuron was refused.',
        );
    }

    /**
     * A request starts an order, which stops at its wait for the payment; then many requests answer that wait at once.
     *
     * @return array<int, array<string, mixed>> what submitInputs(['amount' => n])->run() returned or threw in each request
     */
    protected function requestsAnswerAtOnce(string $workflowId): array
    {
        $this->bench->cloud->workflow(LocalProcessBench::ORDER, $workflowId)->start(new OrderPlaced($workflowId))->run();
        $this->bench->work();

        return $this->bench->atOnce(...array_map(
            static fn (int $request): array => ApplicationCall::answerAndRun(LocalProcessBench::ORDER, $workflowId, ['amount' => $request]),
            range(1, self::REQUESTS),
        ));
    }
}
