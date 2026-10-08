<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\RefusalReason;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Origin;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The second of the two notices a segment run in the calling process leaves
 * in the queue: "read the store and tell the platform", with how the segment
 * ended, so that the later report still says so.
 */
class ObserveTest extends InPlaceTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_travels_as_a_message_of_the_application_about_the_run_and_carries_no_input(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $stage = $this->stage($backend, $kind, $ending);

        $played = $stage->play();

        $observe = $played->observe();
        self::assertSame([
            'invocationId' => $observe->invocationId,
            'verb' => Verb::Observe->value,
            'origin' => Origin::APPLICATION,
            'definition' => Stage::DEFINITION,
            'workflowId' => Stage::WORKFLOW_ID,
            'runId' => self::runIdOf($stage, $played),
            'attempt' => null,
            'expiresAt' => null,
            'signal' => null,
            'payload' => null,
            'event' => null,
            'body' => $observe->body,
        ], $observe->toArray());
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_says_how_the_segment_ended_field_by_field(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertSame([
            'invocationId' => $played->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => self::outcomeOf($ending)->value,
            'code' => null,
            'errorClass' => $ending === Ending::Fails ? NodeFailed::class : null,
            'executedAttempt' => $kind->continuesARun() ? 2 : 1,
        ], $played->observe()->body);
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_observe_carries_the_attempt_neuron_opened_the_segment_under(string $backend, Kind $kind, Ending $ending): void
    {
        $played = $this->stage($backend, $kind, $ending)->play();

        self::assertCount(1, $played->attemptsOpened);
        self::assertSame($played->attemptsOpened[0], $played->observe()->body['executedAttempt'] ?? null);
    }

    #[DataProvider('refusals')]
    public function test_the_observe_of_a_segment_neuron_refused_carries_the_reason_and_no_executed_attempt(
        string $backend,
        Kind $kind,
        Ending $ending,
        RefusalReason $reason,
    ): void {
        $played = $this->stage($backend, $kind, $ending)->playOvertaken();

        self::assertSame([
            'invocationId' => $played->announce()->body['invocationId'] ?? null,
            'verb' => $kind->verb()->value,
            'outcome' => Outcome::Refused->value,
            'code' => $reason->value,
            'errorClass' => null,
            'executedAttempt' => null,
        ], $played->observe()->body);
    }

    #[DataProvider('refusals')]
    public function test_neuron_opens_no_segment_for_a_call_whose_observe_says_refused(
        string $backend,
        Kind $kind,
        Ending $ending,
    ): void {
        $played = $this->stage($backend, $kind, $ending)->playOvertaken();

        self::assertSame([], $played->attemptsOpened);
    }

    #[DataProvider('backends')]
    public function test_the_observe_of_a_segment_that_lost_its_run_while_executing_says_stale_attempt_under_the_attempt_it_executed(
        string $backend,
    ): void {
        $stage = $this->stage($backend, Kind::Start, lease: 1);
        $stage->midBody->whenReached(static function () use ($stage): void {
            $stage->outliveTheLease();
            $stage->elsewhere()->recover();
        });

        $played = $stage->play();

        self::assertSame(
            [Outcome::Refused->value, RefusalReason::StaleAttempt->value, null, 1],
            [
                $played->observe()->body['outcome'] ?? null,
                $played->observe()->body['code'] ?? null,
                $played->observe()->body['errorClass'] ?? null,
                $played->observe()->body['executedAttempt'] ?? null,
            ],
        );
    }
}
