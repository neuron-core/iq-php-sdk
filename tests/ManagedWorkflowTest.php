<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests;

use DateTimeImmutable;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\ManagedWorkflow;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\PendingSegment;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Stub\CompleteNode;
use NeuronCore\Cloud\Tests\Stub\CompletingWorkflow;
use NeuronCore\Cloud\Tests\Stub\DeliverNode;
use NeuronCore\Cloud\Tests\Stub\FlakyNode;
use NeuronCore\Cloud\Tests\Stub\FlakyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Local\BriefingAgent;
use NeuronCore\Cloud\Tests\Stub\Local\OrderFulfilment;
use NeuronCore\Cloud\Tests\Stub\Local\PaidAmountTranslator;
use NeuronCore\Cloud\Tests\Stub\Local\ReviewedWorkflow;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Stub\PaymentSettled;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyNode;
use NeuronCore\Cloud\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\Cloud\Tests\Stub\Units\BriefingStartEvent;
use NeuronCore\Cloud\Tests\Stub\Units\OrderPlaced;
use NeuronCore\Cloud\Tests\Stub\Units\UnreadableOrderWorkflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Local\LocalTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function base64_decode;
use function count;
use function str_repeat;
use function time;
use function unserialize;
use function usleep;

use const NAN;

class ManagedWorkflowTest extends LocalTestCase
{
    protected const PAYMENT = ['amount' => 100];

    protected const MINTED_ON_VERSION_2 = '/^2-[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function test_a_name_that_is_not_registered_is_refused_as_unknown_definition(): void
    {
        $cloud = $this->benchOn(TestBench::IN_MEMORY)->cloud;

        $reason = self::reasonThrownBy(static fn (): ManagedWorkflow => $cloud->workflow('never-registered', self::WORKFLOW_ID));

        self::assertSame(Code::UnknownDefinition, $reason);
    }

    #[DataProvider('workflowIdsTheProtocolCannotCarry')]
    public function test_a_workflow_id_the_protocol_cannot_carry_is_refused_as_invalid_request(string $workflowId): void
    {
        $cloud = $this->benchWith('greeting')->cloud;

        $reason = self::reasonThrownBy(static fn (): ManagedWorkflow => $cloud->workflow('greeting', $workflowId));

        self::assertSame(Code::InvalidRequest, $reason);
    }

    #[DataProvider('workflowIdsTheProtocolCannotCarry')]
    public function test_neuron_refuses_every_workflow_id_the_protocol_cannot_carry(string $workflowId): void
    {
        $store = ($this->benchOn(TestBench::IN_MEMORY)->persistence)();

        $this->expectExceptionMessage('Invalid workflow ID');

        (new Workflow())->for($workflowId)->setPersistence($store)->inspect();
    }

    public function test_a_workflow_id_of_255_multibyte_characters_is_accepted_as_neuron_accepts_it(): void
    {
        $bench = $this->benchWith('greeting');
        $workflowId = str_repeat('é', 255);

        $thread = $bench->cloud->workflow('greeting', $workflowId);

        self::assertInstanceOf(ManagedWorkflow::class, $thread);
        self::assertNull((new Workflow())->for($workflowId)->setPersistence(($bench->persistence)())->inspect());
    }

    public function test_configure_returns_the_instance_it_was_called_on(): void
    {
        $thread = $this->benchWith('greeting')->cloud->workflow('greeting', self::WORKFLOW_ID);

        self::assertSame($thread, $thread->configure(static function (): void {
        }));
    }

