<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\Streamed;

use NeuronCore\Cloud\Tests\Support\Faults\FaultMode;
use NeuronCore\Cloud\Tests\Support\Streamed\FirstStep;
use NeuronCore\Cloud\Tests\Support\Streamed\Kind;
use NeuronCore\Cloud\Tests\Support\Streamed\Stage;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamedTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;

/**
 * Section 9.6 of the design: with events(), admission happens on the first
 * iteration, as in Neuron. Until then the generator is a promise: nothing was
 * asked of the store and no node body ran, however long the consumer waits.
 */
class AdmissionTest extends StreamedTestCase
{
    #[DataProvider('kinds')]
    public function test_events_asks_nothing_of_the_store(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $segment = $stage->prepare();
        $operations = $stage->journal->exposedTo(FaultMode::BeforeOperation);

        $events = $segment->events();
        $operationsOnceReturned = $stage->journal->exposedTo(FaultMode::BeforeOperation);
        $events->valid();

        self::assertSame($operations, $operationsOnceReturned);
        self::assertGreaterThan($operations, $stage->journal->exposedTo(FaultMode::BeforeOperation), 'The first step asked nothing of the store either.');
    }

    #[DataProvider('kinds')]
    public function test_the_store_holds_what_it_held_until_the_first_step_is_taken(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $held = $stage->stored();

        $events = $stage->stream();

        self::assertSame($held, $stage->stored());
        self::assertSame(0, $stage->writes());
        self::assertTrue($events->valid(), 'The stream had nothing to give once it was taken.');
    }

    #[DataProvider('kinds')]
    public function test_no_node_body_runs_until_the_first_step_is_taken(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);

        $events = $stage->stream();

        self::assertSame(0, $stage->bodiesRun());
        self::assertTrue($events->valid(), 'The stream had nothing to give once it was taken.');
    }

    #[DataProvider('firstSteps')]
    public function test_the_first_step_admits_the_segment_under_the_attempt_of_its_kind(string $backend, Kind $kind, FirstStep $step): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        $step->takeOn($events);

        $run = $stage->stored();
        self::assertSame(['running', $kind->attempt()], [$run['status'] ?? null, $run['attempt'] ?? null]);
    }

    #[DataProvider('firstSteps')]
    public function test_the_first_step_puts_the_run_under_the_lease_of_its_definition(string $backend, Kind $kind, FirstStep $step): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $takenAt = time();

        $step->takeOn($events);

        $leaseExpiresAt = $stage->stored()['leaseExpiresAt'] ?? null;
        self::assertGreaterThanOrEqual($takenAt + Stage::LEASE, $leaseExpiresAt);
        self::assertLessThanOrEqual(time() + Stage::LEASE, $leaseExpiresAt);
    }

    #[DataProvider('firstSteps')]
    public function test_the_first_step_runs_the_first_node_body_and_no_other(string $backend, Kind $kind, FirstStep $step): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();

        $step->takeOn($events);

        self::assertSame(1, $stage->bodiesRun());
    }

    #[DataProvider('firstSteps')]
    public function test_the_first_step_queues_nothing(string $backend, Kind $kind, FirstStep $step): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $queued = $stage->verbsQueued();

        $step->takeOn($events);

        self::assertSame($queued, $stage->verbsQueued());
    }

    #[DataProvider('kinds')]
    public function test_the_first_step_executes_the_run_that_was_announced_before_it(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $announced = $stage->announce()->runId;

        $events->valid();

        self::assertSame($announced, $stage->stored()['runId'] ?? null);
    }

    /**
     * @return array<string, array{string, Kind, FirstStep}>
     */
    public static function firstSteps(): array
    {
        $rows = [];
        foreach (Kind::cases() as $kind) {
            foreach (FirstStep::cases() as $step) {
                $rows["{$kind->label()} first taken with {$step->label()}"] = [$kind, $step];
            }
        }

        return self::onBothBackends($rows);
    }
}
