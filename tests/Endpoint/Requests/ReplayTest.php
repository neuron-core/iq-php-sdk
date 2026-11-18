<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use DateTimeImmutable;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function time;

/**
 * Rows 1, 2, 4 and 17 of "When things go wrong": a request the platform
 * signed, sent again byte for byte inside the signature window, verifies and
 * is queued again. The copy is harmless: a worker refuses it and runs nothing.
 */
class ReplayTest extends RequestTestCase
{
    #[DataProvider('backends')]
    public function test_a_start_replayed_inside_the_window_is_queued_again(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, 'inv_start', time() - 200);

        $answers = [$this->post($body, $headers), $this->post($body, $headers)];

        self::assertSame([202, 202, 2], [$answers[0]->statusCode, $answers[1]->statusCode, $this->done()['messages queued']]);
    }

    #[DataProvider('backends')]
    public function test_the_copy_of_a_replayed_start_reports_already_claimed_and_runs_nothing(string $backend): void
    {
        $bench = $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, 'inv_start');
        $this->post($body, $headers);
        $this->post($body, $headers);

        $reports = $this->work();

        self::assertSame([[Outcome::Suspended, null], [Outcome::Rejected, Code::AlreadyClaimed->value]], self::outcomesOf($reports));
        self::assertSame(1, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_start_replayed_after_its_run_completed_reports_already_claimed_and_runs_nothing(string $backend): void
    {
        $bench = $this->shop($backend);
        $executions = $bench->executions;
        $this->iq->register(self::DEFINITION, static fn (): Workflow => new CompletingWorkflow($executions));
        $body = self::encode($this->start());
        $headers = $this->signed($body, 'inv_start');
        $this->post($body, $headers);
        $completed = $this->work();

        $answer = $this->post($body, $headers);
        $copy = $this->work();

        self::assertSame(202, $answer->statusCode);
        self::assertSame([[Outcome::Completed, null], [Outcome::Rejected, Code::AlreadyClaimed->value]], self::outcomesOf([...$completed, ...$copy]));
        self::assertSame(1, $executions->total());
    }

    #[DataProvider('backends')]
    public function test_the_copy_of_a_replayed_wake_is_refused_as_stale_attempt_and_runs_nothing(string $backend): void
    {
        $bench = $this->orderWhosePaymentIsOverdue($backend);
        $body = self::encode($this->wake(attempt: 1));
        $headers = $this->signed($body, 'inv_wake_1');
        $this->post($body, $headers);
        $this->work();
        $executedByTheWake = $bench->executions->total();

        $answer = $this->post($body, $headers);
        $copy = $this->work();

        self::assertSame(202, $answer->statusCode);
        self::assertSame([[Outcome::Refused, RefusalReason::StaleAttempt->value]], self::outcomesOf($copy));
        self::assertSame($executedByTheWake, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_two_copies_of_a_wake_in_the_queue_execute_one_segment(string $backend): void
    {
        $this->orderWhosePaymentIsOverdue($backend);
        $body = self::encode($this->wake(attempt: 1));
        $headers = $this->signed($body, 'inv_wake_1');
        $this->post($body, $headers);
        $this->post($body, $headers);

        $reports = $this->work();

        self::assertSame([[Outcome::Suspended, null], [Outcome::Refused, RefusalReason::StaleAttempt->value]], self::outcomesOf($reports));
        self::assertSame([2, null], array_map(static fn (Report|IQException $report): ?int => $report instanceof Report ? $report->executedAttempt : null, $reports));
    }

    #[DataProvider('backends')]
    public function test_a_wake_replayed_after_it_completed_the_run_is_refused_as_stale_run_and_runs_nothing(string $backend): void
    {
        $bench = $this->orderWhosePaymentIsOverdue($backend);
        $this->deliver($this->wake(attempt: 1));
        $this->work();
        $body = self::encode($this->wake(attempt: 2));
        $headers = $this->signed($body, 'inv_wake_2');
        $this->post($body, $headers);
        $completed = $this->work();
        $executedByTheRun = $bench->executions->total();

        $answer = $this->post($body, $headers);
        $copy = $this->work();

        self::assertSame(202, $answer->statusCode);
        self::assertSame([[Outcome::Completed, null], [Outcome::Refused, RefusalReason::StaleRun->value]], self::outcomesOf([...$completed, ...$copy]));
        self::assertSame($executedByTheRun, $bench->executions->total());
    }

    /**
     * The platform started the order and a worker ran its first segment: attempt 1 waits for a payment whose
     * deadline has passed, so a wake continues the run to its timer, which is due, and the next one completes it.
     */
    protected function orderWhosePaymentIsOverdue(string $backend): TestBench
    {
        $bench = $this->shop($backend);
        $executions = $bench->executions;
        $this->iq->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions, new DateTimeImmutable('-1 minute')));
        $this->deliver($this->start());
        $this->work();

        return $bench;
    }

    /**
     * @param array<int, Report|IQException> $reports
     * @return array<int, array{Outcome|null, string|null}> the outcome and the code of each report, in order
     */
    protected static function outcomesOf(array $reports): array
    {
        return array_map(
            static fn (Report|IQException $report): array => $report instanceof Report ? [$report->outcome, $report->code] : [null, $report->reason->value],
            $reports,
        );
    }
}
