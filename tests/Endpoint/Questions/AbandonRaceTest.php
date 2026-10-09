<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Support\Concurrency\ProcessBench;
use NeuronCore\Cloud\Tests\Support\Questions\RaceBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\TestCase;

use function count;
use function extension_loaded;
use function json_decode;
use function max;
use function range;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * An abandon that reaches the endpoint while a queue worker, in a process of
 * its own, takes the start of the same run ID: many rounds on one SQLite
 * file. Whichever of the two writes the ledger first, a start never runs
 * after its abandon was answered 200 with no run.
 */
class AbandonRaceTest extends TestCase
{
    protected const ROUNDS = 30;

    /** Seconds the aim of the abandon moves by between two rounds. */
    protected const STEP = 0.0005;

    /** Seconds past the claim of the worker each round of a cycle aims at: the claim itself, then what the worker does next. */
    protected const PAST_THE_CLAIM = [0.0, 0.0005, 0.002, 0.004, 0.008];

    protected RaceBench $bench;

    /** Seconds from the instant a worker takes its start to the instant it writes its claim, as the rounds so far show it. */
    protected float $claimAfter = 0.0;

    protected function setUp(): void
    {
        foreach (['pcntl', 'posix', 'pdo_sqlite'] as $extension) {
            if (!extension_loaded($extension)) {
                self::markTestSkipped("Worker processes on SQLite need the {$extension} extension.");
            }
        }

        $this->bench = new RaceBench();
    }

    protected function assertPostConditions(): void
    {
        $this->bench->assertProtocolRespected();
    }

    protected function tearDown(): void
    {
        if (isset($this->bench)) {
            $this->bench->cleanUp();
        }
    }

    public function test_a_start_that_completes_never_runs_once_its_abandon_was_answered_200_and_always_ran_when_it_was_answered_409(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $workflowId = "order:{$round}";
            [$report, $answer, $executed] = $this->race(ProcessBench::COMPLETING, $workflowId, $round);
            $said = self::fieldsOf($answer);

            if ($answer->statusCode === 200) {
                self::assertSame(0, $executed, "Round {$round}: the start ran although its abandon was answered 200.");
                self::assertSame([null, true], [$said['run'], $said['claimed']]);
                self::assertSame([Outcome::Rejected->value, Code::AlreadyClaimed->value], [$report['outcome'], $report['code']]);
            } else {
                self::assertSame(1, $executed, "Round {$round}: the worker had claimed the start, so it ran.");
                self::assertSame([Outcome::Completed->value, 1], [$report['outcome'], $report['executedAttempt']]);
                $this->assertReportsTheStateOf($report['runId'], $answer, $round);
            }

            self::assertNull($this->bench->run($workflowId), "Round {$round}: no run is left.");
        }
    }

    public function test_a_start_that_suspends_either_never_runs_or_ran_before_its_abandon_discarded_it_or_reported_its_state(): void
    {
        foreach (range(1, self::ROUNDS) as $round) {
            $workflowId = "order:{$round}";
            [$report, $answer, $executed] = $this->race(ProcessBench::WAITING, $workflowId, $round);
            $said = self::fieldsOf($answer);
            $left = $this->bench->run($workflowId);

            if ($executed === 0) {
                self::assertSame([200, null, true], [$answer->statusCode, $said['run'], $said['claimed']], "Round {$round}: {$answer->body}");
                self::assertSame([Outcome::Rejected->value, Code::AlreadyClaimed->value], [$report['outcome'], $report['code']]);
                self::assertNull($left);

                continue;
            }

            self::assertSame(1, $executed);
            self::assertSame([Outcome::Suspended->value, 1], [$report['outcome'], $report['executedAttempt']]);

            if ($answer->statusCode === 200) {
                // A start that ran after this answer would have left its run suspended: the abandon discarded it.
                self::assertNull($left, "Round {$round}: the start ran after its abandon was answered 200 with no run.");
                self::assertSame([null, true], [$said['run'], $said['claimed']]);
            } else {
                $this->assertReportsTheStateOf($report['runId'], $answer, $round);
                self::assertSame($report['runId'], $left?->runId, "Round {$round}: the run the abandon did not discard is still there.");
            }
        }
    }

    /**
     * The abandon found the run ID in the ledger: it discarded nothing, and says why with the run as it then read it,
     * which by that time may have ended.
     */
    protected function assertReportsTheStateOf(string $runId, HttpResponse $answer, int $round): void
    {
        $said = self::fieldsOf($answer);

        self::assertSame(409, $answer->statusCode, "Round {$round}: {$answer->body}");
        self::assertContains(
            $said['code'],
            [RefusalReason::StaleRun->value, RefusalReason::Executing->value, RefusalReason::Conflict->value],
            "Round {$round}: {$answer->body}",
        );
        self::assertContains($said['run']['runId'] ?? null, [null, $runId], "Round {$round}: {$answer->body}");
        self::assertTrue($said['claimed']);
    }

    /**
     * One round: a worker takes the start of a new workflow ID while the platform has the same run ID abandoned.
     *
     * @return array{array<string, mixed>, HttpResponse, int} the report of the start, the answer to the abandon and
     *                                                        how many node bodies ran
     */
    protected function race(string $definition, string $workflowId, int $round): array
    {
        $runId = "1-round-{$round}";
        $start = Invocation::fromArray([
            'invocationId' => "inv_start_{$round}",
            'verb' => Verb::Start->value,
            'origin' => Origin::PLATFORM,
            'definition' => $definition,
            'workflowId' => $workflowId,
            'runId' => $runId,
            'expiresAt' => time() + 900,
        ]);
        $abandon = ['verb' => 'abandon', 'definition' => $definition, 'workflowId' => $workflowId, 'runId' => $runId];
        $past = self::PAST_THE_CLAIM[$round % count(self::PAST_THE_CLAIM)];
        $after = $this->claimAfter + $past;
        $executedBefore = $this->bench->executions->total();

        [$report, $answer] = $this->bench->race($start, $abandon, $after);
        $executed = $this->bench->executions->total() - $executedBefore;

        // No two machines reach the claim in the same time: the aim follows where the rounds show it to be.
        if ($executed === 0) {
            $this->claimAfter = max($this->claimAfter, $after) + self::STEP;
        } elseif ($past === 0.0) {
            $this->claimAfter = max(0.0, $this->claimAfter - self::STEP);
        }

        return [$report, $answer, $executed];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fieldsOf(HttpResponse $answer): array
    {
        return json_decode($answer->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
