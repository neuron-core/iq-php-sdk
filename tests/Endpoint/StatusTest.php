<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Tests\Stub\Endpoint\AwaitUnencodableEventNode;
use NeuronCore\Cloud\Tests\Stub\Flows\SupportAgent;
use NeuronCore\Cloud\Tests\Stub\Requests\UnencodableRunWorkflow;
use NeuronCore\Cloud\Tests\Stub\Runner\UnreachableQueue;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointTestCase;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\TestBench;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;
use function hash;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * One test per status and code of the endpoint, as the table of the wire
 * protocol lists them.
 */
class StatusTest extends EndpointTestCase
{
    protected const THREAD_ID = 'thread:42';

    #[DataProvider('backends')]
    public function test_a_start_is_queued_and_answered_202(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = $this->deliver($this->start());

        self::assertSame([202, []], [$answer->statusCode, self::fieldsOf($answer)]);
        $bench->dispatcher->assertDispatched(Verb::Start, self::WORKFLOW_ID);
    }

    #[DataProvider('backends')]
    public function test_a_202_has_run_nothing_and_claimed_nothing(string $backend): void
    {
        $bench = $this->shop($backend);

        $this->deliver($this->start());

        $shown = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)));
        self::assertSame(0, $bench->executions->total());
        self::assertSame([null, false], [$shown['run'], $shown['claimed']]);
    }

    #[DataProvider('backends')]
    public function test_a_wake_is_queued_and_answered_202(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->orderWaitingForPayment();
        $heard = count($bench->platform->received());

        $answer = $this->deliver($this->wake(attempt: 1));

        self::assertSame([202, []], [$answer->statusCode, self::fieldsOf($answer)]);
        $bench->dispatcher->assertDispatched(Verb::Wake, self::WORKFLOW_ID);
        self::assertCount($heard, $bench->platform->received(), 'Nothing runs in the request: no segment was announced.');
    }

    #[DataProvider('backends')]
    public function test_an_inspect_is_answered_200_with_the_run_that_holds_the_workflow_id(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();
        $before = time();

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        $shown = self::fieldsOf($answer);
        self::assertSame(200, $answer->statusCode);
        self::assertSame(
            [
                'runId' => self::RUN_ID,
                'status' => 'suspended',
                'attempt' => 1,
                'leaseExpiresAt' => null,
                'wait' => ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => WaitingWorkflow::EVENT, 'wakeAt' => null],
            ],
            $shown['run'],
        );
        self::assertSame([true, true], [$shown['claimed'], $shown['snapshotRead']]);
        self::assertGreaterThanOrEqual($before, $shown['at']);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_that_names_no_run_id_answers_claimed_null(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();

        $shown = self::fieldsOf($this->deliver($this->inspect()));

        self::assertSame(self::RUN_ID, $shown['run']['runId']);
        self::assertNull($shown['claimed']);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_is_answered_200_with_no_snapshot_when_the_store_cannot_be_read(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();
        $this->failTheStore();

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        $shown = self::fieldsOf($answer);
        self::assertSame(200, $answer->statusCode);
        self::assertSame([null, null, false], [$shown['run'], $shown['claimed'], $shown['snapshotRead']]);
        self::assertStringNotContainsString(self::STORE_FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_of_the_run_that_holds_the_workflow_id_is_answered_200_and_frees_it(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        $shown = self::fieldsOf($answer);
        self::assertSame(200, $answer->statusCode);
        self::assertSame([null, true, true], [$shown['run'], $shown['claimed'], $shown['snapshotRead']]);
        self::assertNull(self::fieldsOf($this->deliver($this->inspect()))['run']);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_of_a_start_still_in_the_queue_is_answered_200_and_the_start_never_runs(string $backend): void
    {
        $bench = $this->shop($backend);
        $this->deliver($this->start());

        $answer = $this->deliver($this->abandon(self::RUN_ID));
        [$report] = $this->work();

        $shown = self::fieldsOf($answer);
        self::assertSame(200, $answer->statusCode);
        self::assertSame([null, true], [$shown['run'], $shown['claimed']]);
        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Rejected, Code::AlreadyClaimed->value], [$report->outcome, $report->code]);
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_the_entry_an_abandon_writes_in_the_ledger_can_be_used_for_an_hour(string $backend): void
    {
        $bench = $this->shop($backend);
        $before = time();

        $this->deliver($this->abandon(self::RUN_ID));

        $stored = ($bench->persistence)()->get('__neuron_cloud:' . hash('sha256', self::WORKFLOW_ID), 'starts');
        $useBy = json_decode((string) $stored, true, flags: JSON_THROW_ON_ERROR)[self::RUN_ID];
        self::assertGreaterThanOrEqual($before + 3600, $useBy);
        self::assertLessThanOrEqual(time() + 3600, $useBy);
    }

    #[DataProvider('backends')]
    public function test_a_ping_is_answered_200_with_the_protocols_the_sdk_speaks(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['verb' => 'ping']);

        self::assertSame([200, ['protocols' => [1]]], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    public function test_a_ping_is_answered_before_a_persistence_is_set(): void
    {
        $platform = $this->benchOn(TestBench::IN_MEMORY)->platform;
        $unconfigured = new NeuronCloud(TestBench::API_KEY, $platform->signingKey);

        self::assertSame(200, $platform->deliver($unconfigured, ['verb' => 'ping'])->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_message_whose_field_breaks_its_format_is_answered_400_invalid_request(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = $this->deliver($this->start(['runId' => '01JB2M8Q3W']));

        self::assertSame([400, ['code' => 'invalid_request']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_a_signed_body_that_is_not_json_is_answered_400_invalid_request(string $backend): void
    {
        $bench = $this->shop($backend);
        $body = '{"protocol": 1, "verb":';

        $answer = $bench->cloud->handle('POST', $this->signedHeaders($body), $body);

        self::assertSame([400, ['code' => 'invalid_request']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_a_start_that_could_be_used_for_more_than_an_hour_is_answered_400_invalid_request(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = $this->deliver($this->start(['expiresAt' => time() + 3600 + 300 + 60]));

        self::assertSame([400, ['code' => 'invalid_request']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('reservedAndUnknownVerbs')]
    public function test_a_reserved_or_unknown_verb_is_answered_400_invalid_request(string $verb): void
    {
        $bench = $this->shop(TestBench::IN_MEMORY);

        $answer = $this->deliver(['verb' => $verb, 'attempt' => 1] + $this->start());

        self::assertSame([400, ['code' => 'invalid_request']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_another_protocol_is_answered_400_unsupported_protocol(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['protocol' => 2] + $this->start());

        self::assertSame([400, ['code' => 'unsupported_protocol']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('methodsOtherThanPost')]
    public function test_a_method_other_than_post_is_answered_405_invalid_request_before_its_signature_is_looked_at(string $method): void
    {
        $bench = $this->shop(TestBench::IN_MEMORY);

        $answer = $bench->cloud->handle($method, [], '');

        self::assertSame([405, ['code' => 'invalid_request']], [$answer->statusCode, self::fieldsOf($answer)]);
        ProtocolSchema::assertValid('error', self::fieldsOf($answer));
    }

    #[DataProvider('keysThatAreNoKey')]
    public function test_an_endpoint_that_holds_no_usable_signing_key_answers_every_request_401_invalid_signature(?string $signingKey): void
    {
        $bench = $this->shop(TestBench::IN_MEMORY);
        $cloud = new NeuronCloud(TestBench::API_KEY, $signingKey);

        $answer = $bench->platform->deliver($cloud, ['verb' => 'ping']);

        self::assertSame([401, ['code' => 'invalid_signature']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_a_message_signed_with_another_key_is_answered_401_invalid_signature(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = (new FakePlatform())->deliver($bench->cloud, $this->start());

        self::assertSame([401, ['code' => 'invalid_signature']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing unverified is queued.');
    }

    #[DataProvider('backends')]
    public function test_an_altered_body_is_answered_401_invalid_signature(string $backend): void
    {
        $bench = $this->shop($backend);
        $signed = json_encode(['protocol' => 1] + $this->abandon(self::RUN_ID), JSON_THROW_ON_ERROR);
        $altered = json_encode(['protocol' => 1] + $this->abandon('1-another-run'), JSON_THROW_ON_ERROR);

        $answer = $bench->cloud->handle('POST', $this->signedHeaders($signed), $altered);

        self::assertSame([401, ['code' => 'invalid_signature']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertFalse(self::fieldsOf($this->deliver($this->inspect('1-another-run')))['claimed'], 'Nothing unverified is obeyed.');
    }

    #[DataProvider('timestampsOutOfTheWindow')]
    public function test_a_timestamp_out_of_the_window_is_answered_401_invalid_signature(int $secondsFromNow): void
    {
        $bench = $this->shop(TestBench::IN_MEMORY);

        $answer = $this->deliver($this->start(), time() + $secondsFromNow);

        self::assertSame([401, ['code' => 'invalid_signature']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'A replayed request queues nothing.');
    }

    #[DataProvider('backends')]
    public function test_a_definition_this_deployment_lacks_is_answered_404_unknown_definition(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = $this->deliver($this->start(['definition' => 'nightly-reconciliation']));

        self::assertSame([404, ['code' => 'unknown_definition']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_a_question_about_a_definition_this_deployment_lacks_is_answered_404_unknown_definition(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->inspect());

        self::assertSame([404, ['code' => 'unknown_definition']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_a_start_on_a_version_this_deployment_lacks_is_answered_404_unknown_version(string $backend): void
    {
        $bench = $this->shop($backend);

        $answer = $this->deliver($this->start(['runId' => '2-0192f3a8']));

        self::assertSame([404, ['code' => 'unknown_version']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $bench->dispatcher->runAll($bench->cloud), 'Nothing was queued.');
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_version_this_deployment_lacks_is_answered_404_unknown_version(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->wake(attempt: 1, fields: ['runId' => '2-0192f3a8']));

        self::assertSame([404, ['code' => 'unknown_version']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_neuron_refuses_is_answered_409_with_its_reason_and_the_run(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 7));

        $refusal = self::fieldsOf($answer);
        self::assertSame([409, 'stale_attempt'], [$answer->statusCode, $refusal['code']]);
        self::assertSame([self::RUN_ID, 'suspended', 1], [$refusal['run']['runId'], $refusal['run']['status'], $refusal['run']['attempt']]);
        self::assertSame([true, true], [$refusal['claimed'], $refusal['snapshotRead']]);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_of_a_run_that_is_not_the_holder_is_answered_409_stale_run_with_the_holder(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();

        $answer = $this->deliver($this->abandon('1-a-start-that-never-ran'));

        $refusal = self::fieldsOf($answer);
        self::assertSame([409, 'stale_run'], [$answer->statusCode, $refusal['code']]);
        self::assertSame(self::RUN_ID, $refusal['run']['runId'], 'The run that holds the workflow ID was left alone.');
    }

    #[DataProvider('backends')]
    public function test_an_abandon_the_guard_of_an_agent_refuses_is_answered_409_abandon_refused_with_the_run(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $runId = $this->turnWaitingForAnApproval($bench);

        $answer = $this->deliver(['definition' => 'support-agent', 'workflowId' => self::THREAD_ID] + $this->abandon($runId, attempt: 1));

        $refusal = self::fieldsOf($answer);
        self::assertSame([409, 'abandon_refused'], [$answer->statusCode, $refusal['code']]);
        self::assertSame([$runId, 'suspended'], [$refusal['run']['runId'], $refusal['run']['status']], 'The agent keeps its run.');
        self::assertTrue($refusal['claimed']);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_that_finds_its_run_id_in_the_ledger_and_no_run_is_answered_409_stale_run_with_no_run(string $backend): void
    {
        $this->shop($backend);
        $this->deliver($this->abandon(self::RUN_ID));

        $answer = $this->deliver($this->abandon(self::RUN_ID));

        self::assertSame(409, $answer->statusCode);
        self::assertSame(
            ['code' => 'stale_run', 'run' => null, 'claimed' => true, 'snapshotRead' => true],
            self::without('at', self::fieldsOf($answer)),
        );
    }

    #[DataProvider('backends')]
    public function test_a_queue_that_takes_nothing_is_answered_503_dispatch_failed_with_a_retry_after(string $backend): void
    {
        $bench = $this->shop($backend);
        $bench->cloud->setDispatcher(new UnreachableQueue());

        $answer = $this->deliver($this->start());

        self::assertSame([503, ['code' => 'dispatch_failed']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
        self::assertSame(0, $bench->executions->total());
    }

    #[DataProvider('backends')]
    public function test_an_abandon_is_answered_503_unavailable_with_a_retry_after_when_the_store_fails(string $backend): void
    {
        $this->shop($backend);
        $this->orderWaitingForPayment();
        $this->failTheStore();

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame([503, ['code' => 'unavailable']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertGreaterThan(0, (int) $answer->header('Retry-After'));
        self::assertStringNotContainsString(self::STORE_FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_anything_else_that_is_thrown_is_answered_500_internal_error(string $backend): void
    {
        $bench = $this->benchOn($backend);
        // The run this workflow shows has an ID no JSON body can hold: the answer cannot be encoded.
        $bench->cloud->register(self::DEFINITION, static fn (): Workflow => new UnencodableRunWorkflow());

        $answer = $this->deliver($this->inspect());

        self::assertSame([500, ['code' => 'internal_error']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_an_event_name_that_is_not_utf8_is_shown_with_its_invalid_bytes_replaced(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $unencodable = static fn (): Workflow => (new Workflow())->addNode(new AwaitUnencodableEventNode());
        $bench->cloud->register(self::DEFINITION, $unencodable);
        // A run started outside the SDK waits for an event whose name no JSON body can hold as it is.
        $unencodable()->for(self::WORKFLOW_ID)->setPersistence(($bench->persistence)())->run();

        $answer = $this->deliver($this->inspect());

        self::assertSame(200, $answer->statusCode);
        self::assertSame("payment\u{FFFD}received", self::fieldsOf($answer)['run']['wait']['eventName']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedAndUnknownVerbs(): array
    {
        return [
            'answer, which is reserved' => ['answer'],
            'signal, which is reserved' => ['signal'],
            'a verb the protocol does not have' => ['retire'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methodsOtherThanPost(): array
    {
        return ['GET' => ['GET'], 'PUT' => ['PUT'], 'DELETE' => ['DELETE'], 'HEAD' => ['HEAD']];
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function keysThatAreNoKey(): array
    {
        return [
            'no key' => [null],
            'an empty key' => [''],
            'a key that is only its prefix' => ['whsec_'],
            'a key that does not decode' => ['whsec_not*base64*at*all'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function timestampsOutOfTheWindow(): array
    {
        return [
            'signed more than 300 seconds ago' => [-330],
            'signed more than 300 seconds ahead' => [330],
        ];
    }

    /**
     * The bootstrap: "order-fulfilment" waits for a payment, then for a timer, then completes.
     */
    protected function shop(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;

        $bench->cloud->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        return $bench;
    }

    /**
     * The platform started the order and a worker ran its first segment: attempt 1 waits for "payment.received".
     */
    protected function orderWaitingForPayment(): void
    {
        $this->deliver($this->start());
        $this->work();
    }

    /**
     * A chat turn stopped at the approval of a tool call: the conversation ends on a call nobody answered.
     *
     * @return string the run ID
     */
    protected function turnWaitingForAnApproval(TestBench $bench): string
    {
        $executions = $bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('refund', 'call_refund_1', ['iban' => 'IT60X0542811101000000123456'])]));
        $bench->cloud->register('support-agent', static fn (): Agent => new SupportAgent($provider, $conversations, $executions));

        return (string) $bench->cloud->workflow('support-agent', self::THREAD_ID)->start(new UserMessage('Refund order 123.'))->run()->getRunId();
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function without(string $field, array $fields): array
    {
        unset($fields[$field]);

        return $fields;
    }
}
