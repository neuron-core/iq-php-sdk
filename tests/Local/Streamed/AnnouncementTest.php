<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_column;
use function time;

/**
 * Section 9.6 of the design: with events(), the announcement is handed to the
 * dispatcher before the generator is returned. It is what lets the platform
 * find the run when a browser aborts so hard that nothing else is ever said.
 */
class AnnouncementTest extends StreamedTestCase
{
    /** The format of an invocation ID in appendix A of the design. */
    protected const INVOCATION_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    /** The format of a run ID minted by the SDK or the platform in appendix A of the design. */
    protected const MINTED_RUN_ID = '/^[1-9][0-9]{0,5}-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    #[DataProvider('kinds')]
    public function test_the_announcement_and_nothing_else_is_in_the_queue_when_events_returns(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        $events = $stage->stream();

        self::assertSame([Verb::Announce], $stage->verbsQueued());
        self::assertTrue($events->valid(), 'The stream had nothing to give once it was taken.');
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_is_handed_over_before_anything_is_asked_of_the_store_for_the_segment(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $operationsWhenPrepared = $stage->journal->exposedTo(FaultMode::BeforeOperation);
        $operationsWhenAnnounced = null;
        $stage->queue->beforeTaking(static function (Invocation $handed) use ($stage, &$operationsWhenAnnounced): void {
            if ($handed->verb === Verb::Announce) {
                $operationsWhenAnnounced = $stage->journal->exposedTo(FaultMode::BeforeOperation);
            }
        });

        $stage->take($segment->events());

        self::assertSame($operationsWhenPrepared, $operationsWhenAnnounced);
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_tells_the_platform_which_segment_starts_and_under_which_lease(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $held = $stage->stored();
        $calledAt = time();

        $taken = $stage->take($stage->stream());

        $announcement = $stage->announce()->body ?? [];
        self::assertSame([
            'protocol' => 1,
            'invocationId' => $announcement['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $taken),
            'attempt' => $held['attempt'] ?? null,
            'leaseSeconds' => Stage::LEASE,
            'at' => $announcement['at'] ?? null,
        ], $announcement);
        self::assertMatchesRegularExpression(self::INVOCATION_ID, $announcement['invocationId']);
        self::assertGreaterThanOrEqual($calledAt, $announcement['at']);
        self::assertLessThanOrEqual(time(), $announcement['at']);
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_of_a_stream_matches_the_schema_of_the_protocol(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        $stage->stream();

        self::assertSame([], ProtocolSchema::violations('announcement', $stage->announce()->body ?? []));
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_travels_as_a_message_of_the_application_about_the_run_and_carries_no_input(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        $taken = $stage->take($stage->stream());

        $announce = $stage->announce();
        self::assertSame([
            'invocationId' => $announce->invocationId,
            'verb' => Verb::Announce->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $taken),
            'attempt' => null,
            'expiresAt' => null,
            'signal' => null,
            'payload' => null,
            'event' => null,
            'body' => $announce->body,
        ], $announce->toArray());
        self::assertNotSame($announce->body['invocationId'] ?? null, $announce->invocationId, 'A notice is a message of its own.');
    }

    #[DataProvider('backends')]
    public function test_a_start_is_announced_under_a_run_id_minted_on_the_current_version(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);

        $stage->stream();

        self::assertMatchesRegularExpression(self::MINTED_RUN_ID, $stage->announce()->runId);
        self::assertStringStartsWith('1-', $stage->announce()->runId);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_a_stream_is_announced_once_however_it_ends(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $stage->take($stage->stream());
        $stage->drain();

        self::assertSame([self::ANNOUNCEMENTS, self::REPORTS], array_column($stage->requests(), 0));
    }
}
