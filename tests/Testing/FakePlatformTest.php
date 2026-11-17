<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Testing;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Console\Evaluation\EvaluationCommand;
use NeuronAI\Evaluation\Config\ConfigLoader;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Evaluation\CloudOutput;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Signature;
use NeuronCore\Cloud\Testing\FakePlatform;
use NeuronCore\Cloud\Tests\Stub\Datasets\RefundRequestsEvaluator;
use NeuronCore\Cloud\Tests\Stub\Endpoint\RecordingCloud;
use NeuronCore\Cloud\Tests\Stub\Evaluation\GreetingEvaluator;
use NeuronCore\Cloud\Tests\Stub\Evaluation\ProfileEvaluator;
use NeuronCore\Cloud\Tests\Stub\Tracing\Researcher;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use NeuronCore\Cloud\Tests\Support\Units\CatchesCloudExceptions;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_decode;
use function count;
use function json_decode;
use function ob_end_clean;
use function ob_start;
use function strlen;
use function substr;
use function time;

use const JSON_THROW_ON_ERROR;

class FakePlatformTest extends TestCase
{
    use CatchesCloudExceptions;

    protected const WORKFLOW_ID = 'order:123';

    protected const ITEMS = [
        ['dataset_item_id' => 11, 'input' => ['order' => 123], 'expected_output' => ['contains' => 'Order 123'], 'metadata' => ['source' => 'support']],
        ['dataset_item_id' => 12, 'input' => ['order' => 456], 'expected_output' => ['contains' => 'declined'], 'metadata' => null],
    ];

    #[DataProvider('paths')]
    public function test_an_announcement_or_a_report_is_answered_as_recorded(string $path): void
    {
        $answer = (new FakePlatform())->request(self::post($path));

        self::assertSame(200, $answer->statusCode);
        self::assertSame(['recorded' => true], $answer->json());
    }

    public function test_an_unknown_path_is_answered_404_and_thrown_as_the_clients_of_neuron_do(): void
    {
        $answer = self::answerOf(new FakePlatform(), self::post('/v1/runs'));

        self::assertSame(404, $answer->statusCode);
    }

    public function test_a_request_to_an_unknown_path_is_not_recorded(): void
    {
        $platform = new FakePlatform();
        self::answerOf($platform, self::post('/v2/announcements'));

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_fail_next_answers_the_next_request_with_503(): void
    {
        $platform = (new FakePlatform())->failNext();

        self::assertSame(503, self::answerOf($platform, self::announcement())->statusCode);
    }

    #[DataProvider('statuses')]
    public function test_fail_next_answers_with_the_status_it_is_given_and_the_code_that_goes_with_it(int $status, ?string $code): void
    {
        $platform = (new FakePlatform())->failNext(1, $status);

        $answer = self::answerOf($platform, self::announcement());

        self::assertSame($status, $answer->statusCode);
        self::assertSame(['code' => $code], $answer->json());
    }

    public function test_a_failure_of_400_and_above_is_thrown_with_its_answer_attached(): void
    {
        $platform = (new FakePlatform())->failNext(1, 500);

        try {
            $platform->request(self::announcement());
            self::fail('The answer should have been thrown.');
        } catch (HttpException $thrown) {
            self::assertSame(500, $thrown->response?->statusCode);
        }
    }

    public function test_a_failure_below_400_is_returned(): void
    {
        $platform = (new FakePlatform())->failNext(1, 302);

        self::assertSame(302, $platform->request(self::announcement())->statusCode);
    }

    #[DataProvider('thrownAndReturnedFailures')]
    public function test_a_request_fail_next_answered_is_not_recorded(int $status): void
    {
        $platform = (new FakePlatform())->failNext(1, $status);
        self::answerOf($platform, self::announcement());

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_fail_next_fails_as_many_requests_as_it_is_told_then_records_again(): void
    {
        $platform = (new FakePlatform())->failNext(2);

        $statuses = [
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::announcement())->statusCode,
        ];

        self::assertSame([503, 503, 200], $statuses);
    }

    public function test_failures_asked_for_one_after_the_other_are_answered_in_that_order(): void
    {
        $platform = (new FakePlatform())->failNext(1, 500)->failNext(1, 401);

        $statuses = [
            self::answerOf($platform, self::announcement())->statusCode,
            self::answerOf($platform, self::report())->statusCode,
            self::answerOf($platform, self::report())->statusCode,
        ];

        self::assertSame([500, 401, 200], $statuses);
    }

    public function test_assert_announced_passes_for_a_recorded_announcement_of_the_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement());

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_announced_passes_for_the_verb_that_was_announced(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement(verb: 'wake'));