    public function test_a_start_mints_a_run_id_that_carries_the_current_version(): void
    {
        $bench = $this->benchWith('greeting');
        $executions = $bench->executions;
        $bench->cloud->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions), version: 2);

        $runId = $bench->cloud->workflow('greeting', self::WORKFLOW_ID)->start()->dispatch();

        self::assertMatchesRegularExpression(self::MINTED_ON_VERSION_2, $runId);
    }

    public function test_every_start_mints_a_run_id_of_its_own(): void
    {
        $thread = $this->benchWith('greeting')->cloud->workflow('greeting', self::WORKFLOW_ID);

        self::assertNotSame($thread->start()->dispatch(), $thread->start()->dispatch());
    }

    public function test_the_factory_receives_the_workflow_id_and_the_minted_run_id(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $received = [];
        $bench->cloud->register('greeting', static function (string $workflowId, ?string $runId) use ($executions, &$received): Workflow {
            $received[] = [$workflowId, $runId];

            return new CompletingWorkflow($executions);
        });

        $state = $bench->cloud->workflow('greeting', self::WORKFLOW_ID)->start()->run();

        self::assertSame([[self::WORKFLOW_ID, $state->getRunId()]], $received);
    }

    public function test_a_start_with_no_input_ignites_the_run_with_the_workflow_own_event(): void
    {
        $bench = $this->benchWith('greeting');

        $state = $bench->cloud->workflow('greeting', self::WORKFLOW_ID)->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_a_start_ignites_the_run_with_the_event_it_is_given(): void
    {
        $bench = $this->benchWith('order-fulfilment');

        $state = $bench->cloud->workflow('order-fulfilment', self::WORKFLOW_ID)->start(new OrderPlaced('order:777'))->run();

        self::assertSame('order:777', $state->get('orderId'));
    }

    public function test_a_start_whose_event_no_node_handles_is_refused_as_invalid_start_before_anything_is_queued(): void
    {
        $bench = $this->benchWith('greeting');
        $thread = $bench->cloud->workflow('greeting', self::WORKFLOW_ID);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $thread->start(new PaymentSettled()));

        self::assertSame(Code::InvalidStart, $reason);
        self::assertSame([], $this->queue->dispatched());
        self::assertSame(0, $bench->executions->total());
    }

    public function test_what_a_hook_of_the_workflow_throws_is_thrown_from_start_as_it_is(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->cloud->register('unreadable', static fn (): Workflow => new UnreadableOrderWorkflow());
        $thread = $bench->cloud->workflow('unreadable', self::WORKFLOW_ID);

        $this->expectExceptionObject(new RuntimeException('The orders table cannot be read.'));

        $thread->start();
    }

    public function test_a_message_starts_an_agent_on_a_turn_of_that_message(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Refunded.'));
        $bench = $this->benchWithAgent($provider);

        $state = $bench->cloud->workflow('assistant', self::WORKFLOW_ID)->start(new UserMessage('Refund order 123.'))->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        $provider->assertCallCount(1);
        $provider->assertSent(static fn (RequestRecord $request): bool => $request->method === 'chat'
            && $request->messages[0]->getContent() === 'Refund order 123.');
    }

    public function test_the_stream_argument_makes_the_provider_stream_its_answer(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Refunded.'));
        $bench = $this->benchWithAgent($provider);
        $conversation = [new UserMessage('Hello.'), new AssistantMessage('How can I help?'), new UserMessage('Refund order 123.')];

        $bench->cloud->workflow('assistant', self::WORKFLOW_ID)->start($conversation, stream: true)->run();

        $provider->assertSent(static fn (RequestRecord $request): bool => $request->method === 'stream');
    }

    public function test_messages_are_set_on_a_copy_of_the_event_the_start_event_hook_of_the_agent_builds(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->cloud->register('briefing', static fn (): Agent => (new BriefingAgent())->setAiProvider(new FakeAIProvider()));

        $bench->cloud->workflow('briefing', self::WORKFLOW_ID)->start(new UserMessage('Brief me.'), stream: true)->dispatch();

        $event = unserialize((string) base64_decode((string) $this->queue->dispatched()[0]->event, true));
        self::assertInstanceOf(BriefingStartEvent::class, $event);
        self::assertSame('Brief me.', $event->messages[0]->getContent());
        self::assertSame(BriefingAgent::MAX_RETRIES, $event->options->maxRetries);
        self::assertTrue($event->options->stream);
    }

    public function test_messages_given_to_a_workflow_that_is_not_an_agent_are_an_invalid_start(): void
    {
        $thread = $this->benchWith('greeting')->cloud->workflow('greeting', self::WORKFLOW_ID);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $thread->start(new UserMessage('Hello.')));

        self::assertSame(Code::InvalidStart, $reason);
    }

    #[DataProvider('startsOfAnAgentWithNoMessage')]
    public function test_an_agent_started_with_no_message_is_an_invalid_start(?array $input): void
    {
        $provider = new FakeAIProvider();
        $thread = $this->benchWithAgent($provider)->cloud->workflow('assistant', self::WORKFLOW_ID);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $thread->start($input));

        self::assertSame(Code::InvalidStart, $reason);
        self::assertSame([], $this->queue->dispatched());
        $provider->assertNothingSent();
    }

    public function test_an_answer_for_a_workflow_id_no_run_holds_is_an_input_translation_exception(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);

        $this->expectException(InputTranslationException::class);

        $thread->submitInputs(self::PAYMENT);
    }

    public function test_an_answer_for_a_run_that_is_not_suspended_is_an_input_translation_exception(): void
    {
        $thread = $this->benchWith('flaky')->cloud->workflow('flaky', self::WORKFLOW_ID);
        $this->failAStart($thread);

        $this->expectException(InputTranslationException::class);

        $thread->submitInputs(self::PAYMENT);
    }

    public function test_an_answer_for_a_run_that_failed_with_its_answered_wait_still_attached_is_an_input_translation_exception(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->cloud->register('review', static fn (): Workflow => new ReviewedWorkflow(static function (): never {
            throw new NodeFailed('The review could not be filed.');
        }));
        $thread = $bench->cloud->workflow('review', self::WORKFLOW_ID);
        $thread->start()->run();
        try {
            $thread->submitInputs(['approved' => true])->run();
            self::fail('The node did not throw.');
        } catch (NodeFailed) {
        }

        $this->expectException(InputTranslationException::class);

        $thread->submitInputs(['approved' => false]);
    }

    public function test_an_answer_run_in_place_resumes_the_wait_with_its_payload(): void
    {
        $bench = $this->benchWith('order');
        $thread = $bench->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();

        $state = $thread->submitInputs(self::PAYMENT)->run();

        self::assertSame(self::PAYMENT, $state->get('payment'));
        self::assertSame(1, $bench->executions->count(DeliverNode::class));
    }

    #[DataProvider('continuationsRunInPlace')]
    public function test_a_continuation_run_in_place_executes_the_workflow_the_configure_callback_adjusted(string $verb): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();
        $configured = [];
        $thread->configure(static function (Workflow $workflow) use (&$configured): void {
            $configured[] = $workflow::class;
        });

        $continuation = $verb === 'signal' ? $thread->signal(WaitingWorkflow::EVENT, self::PAYMENT) : $thread->submitInputs(self::PAYMENT);
        $continuation->run();

        self::assertSame([WaitingWorkflow::class], $configured);
    }

    public function test_an_answer_is_translated_against_the_wait_the_run_is_suspended_on(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();
        $translator = new PaidAmountTranslator();

        $state = $thread->submitInputs(['paid' => '100'], $translator)->run();

        self::assertSame(self::PAYMENT, $state->get('payment'));
        self::assertInstanceOf(WaitForEventRequest::class, $translator->translatedAgainst);
        self::assertSame(WaitingWorkflow::EVENT, $translator->translatedAgainst->getEventName());
    }

    public function test_what_the_translator_refuses_is_thrown_and_nothing_is_queued(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->dispatch();
        $this->queue->runAll($this->bench->cloud);
        $queued = $this->verbsQueued();

        try {
            $thread->submitInputs(['reference' => 'no amount'], new PaidAmountTranslator());
            self::fail('The payload was accepted although the translator refused it.');
        } catch (InputTranslationException $refusal) {
            self::assertSame('The webhook names no paid amount.', $refusal->getMessage());
        }

        self::assertSame($queued, $this->verbsQueued());
    }

    #[DataProvider('payloadsNeuronCannotHold')]
    public function test_a_payload_that_is_not_json_compatible_is_refused_before_anything_is_queued(string $verb, array $payload): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->dispatch();
        $this->queue->runAll($this->bench->cloud);
        $queued = $this->verbsQueued();

        try {
            $verb === 'signal' ? $thread->signal(WaitingWorkflow::EVENT, $payload) : $thread->submitInputs($payload);
            self::fail('A payload Neuron would refuse was accepted.');
        } catch (WorkflowException $refusal) {
            self::assertSame('A resume event payload must be JSON-compatible.', $refusal->getMessage());
        }

        self::assertSame($queued, $this->verbsQueued());
    }

    public function test_an_answer_the_wait_rejects_is_refused_as_not_awaited_before_anything_is_queued(): void
    {
        $thread = $this->benchWith('order', deliveryAt: new DateTimeImmutable('+1 hour'))->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();
        $thread->signal(WaitingWorkflow::EVENT, self::PAYMENT)->run();
        $queued = $this->verbsQueued();

        try {
            $thread->submitInputs(self::PAYMENT);
            self::fail('An answer was prepared for a run that waits on a timer.');
        } catch (WorkflowRefusedException $refused) {
            self::assertSame(RefusalReason::NotAwaited, $refused->reason);
        }

        self::assertSame($queued, $this->verbsQueued());
    }

    public function test_an_answer_carries_the_run_id_and_the_attempt_the_snapshot_showed(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $started = $thread->start()->run();

        $runId = $thread->submitInputs(self::PAYMENT)->dispatch();

        $answer = $this->lastQueued();
        self::assertSame($started->getRunId(), $runId);
        self::assertSame(
            [Verb::Answer, Origin::APPLICATION, 'order', self::WORKFLOW_ID, $started->getRunId(), 1, self::PAYMENT],
            [$answer->verb, $answer->origin, $answer->definition, $answer->workflowId, $answer->runId, $answer->attempt, $answer->payload],
        );
    }

    public function test_an_answer_executes_on_the_version_its_run_id_pins_not_on_the_current_one(): void
    {
        $bench = $this->benchWith('order');
        $executions = $bench->executions;
        $bench->cloud->workflow('order', self::WORKFLOW_ID)->start()->run();
        $bench->cloud->register('order', static fn (): Workflow => new CompletingWorkflow($executions), version: 2);

        $state = $bench->cloud->workflow('order', self::WORKFLOW_ID)->submitInputs(self::PAYMENT)->run();

        self::assertSame(self::PAYMENT, $state->get('payment'));
        self::assertSame(1, $executions->count(DeliverNode::class));
        self::assertSame(0, $executions->count(CompleteNode::class));
    }

    public function test_an_answer_for_a_run_of_a_version_this_process_lacks_is_refused_as_unknown_version(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): Workflow => new WaitingWorkflow($executions), version: 2);
        $bench->cloud->workflow('order', self::WORKFLOW_ID)->start()->run();
        $oldDeploy = (new NeuronCloud(TestBench::API_KEY, httpClient: $bench->platform))
            ->setPersistence($bench->persistence)
            ->setDispatcher($this->queue)
            ->register('order', static fn (): Workflow => new WaitingWorkflow($executions), version: 1);

        $reason = self::reasonThrownBy(static fn (): PendingSegment => $oldDeploy->workflow('order', self::WORKFLOW_ID)->submitInputs(self::PAYMENT));

        self::assertSame(Code::UnknownVersion, $reason);
    }

    public function test_an_answer_for_a_run_started_outside_the_sdk_executes_on_the_lowest_version(): void
    {
        $bench = $this->benchWith('order');
        $executions = $bench->executions;
        $bench->cloud->register('order', static fn (): Workflow => new CompletingWorkflow($executions), version: 2);
        (new WaitingWorkflow($executions))->for(self::WORKFLOW_ID)->setPersistence(($bench->persistence)())->run();

        $state = $bench->cloud->workflow('order', self::WORKFLOW_ID)->submitInputs(self::PAYMENT)->run();

        self::assertSame(self::PAYMENT, $state->get('payment'));
        self::assertSame(1, $executions->count(DeliverNode::class));
    }

    public function test_a_signal_for_a_workflow_id_no_run_holds_is_an_input_translation_exception(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);

        $this->expectException(InputTranslationException::class);

        $thread->signal(WaitingWorkflow::EVENT);
    }

    public function test_a_signal_the_run_waits_for_carries_its_name_its_payload_and_the_fence_of_the_snapshot(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $started = $thread->start()->run();

        $thread->signal(WaitingWorkflow::EVENT, self::PAYMENT)->dispatch();

        $signal = $this->lastQueued();
        self::assertSame(
            [Verb::Signal, WaitingWorkflow::EVENT, self::PAYMENT, $started->getRunId(), 1],
            [$signal->verb, $signal->signal, $signal->payload, $signal->runId, $signal->attempt],
        );
    }

    public function test_a_signal_with_another_name_than_the_awaited_one_is_refused_as_not_awaited(): void
    {
        $thread = $this->benchWith('order')->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();

        self::assertSame(RefusalReason::NotAwaited, $this->refusalOf($thread, 'shipment.delayed'));
    }

    public function test_a_signal_for_a_run_that_waits_for_a_timer_is_refused_as_not_awaited(): void
    {
        $thread = $this->benchWith('order', deliveryAt: new DateTimeImmutable('+1 hour'))->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();
        $thread->signal(WaitingWorkflow::EVENT, self::PAYMENT)->run();

        self::assertSame(RefusalReason::NotAwaited, $this->refusalOf($thread, WaitingWorkflow::EVENT));
    }

    public function test_recover_returns_null_and_queues_nothing_when_no_run_holds_the_workflow_id(): void
    {
        $thread = $this->benchWith('flaky')->cloud->workflow('flaky', self::WORKFLOW_ID);

        self::assertNull($thread->recover());
        self::assertSame([], $this->queue->dispatched());
    }

    public function test_recover_leaves_a_suspended_run_alone(): void
    {
        $bench = $this->benchWith('order');
        $thread = $bench->cloud->workflow('order', self::WORKFLOW_ID);
        $thread->start()->run();
        $queued = $this->verbsQueued();

        self::assertNull($thread->recover());
        self::assertSame($queued, $this->verbsQueued());
        self::assertSame(1, $bench->executions->count(AwaitPaymentNode::class));
    }

    public function test_recover_finishes_a_failed_run_in_this_process_and_returns_its_state(): void
    {
        $bench = $this->benchWith('flaky');
        $thread = $bench->cloud->workflow('flaky', self::WORKFLOW_ID);
        $this->failAStart($thread);

        $state = $thread->recover();

        self::assertInstanceOf(WorkflowState::class, $state);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(2, $bench->executions->count(FlakyNode::class));
    }

    public function test_recover_runs_a_wake_fenced_with_the_attempt_it_read_between_an_announcement_and_an_observe(): void
    {
        $thread = $this->benchWith('flaky')->cloud->workflow('flaky', self::WORKFLOW_ID);
        $this->failAStart($thread);

        $state = $thread->recover();

        [, , $announce, $observe] = $this->queue->dispatched();
        self::assertSame([Verb::Announce, Verb::Observe, Verb::Announce, Verb::Observe], $this->verbsQueued());
        self::assertSame(
            ['wake', Origin::APPLICATION, $state?->getRunId(), 1],
            [$announce->body['verb'], $announce->body['origin'], $announce->body['runId'], $announce->body['attempt']],
        );
        self::assertSame(['wake', 'completed', 2], [$observe->body['verb'], $observe->body['outcome'], $observe->body['executedAttempt']]);
    }

    public function test_recover_throws_what_a_node_that_fails_again_throws(): void
    {
        $bench = $this->benchWith('flaky', failures: 2);
        $thread = $bench->cloud->workflow('flaky', self::WORKFLOW_ID);
        $this->failAStart($thread);

        try {
            $thread->recover();
            self::fail('The node failed again and recover() returned.');
        } catch (NodeFailed) {
            self::assertSame(2, $bench->executions->count(FlakyNode::class));
        }

        self::assertSame('failed', $this->lastQueued()->body['outcome']);
    }

    public function test_recover_leaves_the_configure_callback_uncalled(): void
    {
        $calls = 0;
        $thread = $this->benchWith('flaky')->cloud->workflow('flaky', self::WORKFLOW_ID)->configure(static function () use (&$calls): void {
            $calls++;
        });
        $this->failAStart($thread);
        $calls = 0;

        $thread->recover();

        self::assertSame(0, $calls);
    }

    #[DataProvider('rivalsThatGetToAFailedRunFirst')]
    public function test_recover_returns_null_when_another_process_got_to_the_run_first(int $failures, RefusalReason $refusal): void
    {
        $bench = $this->benchWith('flaky', $failures);
        $executions = $bench->executions;
        $thread = $bench->cloud->workflow('flaky', self::WORKFLOW_ID);
        $this->failAStart($thread);
        // The run ID reaches the factory when the wake of recover() is built: after its snapshot, before Neuron admits it.
        $bench->cloud->register('flaky', static function (string $workflowId, ?string $runId) use ($bench, $executions, $failures): Workflow {
            static $rivalActed = false;

            if (!$rivalActed && $runId !== null) {
                $rivalActed = true;
                $bench->cloud->invoke(new Invocation('inv_rival', Verb::Wake, Origin::PLATFORM, 'flaky', $workflowId, $runId, 1));
            }

            return new FlakyWorkflow($executions, $failures);
        });

        $state = $thread->recover();

        self::assertNull($state);
        self::assertSame(2, $executions->count(FlakyNode::class), 'Only the rival executed the node again.');
        self::assertSame(['refused', $refusal->value], [$this->lastQueued()->body['outcome'], $this->lastQueued()->body['code']]);
    }

    public function test_recover_takes_over_a_run_left_running_under_a_lease_that_has_passed(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $recovered = null;
        $outlivesItsLease = static function () use ($bench, $executions, &$recovered): void {
            // recover() executes the node again, in this process: only the first execution is the dead worker.
            if ($executions->count(BusyNode::class) > 1) {
                return;
            }

            $leaseEnd = time() + 1;
            while (time() <= $leaseEnd) {
                usleep(50_000);
            }

            $recovered = $bench->cloud->workflow('slow', self::WORKFLOW_ID)->recover();
        };
        $bench->cloud->register('slow', static fn (): Workflow => new BusyWorkflow($executions, $outlivesItsLease), leaseTimeout: 1);

        try {
            $bench->cloud->workflow('slow', self::WORKFLOW_ID)->start()->run();
            self::fail('The worker taken for dead committed its segment.');
        } catch (WorkflowRefusedException) {
            // Its run was finished by recover(): Neuron refuses the write of the segment that lost it.
        }

        self::assertInstanceOf(WorkflowState::class, $recovered);
        self::assertSame(WorkflowStatus::Completed, $recovered->getStatus());
        self::assertSame(2, $recovered->getExecutionAttempt());
    }

    public function test_recover_leaves_alone_a_run_that_is_running_under_a_lease_that_has_not_passed(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $recovered = false;
        $queuedByRecover = null;
        $whileRunning = function () use ($bench, &$recovered, &$queuedByRecover): void {
            $queued = $this->verbsQueued();
            $recovered = $bench->cloud->workflow('busy', self::WORKFLOW_ID)->recover();
            $queuedByRecover = [$queued, $this->verbsQueued()];
        };
        $bench->cloud->register('busy', static fn (): Workflow => new BusyWorkflow($executions, $whileRunning));

        $state = $bench->cloud->workflow('busy', self::WORKFLOW_ID)->start()->run();

        self::assertNull($recovered);
        self::assertSame([[Verb::Announce], [Verb::Announce]], $queuedByRecover);
        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(1, $executions->count(BusyNode::class));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workflowIdsTheProtocolCannotCarry(): array
    {
        return [
            'an empty one' => [''],
            'one that begins with two underscores' => ['__neuron_cloud:order'],
            'one of 256 characters' => [str_repeat('a', 256)],
            'one with a control character' => ["order:\n123"],
            'one that ends with a line feed' => ["order:123\n"],
            'one with the DEL character' => ["order:\x7F123"],
            'one that is not UTF-8' => ["order:\xB1"],
        ];
    }

    /**
     * @return array<string, array{int, RefusalReason}> how many times the node throws, and what Neuron then
     *                                                    answers to the wake of recover()
     */
    public static function rivalsThatGetToAFailedRunFirst(): array
    {
        return [
            'a rival that completed the run' => [1, RefusalReason::StaleRun],
            'a rival whose node failed again, under the next attempt' => [2, RefusalReason::StaleAttempt],
        ];
    }

    /**
     * @return array<string, array{string}> the call that prepares the continuation
     */
    public static function continuationsRunInPlace(): array
    {
        return [
            'an answer' => ['submitInputs'],
            'a signal' => ['signal'],
        ];
    }

    /**
     * @return array<string, array{array<int, never>|null}>
     */
    public static function startsOfAnAgentWithNoMessage(): array
    {
        return [
            'no input: its own start event carries no message' => [null],
            'an empty list of messages' => [[]],
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>}> the call that takes the payload, and the payload
     */
    public static function payloadsNeuronCannotHold(): array
    {
        return [
            'an answer with a number JSON has no form for' => ['submitInputs', ['amount' => NAN]],
            'an answer with a string that is not UTF-8' => ['submitInputs', ['reference' => "\xB1\x31"]],
            'a signal with a number JSON has no form for' => ['signal', ['amount' => NAN]],
        ];
    }

    /**
     * A bench with one definition: "greeting" completes in one segment; "order" waits for the event
     * "payment.received", then for a timer; "order-fulfilment" does the same from an OrderPlaced; the node of
     * "flaky" throws $failures times before it completes.
     */
    protected function benchWith(string $name, int $failures = 1, DateTimeImmutable $deliveryAt = new DateTimeImmutable('@0')): TestBench
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;

        $factories = [
            'greeting' => static fn (): Workflow => new CompletingWorkflow($executions),
            'order' => static fn (): Workflow => new WaitingWorkflow($executions, deliveryAt: $deliveryAt),
            'order-fulfilment' => static fn (): Workflow => new OrderFulfilment($executions, $deliveryAt),
            'flaky' => static fn (): Workflow => new FlakyWorkflow($executions, $failures),
        ];
        $bench->cloud->register($name, $factories[$name]);

        return $bench;
    }

    /**
     * A bench with "assistant": an agent that declares no start event of its own.
     */
    protected function benchWithAgent(FakeAIProvider $provider): TestBench
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->cloud->register('assistant', static fn (): Agent => Agent::make()->setAiProvider($provider));

        return $bench;
    }

    /**
     * Start a run in this process whose node throws: it is left failed.
     */
    protected function failAStart(ManagedWorkflow $thread): void
    {
        try {
            $thread->start()->run();
            self::fail('The node did not throw.');
        } catch (NodeFailed) {
        }
    }

    protected function refusalOf(ManagedWorkflow $thread, string $signal): ?RefusalReason
    {
        try {
            $thread->signal($signal);
        } catch (WorkflowRefusedException $refused) {
            return $refused->reason;
        }

        return null;
    }

    protected function lastQueued(): Invocation
    {
        $queued = $this->queue->dispatched();

        return $queued[count($queued) - 1];
    }
}
