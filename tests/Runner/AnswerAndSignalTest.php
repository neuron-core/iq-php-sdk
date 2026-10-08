<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Runner;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An answer and a signal the application dispatched, as its queue hands them
 * to the runner: the fence they carry was captured from the snapshot when the
 * answer arrived, and is never read again.
 */
class AnswerAndSignalTest extends RunnerTestCase
{
    protected const PAYMENT = ['amount' => 100];

    #[DataProvider('backends')]
    public function test_an_answer_fenced_with_the_snapshot_is_accepted_and_continues_the_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $report = $bench->cloud->invoke($this->answer('order', $waiting, self::PAYMENT));

        self::assertSame(Verb::Answer, $report->verb);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame(2, $report->executedAttempt);
        self::assertSame(2, $report->run['attempt']);
        self::assertSame('sleep_until', $report->run['wait']['type'], 'The run went on to its next wait.');
        self::assertNull($report->claimed);
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Answer);
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_an_answer_is_refused_as_stale_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);
        $answered = $bench->cloud->invoke($this->answer('order', $waiting, self::PAYMENT));
        $executions = $bench->executions->total();

        $copy = $bench->cloud->invoke($this->answer('order', $waiting, self::PAYMENT));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleAttempt->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertSame($answered->run, $copy->run);
        self::assertSame($executions, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_copy_of_an_answer_delivered_after_the_run_completed_is_refused_as_stale_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);
        $answered = $bench->cloud->invoke($this->answer('order', $waiting, self::PAYMENT));
        self::assertSame(Outcome::Completed, $bench->cloud->invoke($this->wake('order', $answered))->outcome);
        $executions = $bench->executions->total();

        $copy = $bench->cloud->invoke($this->answer('order', $waiting, self::PAYMENT));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleRun->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertNull($copy->run);
        self::assertSame($executions, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_signal_fenced_with_the_snapshot_is_accepted_and_continues_the_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $report = $bench->cloud->invoke($this->signal('order', $waiting, WaitingWorkflow::EVENT, self::PAYMENT));

        self::assertSame(Verb::Signal, $report->verb);
        self::assertSame(Outcome::Suspended, $report->outcome);
        self::assertSame(2, $report->executedAttempt);
        self::assertSame(2, $report->run['attempt']);
        self::assertSame('sleep_until', $report->run['wait']['type'], 'The run went on to its next wait.');
        self::assertNull($report->claimed);
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
        $bench->platform->assertAnnounced(self::WORKFLOW_ID, Verb::Signal);
    }

    #[DataProvider('backends')]
    public function test_a_second_copy_of_a_signal_is_refused_as_stale_attempt(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);
        $signalled = $bench->cloud->invoke($this->signal('order', $waiting, WaitingWorkflow::EVENT, self::PAYMENT));
        $executions = $bench->executions->total();

        $copy = $bench->cloud->invoke($this->signal('order', $waiting, WaitingWorkflow::EVENT, self::PAYMENT));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleAttempt->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertSame($signalled->run, $copy->run);
        self::assertSame($executions, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_copy_of_a_signal_delivered_after_the_run_completed_is_refused_as_stale_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);
        $signalled = $bench->cloud->invoke($this->signal('order', $waiting, WaitingWorkflow::EVENT, self::PAYMENT));
        self::assertSame(Outcome::Completed, $bench->cloud->invoke($this->wake('order', $signalled))->outcome);
        $executions = $bench->executions->total();

        $copy = $bench->cloud->invoke($this->signal('order', $waiting, WaitingWorkflow::EVENT, self::PAYMENT));

        self::assertSame(Outcome::Refused, $copy->outcome);
        self::assertSame(RefusalReason::StaleRun->value, $copy->code);
        self::assertNull($copy->executedAttempt);
        self::assertNull($copy->run);
        self::assertSame($executions, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_a_signal_with_another_event_name_is_refused_as_not_awaited(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $report = $bench->cloud->invoke($this->signal('order', $waiting, 'shipment.delayed'));

        self::assertSame(Outcome::Refused, $report->outcome);
        self::assertSame(RefusalReason::NotAwaited->value, $report->code);
        self::assertNull($report->executedAttempt);
        self::assertSame($waiting->run, $report->run, 'The run still waits for its own event, under the same attempt.');
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
        $bench->platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, RefusalReason::NotAwaited->value);
    }

    #[DataProvider('backends')]
    public function test_an_answer_whose_payload_neuron_cannot_hold_is_aborted_as_not_executed_and_never_rejected(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);
        // Handed to invoke() in the process that built it: no queue could carry a closure.
        $answer = new Invocation(
            'inv_answer',
            Verb::Answer,
            self::APPLICATION,
            'order',
            self::WORKFLOW_ID,
            self::RUN_ID,
            $waiting->run['attempt'],
            payload: ['approve' => static fn (): bool => true],
        );

        $thrown = $this->thrownBy($answer);

        self::assertSame(Code::NotExecuted, $thrown->reason);
        self::assertSame(Outcome::Aborted->value, $this->reportsSent()[1]['outcome']);
        self::assertSame($waiting->run, $this->reportsSent()[1]['run']);
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    /**
     * "order" started and suspended on its wait for "payment.received", which has no deadline; its timer is due.
     *
     * @return Report the report of the start: its snapshot gives the fence
     */
    protected function orderWaitingForPayment(TestBench $bench): Report
    {
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions));

        return $bench->cloud->invoke($this->start('order'));
    }
}
