<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Continuations;

use DateTimeImmutable;
use Exception;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Tests\Stub\Continuations\AwaitBuyerNode;
use NeuronCore\Cloud\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\Cloud\Tests\Support\Continuations\ContinuationTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use const NAN;

/**
 * Step 3 of section 9.7, for the payload: what Neuron would refuse when it
 * admits an answer or a signal is refused in the request, and what it takes
 * reaches the wait unchanged, also through the queue.
 */
class PayloadTest extends ContinuationTestCase
{
    protected const NOT_JSON_COMPATIBLE = 'A resume event payload must be JSON-compatible.';

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadsJsonCannotHold')]
    public function test_a_payload_json_cannot_hold_is_refused_in_the_request_with_the_exception_neuron_throws_for_it(
        string $continuation,
        string $backend,
        array $payload,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, $payload));

        self::assertSame(WorkflowException::class, $refusal::class);
        self::assertSame(self::NOT_JSON_COMPATIBLE, $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_stages_a_binary_string_and_throws_that_exception_only_when_the_answer_runs(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->contract()->start()->run();
        $contract = $this->withoutTheSdk(new ContractWorkflow($executions));

        $staged = $contract->submitInputs(['signature' => "\xB1\x31"]);
        $refusal = self::thrownFrom(static fn (): WorkflowState => $staged->run());

        self::assertSame(WorkflowException::class, $refusal::class);
        self::assertSame(self::NOT_JSON_COMPATIBLE, $refusal->getMessage());
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_float_reaches_the_wait_through_the_queue_as_the_float_it_was(string $continuation, string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, ['deposit' => 10.0])->dispatch();
        $this->work();

        self::assertSame(['deposit' => 10.0], $contract->submitInputs(self::SELLER)->run()->get('buyer'));
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_an_object_json_can_encode_is_not_refused_and_reaches_the_wait_through_the_queue_as_an_object_of_its_class(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $signedAt = new DateTimeImmutable('2026-10-08 09:30:00 UTC');

        $this->prepare($continuation, $contract, ContractWorkflow::EVENT, ['signedAt' => $signedAt])->dispatch();
        $this->work();

        $received = $contract->submitInputs(self::SELLER)->run()->get('buyer');
        self::assertEquals(['signedAt' => $signedAt], $received);
        self::assertNotSame($signedAt, $received['signedAt'], 'The queue carried a copy of the object, as a real queue does.');
    }

    #[DataProvider('continuationsOnBackends')]
    public function test_a_closure_in_the_payload_is_thrown_back_as_php_refuses_to_serialize_it_and_nothing_is_queued(
        string $continuation,
        string $backend,
    ): void {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $queued = $this->countQueued();
        $payload = ['onSigned' => static fn (): string => 'notify the seller'];

        $thrown = self::thrownFrom(fn (): PendingSegment => $this->prepare($continuation, $contract, ContractWorkflow::EVENT, $payload));

        self::assertSame(Exception::class, $thrown::class);
        self::assertSame("Serialization of 'Closure' is not allowed", $thrown->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_neuron_alone_throws_the_same_for_a_closure_when_the_answer_is_staged(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $this->contract()->start()->run();
        $contract = $this->withoutTheSdk(new ContractWorkflow($executions));
        $payload = ['onSigned' => static fn (): string => 'notify the seller'];

        $thrown = self::thrownFrom(static fn (): PendingExecution => $contract->submitInputs($payload));

        self::assertSame(Exception::class, $thrown::class);
        self::assertSame("Serialization of 'Closure' is not allowed", $thrown->getMessage());
    }

    #[DataProvider('backends')]
    public function test_an_empty_answer_dispatched_resumes_the_wait_and_is_not_taken_for_a_wake(string $backend): void
    {
        $executions = $this->benchOn($backend)->executions;
        $contract = $this->contract();
        $contract->start()->run();

        $contract->submitInputs([])->dispatch();
        $this->work();

        self::assertSame(1, $executions->count(AwaitBuyerNode::class));
    }

    #[DataProvider('backends')]
    public function test_a_signal_given_no_payload_resumes_the_wait_with_an_empty_one(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();

        $contract->signal(ContractWorkflow::EVENT)->dispatch();
        $this->work();

        self::assertSame([], $contract->submitInputs(self::SELLER)->run()->get('buyer'));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}> the call, the backend and the payload
     */
    public static function payloadsJsonCannotHold(): array
    {
        $payloads = [
            'a binary string' => ['signature' => "\xB1\x31"],
            'a float that is not a number' => ['deposit' => NAN],
        ];

        $rows = [];
        foreach (TestBench::backends() as $backendName => [$backend]) {
            foreach ($payloads as $payloadName => $payload) {
                $rows["an answer with {$payloadName} on {$backendName}"] = [self::ANSWER, $backend, $payload];
                $rows["a signal with {$payloadName} on {$backendName}"] = [self::SIGNAL, $backend, $payload];
            }
        }

        return $rows;
    }
}
