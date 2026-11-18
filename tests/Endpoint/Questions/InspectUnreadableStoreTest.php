<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Questions;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\Runner\FactoryFailed;
use NeuronCore\IQ\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Questions\Holder;
use NeuronCore\IQ\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function hash;
use function serialize;
use function str_repeat;
use function str_starts_with;

/**
 * An inspect whose snapshot cannot be read is still answered 200, with
 * "snapshotRead" false and nothing of what went wrong: the platform asks again.
 */
class InspectUnreadableStoreTest extends QuestionsTestCase
{
    protected const NO_SNAPSHOT = [200, ['run' => null, 'claimed' => null, 'snapshotRead' => false]];

    #[DataProvider('backends')]
    public function test_a_run_that_cannot_be_read_is_answered_200_with_no_snapshot(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, function (): HttpResponse {
            $this->failTheStoreAt(static fn (string $operation, string $partition): bool => $partition === self::WORKFLOW_ID);

            return $this->deliver($this->inspect(self::RUN_ID));
        });

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
        self::assertStringNotContainsString(self::STORE_FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_a_ledger_that_cannot_be_read_leaves_no_snapshot_although_the_run_was_read(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, function (): HttpResponse {
            $this->failTheLedger();

            return $this->deliver($this->inspect(self::RUN_ID));
        });

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
        self::assertStringNotContainsString(self::STORE_FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_a_question_that_names_no_run_id_is_answered_from_the_run_alone_when_the_ledger_cannot_be_read(string $backend): void
    {
        $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, function (): HttpResponse {
            $this->failTheLedger();

            return $this->deliver($this->inspect());
        });

        self::assertSame(
            ['run' => $this->storedRun(), 'claimed' => null, 'snapshotRead' => true],
            self::saidBy($answer),
        );
    }

    #[DataProvider('backends')]
    public function test_a_factory_that_throws_is_answered_200_with_no_snapshot_and_nothing_of_what_it_threw(string $backend): void
    {
        $this->benchOn($backend)->iq->register(
            self::DEFINITION,
            static fn (): Workflow => throw new FactoryFailed('The container could not build OrderWorkflow for tenant acme.'),
        );

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
        self::assertStringNotContainsString('acme', $answer->body);
        self::assertStringNotContainsString('FactoryFailed', $answer->body);
    }

    #[DataProvider('backends')]
    public function test_a_factory_that_builds_something_else_than_a_workflow_is_answered_200_with_no_snapshot(string $backend): void
    {
        // @phpstan-ignore argument.type (what the test is about: an application's factory may return anything)
        $this->benchOn($backend)->iq->register(self::DEFINITION, static fn (): stdClass => new stdClass());

        $answer = $this->deliver($this->inspect());

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_persistence_factory_that_throws_is_answered_200_with_no_snapshot(string $backend): void
    {
        $this->ordersOn($backend)->iq->setPersistence(
            static fn (): PersistenceInterface => throw new StoreUnavailable(self::STORE_FAILURE),
        );

        $answer = $this->deliver($this->inspect());

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
        self::assertStringNotContainsString(self::STORE_FAILURE, $answer->body);
    }

    #[DataProvider('backends')]
    public function test_an_endpoint_that_was_given_no_persistence_answers_200_with_no_snapshot(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $unconfigured = (new IQ(TestBench::API_KEY, $bench->platform->signingKey))
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions));

        $answer = $bench->platform->deliver($unconfigured, $this->inspect(self::RUN_ID));

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_ledger_record_that_cannot_be_decoded_is_answered_200_with_no_snapshot(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        ($bench->persistence)()->initializeIfAbsent(
            self::LEDGER_PARTITION_PREFIX . hash('sha256', self::WORKFLOW_ID),
            'starts',
            '{"1-0192f3a8": 17914',
        );

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_a_run_record_that_cannot_be_decoded_is_answered_200_with_no_snapshot(string $backend): void
    {
        $bench = $this->ordersOn($backend);
        ($bench->persistence)()->initializeIfAbsent(self::WORKFLOW_ID, '__control', serialize('not the record of a run'));

        $answer = $this->deliver($this->inspect());

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
    }

    public function test_a_workflow_id_too_long_for_a_database_store_is_answered_200_with_no_snapshot(): void
    {
        $this->ordersOn(TestBench::SQLITE);
        // 200 characters, as Neuron's rule allows, in 400 bytes: DatabasePersistence refuses the partition.
        $longerThanThePartitionColumn = str_repeat('é', 200);

        $answer = $this->deliver(['workflowId' => $longerThanThePartitionColumn] + $this->inspect());

        self::assertSame(self::NO_SNAPSHOT, self::answered($answer));
    }

    #[DataProvider('backends')]
    public function test_the_next_question_reads_the_snapshot_once_the_store_answers_again(string $backend): void
    {
        $bench = $this->benchOn($backend);

        $answer = $this->whileHeldBy(Holder::WaitingForAnEvent, function () use ($bench): HttpResponse {
            $this->failTheStore();
            $this->deliver($this->inspect(self::RUN_ID));
            $bench->iq->setPersistence($bench->persistence);

            return $this->deliver($this->inspect(self::RUN_ID));
        });

        self::assertSame(['run' => $this->storedRun(), 'claimed' => true, 'snapshotRead' => true], self::saidBy($answer));
    }

    /**
     * From now on the store fails at every operation on a ledger, and serves the runs.
     */
    protected function failTheLedger(): void
    {
        $this->failTheStoreAt(
            static fn (string $operation, string $partition): bool => str_starts_with($partition, self::LEDGER_PARTITION_PREFIX),
        );
    }
}
