<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Testing\FakeDispatcher;
use NeuronCore\IQ\Tests\Stub\CompleteNode;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\FlakyNode;
use NeuronCore\IQ\Tests\Stub\FlakyWorkflow;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use NeuronCore\IQ\Tests\Support\Units\LocalPlatform;
use NeuronCore\IQ\Tests\Support\Units\Queued;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

use function array_column;
use function array_map;
use function json_decode;
use function microtime;
use function parse_url;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

class IQTest extends IQTestCase
{
    use CatchesIQExceptions;

    protected const LOCAL_API_KEY = 'local-api-key';

    protected LocalPlatform $localPlatform;

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->localPlatform)) {
            $this->localPlatform->stop();
        }
    }

    public function test_invoke_before_a_persistence_is_set_is_refused_as_not_configured(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        $reason = self::reasonThrownBy(static fn (): Report => $iq->invoke(Queued::start('greeting')));

        self::assertSame(Code::NotConfigured, $reason);
    }

    public function test_register_returns_the_instance_it_was_called_on(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        self::assertSame($iq, $iq->register('greeting', static fn (): Workflow => new Workflow()));
    }

    public function test_set_persistence_returns_the_instance_it_was_called_on(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        self::assertSame($iq, $iq->setPersistence(static fn (): PersistenceInterface => new InMemoryPersistence()));
    }

    public function test_set_dispatcher_returns_the_instance_it_was_called_on(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        self::assertSame($iq, $iq->setDispatcher(new FakeDispatcher()));
    }

    public function test_set_endpoint_url_returns_the_instance_it_was_called_on(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        self::assertSame($iq, $iq->setEndpointUrl('https://shop.example.com/iq'));
    }

    public function test_registering_a_version_again_replaces_it(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $bench->iq
            ->register('greeting', static fn (): Workflow => new FlakyWorkflow($executions))
            ->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions));

        $report = $bench->iq->invoke(Queued::start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(0, $executions->count(FlakyNode::class));
    }

    public function test_a_run_executes_on_the_version_its_run_id_pins_not_on_the_highest(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $bench->iq
            ->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions), version: 1)
            ->register('greeting', static fn (): Workflow => new FlakyWorkflow($executions), version: 2);

        $bench->iq->invoke(Queued::start('greeting', runId: '1-0192f3a8'));

        self::assertSame(1, $executions->count(CompleteNode::class));
        self::assertSame(0, $executions->count(FlakyNode::class));
    }

    public function test_the_registered_definitions_are_listed_with_the_lease_of_each_version(): void
    {
        $factory = static fn (): Workflow => new Workflow();
        $iq = (new IQ(TestBench::API_KEY))
            ->register('order-fulfilment', $factory, leaseTimeout: 120)
            ->register('support-agent', $factory, version: 1, leaseTimeout: 60)
            ->register('support-agent', $factory, version: 2);

        self::assertSame(['order-fulfilment' => [1 => 120], 'support-agent' => [1 => 60, 2 => 600]], $iq->definitions());
    }

    public function test_no_definition_is_listed_before_one_is_registered(): void
    {
        self::assertSame([], (new IQ(TestBench::API_KEY))->definitions());
    }

    public function test_a_version_registered_again_is_listed_once_with_the_lease_it_was_given_last(): void
    {
        $factory = static fn (): Workflow => new Workflow();
        $iq = (new IQ(TestBench::API_KEY))
            ->register('order-fulfilment', $factory, leaseTimeout: 120)
            ->register('order-fulfilment', $factory, leaseTimeout: 900);

        self::assertSame(['order-fulfilment' => [1 => 900]], $iq->definitions());
    }

    public function test_a_registration_the_sdk_refuses_is_not_listed(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        self::reasonThrownBy(static fn (): IQ => $iq->register('Order Fulfilment', static fn (): Workflow => new Workflow()));

        self::assertSame([], $iq->definitions());
    }

    public function test_register_refuses_a_name_the_protocol_cannot_carry(): void
    {
        $iq = new IQ(TestBench::API_KEY);

        $reason = self::reasonThrownBy(static fn (): IQ => $iq->register('Order Fulfilment', static fn (): Workflow => new Workflow()));

        self::assertSame(Code::InvalidRequest, $reason);
    }

    public function test_a_segment_is_announced_with_the_lease_of_its_registration(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $executions = $bench->executions;
        $bench->iq->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions), leaseTimeout: 90);

        $bench->iq->invoke(Queued::start('greeting'));

        self::assertSame(90, $bench->platform->received()[0]->body['leaseSeconds'] ?? null);
    }

    public function test_a_registration_that_names_no_lease_gets_ten_minutes(): void
    {
        $bench = $this->benchWithGreeting();

        $bench->iq->invoke(Queued::start('greeting'));

        self::assertSame(600, $bench->platform->received()[0]->body['leaseSeconds'] ?? null);
    }

    public function test_the_platform_url_defaults_to_iq(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $iq = $this->withGreeting(new IQ(TestBench::API_KEY, httpClient: $bench->platform), $bench);

        $iq->invoke(Queued::start('greeting'));

        self::assertSame('https://app.neuron-iq.dev/v1/announcements', $bench->platform->received()[0]->uri);
    }

    public function test_with_no_dispatcher_an_announcement_the_platform_did_not_record_is_posted_again_in_place(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(1);

        $report = $this->withoutDispatcher($bench)->invoke(Queued::start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(
            ['/v1/announcements', '/v1/announcements', '/v1/reports'],
            array_map(static fn (HttpRequest $request): string => (string) parse_url($request->uri, PHP_URL_PATH), $bench->platform->received()),
        );
        $bench->platform->assertAnnounced(Queued::WORKFLOW_ID, Verb::Start);
    }

    public function test_with_no_dispatcher_the_announcement_posted_again_is_the_one_that_was_prepared(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(1);

        $this->withoutDispatcher($bench)->invoke(Queued::start('greeting'));

        [$refused, $recorded] = $bench->platform->received();
        self::assertSame($refused->body, $recorded->body);
    }

    public function test_with_no_dispatcher_a_report_the_platform_did_not_record_is_followed_by_an_observe_in_place(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(3);

        $report = $this->withoutDispatcher($bench)->invoke(Queued::start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertCount(4, $bench->platform->received(), 'Two announcements and a report were refused, then the observe posted its report.');
        $bench->platform->assertReported(Queued::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_with_no_dispatcher_a_platform_that_stays_down_does_not_stop_a_segment_it_ordered(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(4);

        $report = $this->withoutDispatcher($bench)->invoke(Queued::start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertTrue($report->claimed);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_with_no_dispatcher_a_segment_of_the_application_that_cannot_be_announced_is_aborted_before_it_runs(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(2);
        $iq = $this->withoutDispatcher($bench);

        $reason = self::reasonThrownBy(static fn (): Report => $iq->invoke(Queued::start('greeting', Origin::APPLICATION)));

        self::assertSame(Code::AnnounceFailed, $reason);
        self::assertSame(0, $bench->executions->total());
        $bench->platform->assertReported(Queued::WORKFLOW_ID, Outcome::Aborted, Code::AnnounceFailed->value);
    }

    public function test_with_no_dispatcher_a_start_that_was_aborted_unannounced_runs_when_it_is_invoked_again(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(2);
        $iq = $this->withoutDispatcher($bench);
        self::thrownBy(static fn (): Report => $iq->invoke(Queued::start('greeting', Origin::APPLICATION)));

        $report = $iq->invoke(Queued::start('greeting', Origin::APPLICATION));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_workflow_before_a_persistence_is_set_is_refused_as_not_configured(): void
    {
        $iq = (new IQ(TestBench::API_KEY))->register('greeting', static fn (): Workflow => new Workflow());

        $reason = self::reasonThrownBy(static fn (): ManagedWorkflow => $iq->workflow('greeting', Queued::WORKFLOW_ID));

        self::assertSame(Code::NotConfigured, $reason);
    }

    public function test_workflow_binds_a_registered_definition_to_the_workflow_id_it_is_given(): void
    {
        $bench = $this->benchWithGreeting();

        $state = $bench->iq->workflow('greeting', 'order:456')->start()->run();

        self::assertSame('order:456', $state->getWorkflowId());
        self::assertSame(1, $bench->executions->count(CompleteNode::class));
    }

    public function test_with_a_dispatcher_set_a_local_segment_reaches_the_platform_through_the_queue_only(): void
    {
        $bench = $this->benchWithGreeting();

        $bench->iq->workflow('greeting', Queued::WORKFLOW_ID)->start()->run();

        self::assertSame([], $bench->platform->received());
        $bench->dispatcher->assertDispatched(Verb::Announce, Queued::WORKFLOW_ID);
        $bench->dispatcher->assertDispatched(Verb::Observe, Queued::WORKFLOW_ID);
    }

    public function test_with_no_dispatcher_a_local_segment_is_announced_and_reported_in_place(): void
    {
        $bench = $this->benchWithGreeting();

        $state = $this->withoutDispatcher($bench)->workflow('greeting', Queued::WORKFLOW_ID)->start()->run();

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame(
            ['/v1/announcements', '/v1/reports'],
            array_map(static fn (HttpRequest $request): string => (string) parse_url($request->uri, PHP_URL_PATH), $bench->platform->received()),
        );
        $bench->platform->assertAnnounced(Queued::WORKFLOW_ID, Verb::Start);
        $bench->platform->assertReported(Queued::WORKFLOW_ID, Outcome::Completed);
    }

    public function test_with_no_dispatcher_a_local_segment_that_cannot_be_announced_fails_as_dispatch_failed_before_it_runs(): void
    {
        $bench = $this->benchWithGreeting();
        $bench->platform->failNext(1);
        $start = $this->withoutDispatcher($bench)->workflow('greeting', Queued::WORKFLOW_ID)->start();

        $thrown = self::thrownBy(static fn (): WorkflowState => $start->run());

        self::assertSame(Code::DispatchFailed, $thrown?->reason);
        self::assertSame(Code::ReportUndelivered, $thrown->getPrevious() instanceof IQException ? $thrown->getPrevious()->reason : null);
        self::assertSame(0, $bench->executions->total());
    }

    public function test_with_no_dispatcher_a_dispatched_start_runs_in_place(): void
    {
        $bench = $this->benchWithGreeting();

        $runId = $this->withoutDispatcher($bench)->workflow('greeting', Queued::WORKFLOW_ID)->start()->dispatch();

        self::assertSame(1, $bench->executions->count(CompleteNode::class));
        self::assertSame($runId, $bench->platform->received()[1]->body['runId'] ?? null);
        $bench->platform->assertReported(Queued::WORKFLOW_ID, Outcome::Completed);
    }

    #[RequiresPhpExtension('curl')]
    public function test_the_default_http_client_follows_no_redirect(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $dispatcher = new FakeDispatcher();
        $iq = $this->onLocalPlatform($platform->url(LocalPlatform::MOVED))->setDispatcher($dispatcher);

        $report = $iq->invoke(Queued::start('greeting'));

        self::assertSame(Outcome::Completed, $report->outcome);
        self::assertSame(
            ['/moved/v1/announcements', '/moved/v1/reports'],
            array_column($platform->requests(), 'path'),
            'A client that followed the redirect would have reached /v1/announcements, where it is recorded.',
        );
        $dispatcher->assertDispatched(Verb::Announce, Queued::WORKFLOW_ID);
        $dispatcher->assertDispatched(Verb::Observe, Queued::WORKFLOW_ID);
    }

    #[RequiresPhpExtension('curl')]
    public function test_the_default_http_client_posts_json_with_the_api_key_and_the_name_of_the_sdk(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $iq = $this->onLocalPlatform($platform->url());

        $iq->invoke(Queued::start('greeting'));

        $announcement = $platform->requests()[0];
        self::assertSame('POST', $announcement['method']);
        self::assertSame('/v1/announcements', $announcement['path']);
        self::assertSame('Bearer ' . self::LOCAL_API_KEY, $announcement['headers']['Authorization']);
        self::assertMatchesRegularExpression('~^iq-php/\d+\.\d+\.\d+$~D', $announcement['headers']['User-Agent']);
        self::assertSame('application/json', $announcement['headers']['Content-Type']);
    }

    #[RequiresPhpExtension('curl')]
    public function test_the_default_http_client_sends_the_report_as_the_body_of_its_request(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $iq = $this->onLocalPlatform($platform->url());

        $report = $iq->invoke(Queued::start('greeting'));

        $sent = $platform->requests()[1];
        self::assertSame('/v1/reports', $sent['path']);
        self::assertSame($report->toArray(), json_decode($sent['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * It waits the three seconds the SDK gives the platform: nothing shorter proves them.
     */
    #[RequiresPhpExtension('curl')]
    public function test_the_default_http_client_gives_the_platform_three_seconds_to_answer(): void
    {
        $platform = $this->localPlatform = new LocalPlatform();
        $iq = $this->onLocalPlatform($platform->url(LocalPlatform::SILENT));
        $askedAt = microtime(true);

        $reason = self::reasonThrownBy(static fn (): Report => $iq->invoke(Queued::announcementOf(Queued::start('greeting'))));

        $waited = microtime(true) - $askedAt;
        self::assertSame(Code::ReportUndelivered, $reason);
        self::assertGreaterThanOrEqual(2.9, $waited);
        self::assertLessThan(5.0, $waited, 'The client waited for its own default of five minutes, or for the connection.');
    }

    /**
     * A bench whose "greeting" completes in one segment.
     */
    protected function benchWithGreeting(): TestBench
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $this->withGreeting($bench->iq, $bench);

        return $bench;
    }

    protected function withGreeting(IQ $iq, TestBench $bench): IQ
    {
        $executions = $bench->executions;

        return $iq
            ->setPersistence($bench->persistence)
            ->register('greeting', static fn (): Workflow => new CompletingWorkflow($executions));
    }

    /**
     * An IQ on the store and the platform of the bench, with no dispatcher set.
     */
    protected function withoutDispatcher(TestBench $bench): IQ
    {
        return $this->withGreeting(new IQ(TestBench::API_KEY, null, TestBench::PLATFORM_URL, $bench->platform), $bench);
    }

    /**
     * An IQ that is given no HTTP client, so it builds its own, and reaches a platform on this machine.
     */
    protected function onLocalPlatform(string $platformUrl): IQ
    {
        return $this->withGreeting(
            new IQ(self::LOCAL_API_KEY, platformUrl: $platformUrl),
            $this->benchOn(TestBench::IN_MEMORY),
        );
    }
}
