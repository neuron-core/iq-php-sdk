<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Definition;
use NeuronCore\IQ\Runtime\Endpoint;
use NeuronCore\IQ\Runtime\Ledger;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Runtime\Registry;
use NeuronCore\IQ\Runtime\Signature;
use NeuronCore\IQ\Runtime\WorkflowBuilder;
use NeuronCore\IQ\Tests\Support\Runner\InspectableDispatcher;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

class EndpointTest extends TestCase
{
    protected const SIGNING_KEY = 'whsec_bmV1cm9uLWNsb3VkLXByb3RvY29sLTEtdmVjdG9ycyEh';

    protected const WEBHOOK_ID = 'inv_01JB7K4T9QW2';

    protected const PING = '{"protocol":1,"verb":"ping"}';

    protected InspectableDispatcher $queue;

    /** How many times the endpoint asked for a store, and for a workflow. */
    protected int $storesOpened = 0;

    protected int $workflowsBuilt = 0;

    protected function setUp(): void
    {
        $this->queue = new InspectableDispatcher();
    }

    public function test_the_headers_of_getallheaders_are_read_whatever_the_case_of_their_names(): void
    {
        $headers = self::signed(self::PING);

        $answer = $this->endpoint()->handle('POST', [
            'Webhook-Id' => $headers['webhook-id'],
            'WEBHOOK-TIMESTAMP' => $headers['webhook-timestamp'],
            'webhook-Signature' => $headers['webhook-signature'],
            'Content-Type' => 'application/json',
        ], self::PING);

        self::assertSame(200, $answer->statusCode);
    }

    public function test_the_headers_of_a_framework_are_read_from_their_lists_of_values(): void
    {
        $headers = array_map(static fn (string $value): array => [$value], self::signed(self::PING));

        $answer = $this->endpoint()->handle('POST', $headers + ['content-type' => ['application/json']], self::PING);

        self::assertSame(200, $answer->statusCode);
    }

    public function test_the_method_is_read_whatever_its_case(): void
    {
        self::assertSame(200, $this->endpoint()->handle('post', self::signed(self::PING), self::PING)->statusCode);
    }

    public function test_a_request_without_the_headers_of_a_signature_is_answered_401(): void
    {
        $answer = $this->endpoint()->handle('POST', ['Content-Type' => 'application/json'], self::PING);

        self::assertSame([401, ['code' => 'invalid_signature']], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    public function test_a_header_that_holds_no_string_is_no_signature(): void
    {
        $headers = ['webhook-id' => [['inv_1']], 'webhook-timestamp' => [], 'webhook-signature' => 7];

        self::assertSame(401, $this->endpoint()->handle('POST', $headers, self::PING)->statusCode);
    }

    public function test_the_signature_is_verified_before_the_body_is_decoded(): void
    {
        $notJson = '{"protocol": 1, "verb":';

        $answer = $this->endpoint()->handle('POST', ['webhook-signature' => 'v1,forged'] + self::signed(self::PING), $notJson);

        self::assertSame(401, $answer->statusCode, 'A body that cannot be decoded is a 400 only once it is known to be signed.');
    }

    public function test_an_answer_is_a_json_body_that_says_so(): void
    {
        $answer = $this->endpoint()->handle('POST', self::signed(self::PING), self::PING);

        self::assertSame('application/json', $answer->header('Content-Type'));
        self::assertSame(['protocols' => [1]], self::fieldsOf($answer));
    }

    public function test_a_start_is_handed_to_the_dispatcher_as_an_invocation_of_the_platform(): void
    {
        $expiresAt = time() + 900;
        $start = self::start($expiresAt);

        $answer = $this->endpoint()->handle('POST', self::signed($start), $start);

        self::assertSame([202, '{}'], [$answer->statusCode, $answer->body]);
        self::assertSame(
            [[
                'invocationId' => self::WEBHOOK_ID,
                'verb' => 'start',
                'origin' => Origin::PLATFORM,
                'definition' => 'order-fulfilment',
                'workflowId' => 'order:123',
                'runId' => '2-01JB2M8Q3W',
                'attempt' => null,
                'expiresAt' => $expiresAt,
                'signal' => null,
                'payload' => null,
                'event' => null,
                'body' => null,
            ]],
            array_map(static fn (Invocation $queued): array => $queued->toArray(), $this->queue->dispatched()),
        );
    }

    public function test_a_start_builds_no_workflow_and_touches_no_store(): void
    {
        $start = self::start(time() + 900);

        $this->endpoint()->handle('POST', self::signed($start), $start);

        self::assertSame([0, 0], [$this->workflowsBuilt, $this->storesOpened]);
    }

    public function test_a_ping_builds_no_workflow_and_touches_no_store(): void
    {
        $this->endpoint()->handle('POST', self::signed(self::PING), self::PING);

        self::assertSame([0, 0], [$this->workflowsBuilt, $this->storesOpened]);
    }

    public function test_a_start_of_a_version_this_deployment_lacks_is_not_dispatched(): void
    {
        $start = self::start(time() + 900, runId: '7-01JB2M8Q3W');

        $answer = $this->endpoint()->handle('POST', self::signed($start), $start);

        self::assertSame([404, ['code' => 'unknown_version']], [$answer->statusCode, self::fieldsOf($answer)]);
        self::assertSame([], $this->queue->dispatched());
    }

    public function test_an_inspect_of_a_run_of_a_version_this_deployment_lacks_reads_the_store_through_the_current_one(): void
    {
        $inspect = '{"protocol":1,"verb":"inspect","definition":"order-fulfilment","workflowId":"order:123","runId":"7-01JB2M8Q3W"}';

        $answer = $this->endpoint()->handle('POST', self::signed($inspect), $inspect);

        self::assertSame(200, $answer->statusCode, 'Every version of a definition shares one store.');
        self::assertSame([null, false, true], [self::fieldsOf($answer)['run'], self::fieldsOf($answer)['claimed'], self::fieldsOf($answer)['snapshotRead']]);
        self::assertSame(1, $this->workflowsBuilt);
    }

    /**
     * An endpoint that holds the signing key, with version 2 of "order-fulfilment" registered over an empty store.
     */
    protected function endpoint(): Endpoint
    {
        $store = new InMemoryPersistence();
        $persistence = function () use ($store): PersistenceInterface {
            $this->storesOpened++;

            return $store;
        };

        $registry = new Registry();
        $registry->register(new Definition('order-fulfilment', 2, function (): Workflow {
            $this->workflowsBuilt++;

            return new Workflow();
        }, 600));

        return new Endpoint(
            new Signature(self::SIGNING_KEY),
            $registry,
            new WorkflowBuilder($persistence),
            new Ledger($persistence),
            $this->queue,
            // No test of this class asks for a sync.
            static fn (): array => [],
        );
    }

    /**
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string} the headers the platform sends with a body
     */
    protected static function signed(string $body): array
    {
        $timestamp = time();

        return [
            'webhook-id' => self::WEBHOOK_ID,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (string) (new Signature(self::SIGNING_KEY))->sign(self::WEBHOOK_ID, $timestamp, $body),
        ];
    }

    protected static function start(int $expiresAt, string $runId = '2-01JB2M8Q3W'): string
    {
        return json_encode([
            'protocol' => 1,
            'verb' => 'start',
            'invocationId' => self::WEBHOOK_ID,
            'definition' => 'order-fulfilment',
            'workflowId' => 'order:123',
            'runId' => $runId,
            'expiresAt' => $expiresAt,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fieldsOf(HttpResponse $answer): array
    {
        return json_decode($answer->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
