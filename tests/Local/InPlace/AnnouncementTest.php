<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;
use function usleep;

/**
 * The first of the two notices a segment run in the calling process leaves in
 * the queue: "a segment of this run is starting; its lease is that long".
 */
class AnnouncementTest extends InPlaceTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_the_announcement_travels_as_a_message_of_the_application_about_the_run_and_carries_no_input(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);

        $played = $stage->play();

        $announce = $played->announce();
        self::assertSame([
            'invocationId' => $announce->invocationId,
            'verb' => Verb::Announce->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $played),
            'attempt' => null,
            'expiresAt' => null,
            'signal' => null,
            'payload' => null,
            'event' => null,
            'body' => $announce->body,
        ], $announce->toArray());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_announcement_names_the_segment_field_by_field(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $attemptTheStoreShowed = $stage->stored()['attempt'] ?? null;

        $played = $stage->play();

        $announcement = $played->announce()->body ?? [];
        self::assertSame([
            'protocol' => 1,
            'invocationId' => $played->observe()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $played),
            'attempt' => $attemptTheStoreShowed,
            'leaseSeconds' => Stage::LEASE,
            'at' => $announcement['at'] ?? null,
        ], $announcement);
    }

    #[DataProvider('refusals')]
    public function test_the_announcement_of_a_segment_neuron_then_refuses_names_it_field_by_field(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $runToContinue = $stage->stored();

        $played = $stage->playOvertaken();

        $announcement = $played->announce()->body ?? [];
        $reserved = $played->thrown instanceof RunInFlightException ? $played->thrown->reservedRunId : null;
        self::assertSame([
            'protocol' => 1,
            'invocationId' => $played->observe()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => $runToContinue['runId'] ?? $reserved,
            'attempt' => $runToContinue['attempt'] ?? null,
            'leaseSeconds' => Stage::LEASE,
            'at' => $announcement['at'] ?? null,
        ], $announcement);
    }

    #[DataProvider('kinds')]
    public function test_the_announcement_of_a_start_carries_no_attempt_and_that_of_a_continuation_the_attempt_it_is_fenced_with(
        string $backend,
        Kind $kind,
    ): void {
        $played = $this->stage($backend, $kind)->play();

        self::assertSame($kind->continuesARun() ? 1 : null, $played->announce()->body['attempt'] ?? null);
    }

    #[DataProvider('preparedKinds')]
    public function test_the_announcement_is_stamped_when_the_segment_runs_not_when_it_was_prepared(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $prepared = $stage->prepare();
        $ranAt = self::theNextSecond();

        $played = $stage->play($prepared->run(...));

        self::assertGreaterThanOrEqual($ranAt, $played->announce()->body['at'] ?? null);
        self::assertLessThanOrEqual(time(), $played->announce()->body['at'] ?? null);
    }

    #[DataProvider('backends')]
    public function test_the_announcement_of_a_start_names_the_lease_of_the_current_version(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);
        $stage->register(2, Ending::Completes, lease: 120);

        $played = $stage->play();

        self::assertSame(120, $played->announce()->body['leaseSeconds'] ?? null);
    }

    #[DataProvider('continuations')]
    public function test_the_announcement_of_a_continuation_names_the_lease_of_the_version_its_run_is_pinned_to(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $stage->register(2, Ending::Completes, lease: 120);

        $played = $stage->play();

        self::assertSame(Stage::LEASE, $played->announce()->body['leaseSeconds'] ?? null);
    }

    /**
     * Neuron and the SDK read time(): only the real clock tells two instants apart.
     */
    protected static function theNextSecond(): int
    {
        $next = time() + 1;

        while (time() < $next) {
            usleep(20_000);
        }

        return $next;
    }
}
