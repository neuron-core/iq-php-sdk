<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Stub\Flows\SupportAgent;
use NeuronCore\Cloud\Tests\Stub\Requests\FailingQueue;
use NeuronCore\Cloud\Tests\Stub\Requests\UnencodableRunWorkflow;
use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function time;

/**
 * The table of statuses and codes of the wire protocol, row by row: each
 * status with each of its codes, reached the way the table says.
 */
class StatusTableTest extends RequestTestCase
{
    protected const THREAD_ID = 'thread:42';

    /**
     * @param Closure(self): HttpResponse $reach
     */
    #[DataProvider('rowsOfTheTable')]
    public function test_the_endpoint_answers_the_status_and_the_code_of_the_table(string $backend, Closure $reach, int $status, ?string $code): void
    {
        $this->shop($backend);

        $answer = $reach($this);

        self::assertSame([$status, $code], [$answer->statusCode, self::fieldsOf($answer)['code'] ?? null]);
    }

    /**
     * @return array<string, array<int, mixed>> the backend, what the platform does, the status and the code it is answered
     */
    public static function rowsOfTheTable(): array
    {
        return self::onEveryBackend([
            '202 for a start that is verified and valid' => [static fn (self $table): HttpResponse => $table->deliver($table->start()), 202, null],
            '202 for a wake that is verified and valid' => [static fn (self $table): HttpResponse => $table->deliver($table->wake(attempt: 1)), 202, null],
            '200 for an inspect' => [static fn (self $table): HttpResponse => $table->deliver($table->inspect(self::RUN_ID)), 200, null],
            '200 for a ping' => [static fn (self $table): HttpResponse => $table->post(self::PING), 200, null],
            '200 for an abandon of a start that is still in the queue' => [
                static function (self $table): HttpResponse {
                    $table->deliver($table->start());

                    return $table->deliver($table->abandon());
                },
                200,
                null,
            ],
            '400 invalid_request for a malformed message' => [
                static fn (self $table): HttpResponse => $table->deliver($table->start(['workflowId' => '__order'])),
                400,
                'invalid_request',
            ],
            '400 invalid_request for an unknown verb' => [
                static fn (self $table): HttpResponse => $table->deliver(['verb' => 'retire'] + $table->start()),
                400,
                'invalid_request',
            ],
            '400 unsupported_protocol for another protocol' => [
                static fn (self $table): HttpResponse => $table->deliver(['protocol' => 2] + $table->start()),
                400,
                'unsupported_protocol',
            ],
            '405 invalid_request for a request that is not a POST' => [
                static fn (self $table): HttpResponse => $table->post(self::PING, method: 'GET'),
                405,
                'invalid_request',
            ],
            '401 invalid_signature for a signature of another key' => [
                static function (self $table): HttpResponse {
                    $timestamp = (string) time();

                    return $table->post(self::PING, [
                        'webhook-id' => self::WEBHOOK_ID,
                        'webhook-timestamp' => $timestamp,
                        'webhook-signature' => self::signature('a key the platform never held', self::WEBHOOK_ID, $timestamp, self::PING),
                    ]);
                },
                401,
                'invalid_signature',
            ],
            '404 unknown_definition for a definition this deployment lacks' => [
                static fn (self $table): HttpResponse => $table->deliver($table->start(['definition' => 'nightly-reconciliation'])),
                404,
                'unknown_definition',
            ],
            '404 unknown_version for a version this deployment lacks' => [
                static fn (self $table): HttpResponse => $table->deliver($table->start(['runId' => '2-0192f3a8'])),
                404,
                'unknown_version',
            ],
            '409 with a refusal of Neuron for an abandon the state of the run refuses' => [
                static function (self $table): HttpResponse {
                    $table->orderWaitingForPayment();

                    return $table->deliver($table->abandon(self::RUN_ID, attempt: 7));
                },
                409,
                'stale_attempt',
            ],
            '409 stale_run for an abandon whose run ID was already in the ledger while no run holds the workflow ID' => [
                static function (self $table): HttpResponse {
                    $table->deliver($table->abandon());

                    return $table->deliver($table->abandon());
                },
                409,
                'stale_run',
            ],
            '409 abandon_refused for an abandon the guard of an agent refuses' => [
                static fn (self $table): HttpResponse => $table->deliver(
                    ['definition' => 'support-agent', 'workflowId' => self::THREAD_ID] + $table->abandon($table->turnWaitingForAnApproval(), attempt: 1),
                ),
                409,
                'abandon_refused',
            ],
            '503 dispatch_failed when the queue fails' => [
                static function (self $table): HttpResponse {
                    $table->cloud->setDispatcher(new FailingQueue(new RuntimeException('The queue is down.')));

                    return $table->deliver($table->start());
                },
                503,
                'dispatch_failed',
            ],
            '503 unavailable when the store fails during an abandon' => [
                static function (self $table): HttpResponse {
                    $table->failTheStore();

                    return $table->deliver($table->abandon());
                },
                503,
                'unavailable',
            ],
            '500 internal_error for anything else that is thrown' => [
                static function (self $table): HttpResponse {
                    $table->cloud->register(self::DEFINITION, static fn (): Workflow => new UnencodableRunWorkflow());

                    return $table->deliver($table->inspect());
                },
                500,
                'internal_error',
            ],
        ]);
    }

    /**
     * The platform started the order and a worker ran its first segment: attempt 1 waits for a payment.
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
    protected function turnWaitingForAnApproval(): string
    {
        $executions = $this->bench->executions;
        $conversations = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new ToolCallMessage(null, [new ToolCall('refund', 'call_refund_1', ['iban' => 'IT60X0542811101000000123456'])]));
        $this->cloud->register('support-agent', static fn (): Agent => new SupportAgent($provider, $conversations, $executions));

        return (string) $this->cloud->workflow('support-agent', self::THREAD_ID)->start(new UserMessage('Refund order 123.'))->run()->getRunId();
    }
}
