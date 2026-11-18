<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Questions;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\RefusalReason;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\Runner\BusyNode;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Questions\Holder;
use NeuronCore\IQ\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What still arrives for a run after the platform had it abandoned: a wake,
 * an answer or a signal fenced with the run that is gone, and the segment
 * that was executing it.
 */
class AbandonAftermathTest extends QuestionsTestCase
{
    protected const ANSWER = 'an answer';

    protected const SIGNAL = 'a signal';

    #[DataProvider('backends')]
    public function test_a_wake_of_the_abandoned_run_is_reported_refused_as_stale_run_with_no_run_and_executes_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());

        $this->deliver($this->wake(attempt: 1));
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Verb::Wake, Outcome::Refused, RefusalReason::StaleRun->value, null, null],
            [$report->verb, $report->outcome, $report->code, $report->executedAttempt, $report->run],
        );
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_the_abandoned_run_is_refused_and_leaves_alone_the_run_that_took_its_place(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());
        $this->deliver($this->start(['invocationId' => 'inv_again', 'runId' => '1-a-new-run']));
        $this->work();
        $successor = $this->storedRun();

        $this->deliver($this->wake(attempt: 1));
        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Refused, RefusalReason::StaleRun->value, $successor], [$report->outcome, $report->code, $report->run]);
        self::assertSame('1-a-new-run', $successor['runId'] ?? null);
        self::assertSame(2, $bench->executions->count(AwaitPaymentNode::class), 'One execution per start: the wake ran nothing.');
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_the_application_prepared_before_the_abandon_is_reported_refused_as_stale_run_and_executes_nothing(
        string $backend,
        string $continuation,
    ): void {
        $bench = $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, function () use ($continuation): void {
            $prepared = $this->prepare($continuation, $this->bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID));
            $this->abandonTheHolder();
            $prepared->dispatch();
        });

        [$report] = $this->work();

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Outcome::Refused, RefusalReason::StaleRun->value, null, null],
            [$report->outcome, $report->code, $report->executedAttempt, $report->run],
        );
        self::assertSame(1, $bench->executions->total());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_continuation_sent_after_the_abandon_finds_no_run_and_is_refused_in_the_request(string $backend, string $continuation): void
    {
        $this->benchOn($backend);
        $this->whileHeldBy(Holder::WaitingForAnEvent, fn (): HttpResponse => $this->abandonTheHolder());
        $order = $this->bench->iq->workflow(self::DEFINITION, self::WORKFLOW_ID);

        $this->expectException(InputTranslationException::class);

        $this->prepare($continuation, $order);
    }

    #[DataProvider('backends')]
    public function test_the_segment_that_was_executing_a_dead_run_when_it_was_abandoned_is_reported_refused_as_stale_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $this->whileHeldBy(Holder::Dead, fn (): HttpResponse => $this->abandonTheHolder());

        [$report] = $this->settled;
        self::assertInstanceOf(Report::class, $report);
        self::assertSame(
            [Outcome::Refused, RefusalReason::StaleAttempt->value, 1, null],
            [$report->outcome, $report->code, $report->executedAttempt, $report->run],
        );
        self::assertSame(1, $bench->executions->count(BusyNode::class));
    }

    /**
     * @return array<string, array<int, mixed>> the backend and the call that prepares the continuation
     */
    public static function continuationsOnBackends(): array
    {
        return self::onEachBackend([self::ANSWER => [self::ANSWER], self::SIGNAL => [self::SIGNAL]]);
    }

    /**
     * The application answers the wait of the order, or delivers the event the order waits for.
     */
    protected function prepare(string $continuation, ManagedWorkflow $order): PendingSegment
    {
        return $continuation === self::SIGNAL
            ? $order->signal(WaitingWorkflow::EVENT, ['amount' => 10])
            : $order->submitInputs(['amount' => 10]);
    }
}
