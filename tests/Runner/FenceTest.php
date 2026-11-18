<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runner;

use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Runner\RunnerTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;

/**
 * Neuron reads a missing attempt as "no fence", and would execute every copy
 * of such a message: the runner refuses a wake, an answer or a signal that
 * carries none before anything is posted, claimed or executed.
 */
class FenceTest extends RunnerTestCase
{
    #[DataProvider('backends')]
    public function test_a_wake_that_carries_no_attempt_is_refused_as_invalid_request_and_does_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $this->assertRefusedBeforeAnythingIsDone($this->unfenced($this->wake('order', $waiting)), $waiting);
    }

    #[DataProvider('backends')]
    public function test_an_answer_that_carries_no_attempt_is_refused_as_invalid_request_and_does_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $this->assertRefusedBeforeAnythingIsDone($this->unfenced($this->answer('order', $waiting, ['amount' => 100])), $waiting);
    }

    #[DataProvider('backends')]
    public function test_a_signal_that_carries_no_attempt_is_refused_as_invalid_request_and_does_nothing(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $waiting = $this->orderWaitingForPayment($bench);

        $this->assertRefusedBeforeAnythingIsDone($this->unfenced($this->signal('order', $waiting, WaitingWorkflow::EVENT)), $waiting);
    }

    /**
     * @param Report $waiting the report of the start: the run as it stood before the message came
     */
    protected function assertRefusedBeforeAnythingIsDone(Invocation $unfenced, Report $waiting): void
    {
        $sent = count($this->bench->platform->received());
        $executions = $this->bench->executions->total();

        $thrown = $this->thrownBy($unfenced);

        self::assertSame(Code::InvalidRequest, $thrown->reason);
        self::assertCount($sent, $this->bench->platform->received(), 'Nothing is announced or reported for it.');
        self::assertSame([], $this->queue->dispatched());
        self::assertSame($executions, $this->bench->executions->total());
        self::assertSame($waiting->run, $this->bench->iq->invoke($this->observeOf($this->start('order')))->run);
    }

    /**
     * The same message as a worker of another making could hand to invoke(): with no attempt.
     */
    protected function unfenced(Invocation $continuation): Invocation
    {
        return Invocation::fromArray(['attempt' => null] + $continuation->toArray());
    }

    /**
     * "order" started and suspended on its wait for "payment.received", which has no deadline.
     *
     * @return Report the report of the start
     */
    protected function orderWaitingForPayment(TestBench $bench): Report
    {
        $executions = $bench->executions;
        $bench->iq->register('order', static fn (): WaitingWorkflow => new WaitingWorkflow($executions));

        return $bench->iq->invoke($this->start('order'));
    }
}