        $platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
    }

    public function test_assert_announced_fails_when_nothing_was_announced(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement for workflow ID 'order:123'.");

        (new FakePlatform())->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_announced_fails_for_another_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement for workflow ID 'order:124'.");

        $platform->assertAnnounced('order:124');
    }

    public function test_assert_announced_fails_for_another_verb(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::announcement(verb: 'start'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no announcement of a 'wake' for workflow ID 'order:123'.");

        $platform->assertAnnounced(self::WORKFLOW_ID, Verb::Wake);
    }

    public function test_assert_announced_does_not_count_a_report(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report());

        $this->expectException(AssertionFailedError::class);

        $platform->assertAnnounced(self::WORKFLOW_ID);
    }

    public function test_assert_reported_passes_for_a_recorded_report_with_the_outcome(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused);
    }

    public function test_assert_reported_passes_for_the_outcome_and_the_code_that_were_reported(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, 'stale_attempt');
    }

    public function test_assert_reported_fails_when_nothing_was_reported(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:123'.");

        (new FakePlatform())->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_assert_reported_fails_for_another_outcome(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('suspended'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:123'.");

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_assert_reported_fails_for_another_code(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report('refused', 'stale_attempt'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'refused' report with the code 'stale_run' for workflow ID 'order:123'.");

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Refused, 'stale_run');
    }

    public function test_assert_reported_fails_for_another_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::report());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no 'completed' report for workflow ID 'order:124'.");

        $platform->assertReported('order:124', Outcome::Completed);
    }

    public function test_assert_reported_does_not_count_a_report_the_platform_did_not_record(): void
    {
        $platform = (new FakePlatform())->failNext();
        self::answerOf($platform, self::report());

        $this->expectException(AssertionFailedError::class);

        $platform->assertReported(self::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_the_signing_key_is_a_standard_webhooks_secret(): void
    {
        $signingKey = (new FakePlatform())->signingKey;

        self::assertStringStartsWith('whsec_', $signingKey);
        self::assertGreaterThanOrEqual(24, strlen((string) base64_decode(substr($signingKey, strlen('whsec_')), true)));
    }

    public function test_every_fake_platform_signs_with_a_key_of_its_own(): void
    {
        self::assertNotSame((new FakePlatform())->signingKey, (new FakePlatform())->signingKey);
    }

    public function test_the_platform_has_nothing_to_stream(): void
    {
        $this->expectException(HttpException::class);

        (new FakePlatform())->stream(self::announcement());
    }

    public function test_a_sync_is_answered_with_every_published_version_and_no_open_run(): void
    {
        $answer = (new FakePlatform())->request(self::sync([2, 1]));

        self::assertSame(200, $answer->statusCode);
        self::assertSame(
            [
                'protocol' => 1,
                'modified' => true,
                'versions' => [
                    ['definition' => 'order-fulfilment', 'version' => 2, 'openRuns' => 0],
                    ['definition' => 'order-fulfilment', 'version' => 1, 'openRuns' => 0],
                ],
                'stranded' => [],
            ],
            $answer->json(),
        );
        ProtocolSchema::assertValid('sync-answer', $answer->json());
    }

    public function test_the_same_sync_again_changes_nothing(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::sync([1]));

        self::assertFalse($platform->request(self::sync([1]))->json()['modified']);
    }

    public function test_a_sync_of_another_list_replaces_the_one_before(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::sync([1]));

        self::assertTrue($platform->request(self::sync([2, 1]))->json()['modified']);
        self::assertTrue($platform->request(self::sync([1]))->json()['modified']);
    }

    public function test_a_sync_fail_next_refused_published_nothing(): void
    {
        $platform = (new FakePlatform())->failNext(1, 422);
        self::answerOf($platform, self::sync([1]));

        self::assertTrue($platform->request(self::sync([1]))->json()['modified']);
    }

    public function test_assert_synced_passes_once_a_sync_was_accepted(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::sync([1]));

        $platform->assertSynced();
    }

    public function test_assert_synced_fails_when_nothing_was_published(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The platform accepted no sync.');

        (new FakePlatform())->assertSynced();
    }

    public function test_assert_synced_does_not_count_a_sync_the_platform_refused(): void
    {
        $platform = (new FakePlatform())->failNext(1, 422);
        self::answerOf($platform, self::sync([1]));

        $this->expectException(AssertionFailedError::class);

        $platform->assertSynced();
    }

    public function test_deliver_posts_the_message_to_the_endpoint_as_json_and_returns_its_answer(): void
    {
        $cloud = new RecordingCloud(new HttpResponse(200, '{"protocols":[1]}'));

        $answer = (new FakePlatform())->deliver($cloud, ['protocol' => 1, 'verb' => 'ping']);

        self::assertSame('{"protocols":[1]}', $answer->body);
        self::assertSame('POST', $cloud->handled()[0]['method']);
        self::assertSame(['protocol' => 1, 'verb' => 'ping'], self::bodyOf($cloud));
    }

    public function test_deliver_says_protocol_1_for_a_message_that_names_none(): void
    {
        $cloud = new RecordingCloud();

        (new FakePlatform())->deliver($cloud, ['verb' => 'ping']);

        self::assertSame(1, self::bodyOf($cloud)['protocol']);
    }

    public function test_deliver_keeps_the_protocol_a_message_names(): void
    {
        $cloud = new RecordingCloud();

        (new FakePlatform())->deliver($cloud, ['protocol' => 2, 'verb' => 'ping']);

        self::assertSame(2, self::bodyOf($cloud)['protocol']);
    }

    public function test_deliver_signs_the_body_with_the_signing_key_as_standard_webhooks_does(): void
    {
        $cloud = new RecordingCloud();
        $platform = new FakePlatform();
        $before = time();

        $platform->deliver($cloud, ['verb' => 'ping']);

        ['headers' => $headers, 'body' => $body] = $cloud->handled()[0];
        self::assertGreaterThanOrEqual($before, (int) $headers['webhook-timestamp']);
        self::assertTrue((new Signature($platform->signingKey))->verify(
            $headers['webhook-id'],
            $headers['webhook-timestamp'],
            $headers['webhook-signature'],
            $body,
            time(),
        ));
    }

    public function test_deliver_sends_the_invocation_id_of_a_message_as_its_webhook_id(): void
    {
        $cloud = new RecordingCloud();

        (new FakePlatform())->deliver($cloud, ['verb' => 'wake', 'invocationId' => 'inv_01JB7K4T9QW2']);

        self::assertSame('inv_01JB7K4T9QW2', $cloud->handled()[0]['headers']['webhook-id']);
    }

    public function test_deliver_gives_a_message_without_an_invocation_id_a_webhook_id_of_its_own(): void
    {
        $cloud = new RecordingCloud();
        $platform = new FakePlatform();

        $platform->deliver($cloud, ['verb' => 'ping']);
        $platform->deliver($cloud, ['verb' => 'ping']);

        [$first, $second] = $cloud->handled();
        self::assertNotSame('', $first['headers']['webhook-id']);
        self::assertNotSame($first['headers']['webhook-id'], $second['headers']['webhook-id']);
    }

    public function test_deliver_signs_at_the_timestamp_it_is_given(): void
    {
        $cloud = new RecordingCloud();
        $platform = new FakePlatform();

        $platform->deliver($cloud, ['verb' => 'ping'], 1791453603);

        ['headers' => $headers, 'body' => $body] = $cloud->handled()[0];
        self::assertSame('1791453603', $headers['webhook-timestamp']);
        self::assertTrue((new Signature($platform->signingKey))->verify(
            $headers['webhook-id'],
            '1791453603',
            $headers['webhook-signature'],
            $body,
            1791453603,
        ));
    }

    public function test_wake_sends_a_wake_fenced_with_the_run_of_the_last_report_recorded_for_the_workflow_id(): void
    {
        $cloud = new RecordingCloud();
        $platform = new FakePlatform();
        $platform->request(self::reportOf(self::WORKFLOW_ID, ['runId' => '2-0192f3a8', 'attempt' => 1]));
        $platform->request(self::reportOf(self::WORKFLOW_ID, ['runId' => '2-0192f3a8', 'attempt' => 2]));
        $platform->request(self::reportOf('order:124', ['runId' => '2-7c1e7d3a', 'attempt' => 5]));

        $platform->wake($cloud, self::WORKFLOW_ID);

        $wake = self::bodyOf($cloud);
        self::assertSame(
            ['wake', 'order-fulfilment', self::WORKFLOW_ID, '2-0192f3a8', 2],
            [$wake['verb'], $wake['definition'], $wake['workflowId'], $wake['runId'], $wake['attempt']],
        );
        self::assertSame($wake['invocationId'], $cloud->handled()[0]['headers']['webhook-id']);
        ProtocolSchema::assertValid('wake', $wake);
    }

    public function test_wake_does_not_fence_from_a_report_the_platform_did_not_record(): void
    {
        $cloud = new RecordingCloud();
        $platform = new FakePlatform();
        $platform->request(self::reportOf(self::WORKFLOW_ID, ['runId' => '2-0192f3a8', 'attempt' => 1]));
        $platform->failNext();
        self::answerOf($platform, self::reportOf(self::WORKFLOW_ID, ['runId' => '2-0192f3a8', 'attempt' => 2]));

        $platform->wake($cloud, self::WORKFLOW_ID);

        self::assertSame(1, self::bodyOf($cloud)['attempt']);
    }

    public function test_wake_fails_when_the_last_report_shows_no_run(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::reportOf(self::WORKFLOW_ID, ['runId' => '2-0192f3a8', 'attempt' => 1]));
        $platform->request(self::reportOf(self::WORKFLOW_ID, null));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no report that shows a run to wake for workflow ID 'order:123'");

        $platform->wake(new RecordingCloud(), self::WORKFLOW_ID);
    }

    public function test_wake_fails_when_nothing_was_reported_for_the_workflow_id(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::reportOf('order:124', ['runId' => '2-7c1e7d3a', 'attempt' => 5]));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform recorded no report that shows a run to wake for workflow ID 'order:123'");

        $platform->wake(new RecordingCloud(), self::WORKFLOW_ID);
    }

    public function test_assert_traced_passes_for_a_trace_whose_parentless_span_has_the_name(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::trace('invoke_agent SupportAgent'));

        $platform->assertTraced('invoke_agent SupportAgent');
    }

    public function test_assert_traced_passes_for_a_span_whose_parent_span_id_is_empty_as_otlp_allows(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::post('/v1/traces', ['resourceSpans' => [['scopeSpans' => [['spans' => [
            ['spanId' => '00f067aa0ba902b7', 'parentSpanId' => '', 'name' => 'invoke_agent SupportAgent'],
        ]]]]]]));

        $platform->assertTraced('invoke_agent SupportAgent');
    }

    public function test_assert_traced_fails_when_no_trace_was_taken_in(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform took in no trace whose parentless span is 'invoke_agent SupportAgent': it took in none.");

        (new FakePlatform())->assertTraced('invoke_agent SupportAgent');
    }

    public function test_assert_traced_fails_for_a_span_that_has_a_parent(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::trace('invoke_agent SupportAgent', 'chat openai'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            "The platform took in no trace whose parentless span is 'chat openai': the traces it took in have the parentless spans 'invoke_agent SupportAgent'.",
        );

        $platform->assertTraced('chat openai');
    }

    public function test_assert_traced_fails_for_another_parentless_span_and_names_those_it_took_in(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::trace('invoke_agent Researcher'));
        $platform->request(self::trace('invoke_workflow order-fulfilment'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            "The platform took in no trace whose parentless span is 'invoke_agent SupportAgent': "
            . "the traces it took in have the parentless spans 'invoke_agent Researcher', 'invoke_workflow order-fulfilment'.",
        );

        $platform->assertTraced('invoke_agent SupportAgent');
    }

    public function test_assert_traced_does_not_count_a_trace_the_platform_refused(): void
    {
        $platform = (new FakePlatform())->failNext();
        self::answerOf($platform, self::trace('invoke_agent SupportAgent'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('it took in none.');

        $platform->assertTraced('invoke_agent SupportAgent');
    }

    public function test_an_agent_run_with_the_tracer_of_a_service_given_the_fake_passes_assert_traced(): void
    {
        $platform = new FakePlatform();
        $cloud = self::serviceOn($platform)->enableTracing();

        Researcher::make()
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Rome is sunny.')))
            ->subscribe(ObservabilityEvent::class, $cloud->tracer())
            ->for('thread:1')
            ->chat(new UserMessage('What is the weather in Rome?'));

        $platform->assertTraced('invoke_agent Researcher');
    }

    public function test_assert_evaluated_passes_for_the_results_of_the_evaluator(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::evaluation(GreetingEvaluator::class));

        $platform->assertEvaluated(GreetingEvaluator::class);
    }

    public function test_assert_evaluated_fails_when_no_evaluation_was_taken_in(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("The platform took in no evaluation of '" . GreetingEvaluator::class . "': it took in none.");

        (new FakePlatform())->assertEvaluated(GreetingEvaluator::class);
    }

    public function test_assert_evaluated_fails_for_another_evaluator_and_names_those_it_took_in(): void
    {
        $platform = new FakePlatform();
        $platform->request(self::evaluation(ProfileEvaluator::class));
        $platform->request(self::evaluation(RefundRequestsEvaluator::class));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            "The platform took in no evaluation of '" . GreetingEvaluator::class . "': "
            . "it took in the evaluations of '" . ProfileEvaluator::class . "', '" . RefundRequestsEvaluator::class . "'.",
        );

        $platform->assertEvaluated(GreetingEvaluator::class);
    }

    public function test_assert_evaluated_does_not_count_an_evaluation_the_platform_refused(): void
    {
        $platform = (new FakePlatform())->failNext();
        self::answerOf($platform, self::evaluation(GreetingEvaluator::class));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('it took in none.');

        $platform->assertEvaluated(GreetingEvaluator::class);
    }

    /**
     * Neuron's evaluation command runs every evaluator of a directory, then hands the report to the outputs
     * evaluation.php lists: here the output of a service given the fake.
     */
    public function test_the_results_neurons_evaluation_command_sends_through_the_output_of_a_service_given_the_fake_pass_assert_evaluated(): void
    {
        $platform = new FakePlatform();
        $command = new EvaluationCommand(self::configListing(self::serviceOn($platform)->evaluationOutput()));

        ob_start();
        $command->run(['neuron', __DIR__ . '/../Stub/Evaluation']);
        ob_end_clean();

        $platform->assertEvaluated(GreetingEvaluator::class);
        $platform->assertEvaluated(ProfileEvaluator::class);
    }

    public function test_a_dataset_it_keeps_is_answered_as_the_platform_answers_it(): void
    {
        $platform = (new FakePlatform())->keepDataset('refund-requests', self::ITEMS);

        $answer = $platform->request(HttpRequest::get('https://platform.test/v1/datasets/refund-requests'));

        self::assertSame(200, $answer->statusCode);
        self::assertSame(
            ['slug' => 'refund-requests', 'name' => 'refund-requests', 'description' => null, 'items' => self::ITEMS],
            $answer->json(),
        );
    }

    /**
     * The platform answers an item with its ID, its input, its expected output and its metadata, the last two null
     * when it holds none, and nothing else: an evaluator reads from the fake the keys it reads from the platform, no
     * more and no fewer.
     */
    public function test_an_item_is_answered_with_its_id_its_input_its_expected_output_and_its_metadata_alone_a_missing_id_as_its_position_and_a_missing_field_as_null(): void
    {
        $platform = (new FakePlatform())->keepDataset('refund-requests', [
            ['input' => ['order' => 123]],
            ['input' => ['order' => 456], 'expected_output' => ['contains' => 'declined'], 'metadata' => ['source' => 'support'], 'status' => 'archived'],
        ]);

        self::assertSame(
            [
                ['dataset_item_id' => 1, 'input' => ['order' => 123], 'expected_output' => null, 'metadata' => null],
                ['dataset_item_id' => 2, 'input' => ['order' => 456], 'expected_output' => ['contains' => 'declined'], 'metadata' => ['source' => 'support']],
            ],
            self::serviceOn($platform)->dataset('refund-requests')->load(),
        );
    }

    /**
     * The platform answers the items as a list: keyed items, such as those array_filter() leaves, are not a dataset
     * load() would refuse.
     */
    public function test_items_it_is_given_with_keys_are_answered_as_a_list_in_their_order(): void
    {
        $platform = (new FakePlatform())->keepDataset('refund-requests', [3 => self::ITEMS[0], 7 => self::ITEMS[1]]);

        self::assertSame(self::ITEMS, self::serviceOn($platform)->dataset('refund-requests')->load());
    }

    public function test_the_id_of_an_item_it_is_given_without_one_is_its_position_in_the_list_and_not_its_key(): void
    {
        $platform = (new FakePlatform())->keepDataset('refund-requests', [3 => ['input' => 'a'], 7 => ['input' => 'b']]);

        self::assertSame(
            [
                ['dataset_item_id' => 1, 'input' => 'a', 'expected_output' => null, 'metadata' => null],
                ['dataset_item_id' => 2, 'input' => 'b', 'expected_output' => null, 'metadata' => null],
            ],
            self::serviceOn($platform)->dataset('refund-requests')->load(),
        );
    }

    public function test_a_slug_it_does_not_keep_is_answered_404(): void
    {
        $platform = (new FakePlatform())->keepDataset('refund-requests', self::ITEMS);

        $answer = self::answerOf($platform, HttpRequest::get('https://platform.test/v1/datasets/refund-reviews'));

        self::assertSame([404, ['code' => null]], [$answer->statusCode, $answer->json()]);
    }

    public function test_a_dataset_is_found_under_the_slug_the_sdk_percent_encodes(): void
    {
        $platform = (new FakePlatform())->keepDataset('refunds/2026 Q3', self::ITEMS);

        self::assertSame(self::ITEMS, self::serviceOn($platform)->dataset('refunds/2026 Q3')->load());
    }

    public function test_neurons_runner_runs_an_evaluator_on_a_dataset_of_a_service_given_the_fake_on_the_items_it_keeps_in_order(): void
    {
        $platform = (new FakePlatform())->keepDataset(RefundRequestsEvaluator::DATASET, self::ITEMS);

        $results = (new EvaluatorRunner())->run(new RefundRequestsEvaluator(self::serviceOn($platform)));

        self::assertSame(
            [[self::ITEMS[0], true], [self::ITEMS[1], false]],
            array_map(static fn (EvaluatorResult $result): array => [$result->getInput(), $result->isPassed()], $results->getResults()),
        );
    }

    public function test_fail_next_fails_the_read_of_a_dataset_it_keeps_and_the_read_after_it_is_answered(): void
    {
        $platform = (new FakePlatform())->keepDataset(RefundRequestsEvaluator::DATASET, self::ITEMS)->failNext();
        $dataset = self::serviceOn($platform)->dataset(RefundRequestsEvaluator::DATASET);

        self::assertSame(Code::ReportUndelivered, self::reasonThrownBy(static fn (): array => $dataset->load()));
        self::assertSame(self::ITEMS, $dataset->load());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            'announcements' => ['/v1/announcements'],
            'reports' => ['/v1/reports'],
        ];
    }

    /**
     * @return array<string, array{int, string|null}> a status, and the code the platform gives with it
     */
    public static function statuses(): array
    {
        return [
            'a 400' => [400, 'invalid_request'],
            'a 401' => [401, 'unauthorized'],
            'a 403' => [403, 'unauthorized'],
            'a 404' => [404, null],
            'a 422' => [422, 'endpoint_unverified'],
            'a 429' => [429, null],
            'a 500' => [500, null],
            'a 503' => [503, null],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function thrownAndReturnedFailures(): array
    {
        return [
            'a 503, which is thrown' => [503],
            'a 302, which is returned' => [302],
        ];
    }

    /**
     * What the platform answered, whether its client returned the answer or threw it.
     */
    protected static function answerOf(FakePlatform $platform, HttpRequest $request): HttpResponse
    {
        try {
            return $platform->request($request);
        } catch (HttpException $thrown) {
            return $thrown->response ?? throw $thrown;
        }
    }

    protected static function announcement(string $verb = 'start'): HttpRequest
    {
        return self::post('/v1/announcements', ['workflowId' => self::WORKFLOW_ID, 'verb' => $verb]);
    }

    protected static function report(string $outcome = 'completed', ?string $code = null): HttpRequest
    {
        return self::post('/v1/reports', ['workflowId' => self::WORKFLOW_ID, 'outcome' => $outcome, 'code' => $code]);
    }

    /**
     * A report as the SDK posts it after a segment, with the run the store showed.
     *
     * @param array<string, mixed>|null $run null: no run holds the workflow ID
     */
    protected static function reportOf(string $workflowId, ?array $run): HttpRequest
    {
        return self::post('/v1/reports', [
            'definition' => 'order-fulfilment',
            'workflowId' => $workflowId,
            'outcome' => $run === null ? 'completed' : 'suspended',
            'code' => null,
            'run' => $run,
        ]);
    }

    /**
     * An OTLP export of one trace, as the SDK posts it: a parentless span and a span under it.
     */
    protected static function trace(string $root, string $child = 'chat fake'): HttpRequest
    {
        return self::post('/v1/traces', ['resourceSpans' => [['scopeSpans' => [['spans' => [
            ['spanId' => 'b7ad6b7169203331', 'parentSpanId' => '00f067aa0ba902b7', 'name' => $child],
            ['spanId' => '00f067aa0ba902b7', 'name' => $root],
        ]]]]]]);
    }

    /**
     * The results of an evaluator, as the evaluation output posts them, with no item.
     */
    protected static function evaluation(string $evaluator): HttpRequest
    {
        return self::post('/v1/evaluations', ['run_id' => 'eval_1', 'name' => $evaluator, 'error' => null, 'results' => []]);
    }

    /**
     * An evaluation.php that lists one output.
     */
    protected static function configListing(CloudOutput $output): ConfigLoader
    {
        return new class ($output) extends ConfigLoader {
            public function __construct(protected CloudOutput $output)
            {
            }

            public function load(): array
            {
                return ['output' => [$this->output]];
            }
        };
    }

    protected static function serviceOn(FakePlatform $platform): NeuronCloud
    {
        return new NeuronCloud('test-api-key', httpClient: $platform);
    }

    /**
     * @param int[] $versions the registered versions of "order-fulfilment"
     */
    protected static function sync(array $versions): HttpRequest
    {
        return self::post('/v1/sync', [
            'protocols' => [1],
            'sdk' => 'neuron-cloud-php/1.0.0',
            'endpoint' => 'https://shop.example.com/neuron-cloud.php',
            'definitions' => array_map(
                static fn (int $version): array => ['definition' => 'order-fulfilment', 'version' => $version, 'leaseSeconds' => 600],
                $versions,
            ),
        ]);
    }

    /**
     * @return array<string, mixed> the body of the last request the endpoint was handed
     */
    protected static function bodyOf(RecordingCloud $cloud): array
    {
        $handled = $cloud->handled();

        return json_decode($handled[count($handled) - 1]['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected static function post(string $path, array $body = []): HttpRequest
    {
        return HttpRequest::post('https://platform.test' . $path, $body);
    }
}
