<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Streamed\CloseTaleNode;
use NeuronCore\Cloud\Tests\Stub\Streamed\OpenTaleNode;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rows 15 and 16 of section 7 of the design: the run of a stream whose
 * consumer left is failed, like the run of a node that threw. It holds its
 * workflow ID until it is finished, by recover() or by the platform's wake,
 * and whoever finishes it runs again only the node that was streaming.
 */
class AfterAnAbandonedStreamTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_recover_finishes_the_run_of_a_stream_whose_consumer_left(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);

        $recovered = $stage->thread->recover();

        self::assertSame(WorkflowStatus::Completed, $recovered?->getStatus());
        self::assertNull($stage->stored());
    }

    #[DataProvider('kinds')]
    public function test_recover_runs_again_the_node_that_was_streaming_and_no_node_that_had_finished(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);
        $ranBefore = self::bodiesRunByNode($stage);

        $stage->thread->recover();

        self::assertSame(
            [OpenTaleNode::class => $ranBefore[OpenTaleNode::class], CloseTaleNode::class => $ranBefore[CloseTaleNode::class] + 1],
            self::bodiesRunByNode($stage),
        );
    }

    #[DataProvider('kinds')]
    public function test_recover_gives_the_state_the_stream_would_have_returned(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);

        $recovered = $stage->thread->recover();

        self::assertSame(
            $kind->continuesARun() ? ['reader' => Stage::ANSWER, 'told' => true] : ['told' => true],
            $recovered?->all(),
        );
    }

    #[DataProvider('kinds')]
    public function test_a_second_recover_has_nothing_left_to_finish(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);
        $stage->thread->recover();
        $bodiesRun = $stage->bodiesRun();

        $again = $stage->thread->recover();

        self::assertNull($again);
        self::assertSame($bodiesRun, $stage->bodiesRun());
    }

    #[DataProvider('kinds')]
    public function test_the_abandoned_stream_and_the_recovery_are_each_announced_once_and_observed_once(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);

        $stage->thread->recover();

        [, $abandoned, , $recovery] = $stage->queued();
        self::assertSame([Verb::Announce, Verb::Observe, Verb::Announce, Verb::Observe], $stage->verbsQueued());
        self::assertSame(
            [[$kind->verb()->value, null, null], [Verb::Wake->value, Outcome::Completed->value, $kind->attempt() + 1]],
            [
                [$abandoned->body['verb'] ?? null, $abandoned->body['outcome'] ?? null, $abandoned->body['executedAttempt'] ?? null],
                [$recovery->body['verb'] ?? null, $recovery->body['outcome'] ?? null, $recovery->body['executedAttempt'] ?? null],
            ],
        );
    }

    #[DataProvider('kinds')]
    public function test_the_platform_hears_the_failed_run_of_the_abandoned_stream_then_that_the_recovery_completed_it(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);
        [, $ofTheStream] = $stage->drain();
        $stage->thread->recover();

        [, $ofTheRecovery] = $stage->drain();

        self::assertInstanceOf(Report::class, $ofTheStream);
        self::assertInstanceOf(Report::class, $ofTheRecovery);
        self::assertSame(
            [[$kind->verb(), Outcome::Observed, 'failed', $kind->attempt()], [Verb::Wake, Outcome::Completed, null, null]],
            [
                [$ofTheStream->verb, $ofTheStream->outcome, $ofTheStream->run['status'] ?? null, $ofTheStream->run['attempt'] ?? null],
                [$ofTheRecovery->verb, $ofTheRecovery->outcome, $ofTheRecovery->run['status'] ?? null, $ofTheRecovery->run['attempt'] ?? null],
            ],
        );
    }

    #[DataProvider('kinds')]
    public function test_the_wake_of_the_platform_finishes_the_run_once_when_nobody_recovers_it(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);
        $failed = $stage->stored();
        $ranBefore = self::bodiesRunByNode($stage);

        $stage->work(self::wakeOf($failed));
        $again = $stage->work(self::wakeOf($failed));

        self::assertNull($stage->stored());
        self::assertSame(Outcome::Refused, $again->outcome);
        self::assertSame(
            [OpenTaleNode::class => $ranBefore[OpenTaleNode::class], CloseTaleNode::class => $ranBefore[CloseTaleNode::class] + 1],
            self::bodiesRunByNode($stage),
        );
    }

    #[DataProvider('backends')]
    public function test_a_new_start_is_refused_while_the_run_of_an_abandoned_stream_is_failed(string $backend): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, Kind::Start);
        $events = $stage->stream();

        $refusal = self::caught($events->valid(...));

        self::assertInstanceOf(RunInFlightException::class, $refusal);
        self::assertSame(WorkflowStatus::Failed, $refusal->status);
    }

    #[DataProvider('continuations')]
    public function test_the_answer_of_an_abandoned_stream_cannot_be_submitted_again_while_its_run_is_failed(string $backend, Kind $kind): void
    {
        $stage = $this->stageLeftInItsLastNode($backend, $kind);

        $refused = self::caught($stage->prepare(...));

        self::assertInstanceOf(InputTranslationException::class, $refused);
    }

    /**
     * A stage whose stream was left on the first word of the last node: the
     * first node had finished, the last one was streaming.
     */
    protected function stageLeftInItsLastNode(string $backend, Kind $kind): Stage
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->current();
        $events->next();
        $events->next();
        unset($events);

        self::assertSame('failed', $stage->stored()['status'] ?? null, 'The stream was not abandoned.');

        return $stage;
    }

    /**
     * The wake the platform sends for a failed run, fenced with what its last report showed.
     *
     * @param array<string, mixed>|null $failed the run as the store holds it
     */
    protected static function wakeOf(?array $failed): Invocation
    {
        return new Invocation(
            'inv_wake',
            Verb::Wake,
            Origin::PLATFORM,
            Stage::DEFINITION,
            Stage::WORKFLOW_ID,
            (string) ($failed['runId'] ?? ''),
            (int) ($failed['attempt'] ?? 0),
        );
    }

    /**
     * @return array<class-string, int>
     */
    protected static function bodiesRunByNode(Stage $stage): array
    {
        return [
            OpenTaleNode::class => $stage->bench->executions->count(OpenTaleNode::class),
            CloseTaleNode::class => $stage->bench->executions->count(CloseTaleNode::class),
        ];
    }
}
