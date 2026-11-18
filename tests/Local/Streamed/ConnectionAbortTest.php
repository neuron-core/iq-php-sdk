<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Tests\Support\Streamed\StreamingEndpoint;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;

/**
 * Section 9.6 of the design and row 15 of section 7: a browser that aborts
 * hard. PHP ends the request when a write to the closed connection fails and
 * runs no finally block, so the SDK queues no observe. It still destroys what
 * the request held, and Neuron fails the run in its own destructor: the
 * store says "failed" at once, with no lease left to wait out. The
 * announcement is the only word left for the platform, which asks when the
 * lease it was announced runs out and then sends its wake.
 *
 * Only a real connection shows this: the application runs on PHP's built-in
 * server, and the test is the browser.
 */
class ConnectionAbortTest extends TestCase
{
    protected StreamingEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->endpoint = new StreamingEndpoint();
    }

    protected function tearDown(): void
    {
        $this->endpoint->stop();
    }

    public function test_a_browser_that_stays_receives_every_word_and_the_request_queues_its_observe(): void
    {
        $read = $this->endpoint->readToTheEnd();

        self::assertStringContainsString('data: ' . implode("\n\ndata: ", [...TaleWorkflow::OPENING, ...TaleWorkflow::CLOSING]) . "\n\n", $read);
        self::assertSame([Verb::Announce, Verb::Observe], $this->verbsQueued());
        self::assertSame(Outcome::Completed->value, $this->endpoint->queued()[1]->body['outcome'] ?? null);
    }

    public function test_neuron_fails_the_run_of_a_request_whose_browser_closed_the_connection(): void
    {
        $this->endpoint->leaveAfterTheFirstWord();

        $run = $this->endpoint->stored();
        self::assertSame(['failed', 1, null], [$run['status'] ?? null, $run['attempt'] ?? null, $run['leaseExpiresAt'] ?? null]);
    }

    public function test_a_request_whose_browser_closed_the_connection_queues_no_observe(): void
    {
        $this->endpoint->leaveAfterTheFirstWord();

        self::assertSame([Verb::Announce], $this->verbsQueued());
    }

    public function test_the_announcement_an_aborted_request_left_in_the_queue_names_the_failed_run_and_the_lease_to_wait_out(): void
    {
        $this->endpoint->leaveAfterTheFirstWord();

        $announcement = $this->endpoint->queued()[0]->body ?? [];
        self::assertSame(
            [Verb::Start->value, $this->endpoint->stored()['runId'] ?? null, StreamingEndpoint::LEASE],
            [$announcement['verb'] ?? null, $announcement['runId'] ?? null, $announcement['leaseSeconds'] ?? null],
        );
    }

    public function test_the_wake_of_the_platform_finishes_the_run_of_an_aborted_request(): void
    {
        $this->endpoint->leaveAfterTheFirstWord();
        $failed = $this->endpoint->stored();

        $report = $this->endpoint->worker->invoke(new Invocation(
            'inv_wake',
            Verb::Wake,
            Origin::PLATFORM,
            StreamingEndpoint::DEFINITION,
            StreamingEndpoint::WORKFLOW_ID,
            (string) ($failed['runId'] ?? ''),
            (int) ($failed['attempt'] ?? 0),
        ));

        self::assertSame([Outcome::Completed, 2], [$report->outcome, $report->executedAttempt]);
        self::assertNull($this->endpoint->stored());
    }

    /**
     * @return Verb[] the kind of every message that waits in the queue of the application, in order
     */
    protected function verbsQueued(): array
    {
        return array_map(static fn (Invocation $message): Verb => $message->verb, $this->endpoint->queued());
    }
}
