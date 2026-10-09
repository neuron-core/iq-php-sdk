<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Stub\WaitingWorkflow;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which registered version builds the workflow an inspect and an abandon
 * read the store through, and what its factory is given. Neither is ever
 * answered unknown_version: every version of a definition shares one store.
 */
class FactoryCallTest extends QuestionsTestCase
{
    /** @var array<int, array{int, array<int, mixed>}> the version and the arguments of every call of a factory, in order */
    protected array $built = [];

    #[DataProvider('backends')]
    public function test_an_inspect_that_names_no_run_id_calls_the_factory_of_the_current_version_once_with_the_workflow_id_and_no_run_id(
        string $backend,
    ): void {
        $this->versionsOn($backend, 1, 2, 3);

        $this->deliver($this->inspect());

        self::assertSame([[3, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_calls_the_factory_of_the_version_its_run_id_pins_with_no_run_id(string $backend): void
    {
        $this->versionsOn($backend, 1, 2, 3);

        $this->deliver($this->inspect('2-0192f3a8'));

        self::assertSame([[2, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('versionsThisProcessLacks')]
    public function test_an_inspect_of_a_version_this_process_lacks_is_answered_through_the_current_one(string $backend, string $runId): void
    {
        $this->versionsOn($backend, 2, 3);

        $answer = $this->deliver($this->inspect($runId));

        self::assertSame(200, $answer->statusCode);
        self::assertSame([[3, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_of_a_run_id_the_sdk_did_not_mint_calls_the_factory_of_the_lowest_version(string $backend): void
    {
        $this->versionsOn($backend, 2, 3);

        $this->deliver($this->inspect('legacy-import-42'));

        self::assertSame([[2, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_an_inspect_shows_the_run_of_an_older_version_through_the_factory_of_the_current_one(string $backend): void
    {
        $this->versionsOn($backend, 1, 2);
        $this->deliver($this->start());
        $this->work();
        $this->built = [];

        $answer = $this->deliver($this->inspect());

        self::assertSame(self::RUN_ID, self::fieldsOf($answer)['run']['runId']);
        self::assertSame([[2, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_calls_the_factory_of_the_version_its_run_id_pins_once_with_the_workflow_id_and_no_run_id(string $backend): void
    {
        $this->versionsOn($backend, 1, 2, 3);

        $this->deliver($this->abandon('2-0192f3a8'));

        self::assertSame([[2, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('versionsThisProcessLacks')]
    public function test_an_abandon_of_a_version_this_process_lacks_is_obeyed_through_the_current_one(string $backend, string $runId): void
    {
        $this->versionsOn($backend, 2, 3);

        $answer = $this->deliver($this->abandon($runId));

        self::assertSame([200, ['run' => null, 'claimed' => true, 'snapshotRead' => true]], self::answered($answer));
        self::assertSame([[3, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_an_abandon_of_a_run_id_the_sdk_did_not_mint_calls_the_factory_of_the_lowest_version(string $backend): void
    {
        $this->versionsOn($backend, 2, 3);

        $this->deliver($this->abandon('legacy-import-42'));

        self::assertSame([[2, [self::WORKFLOW_ID, null]]], $this->built);
    }

    #[DataProvider('backends')]
    public function test_a_ping_calls_no_factory(string $backend): void
    {
        $this->versionsOn($backend, 1, 2);

        $this->deliver(['verb' => 'ping']);

        self::assertSame([], $this->built);
    }

    /**
     * @return array<string, array<int, mixed>> the backend and a run ID of the version
     */
    public static function versionsThisProcessLacks(): array
    {
        return self::onEachBackend([
            'a version not deployed yet' => ['7-0192f3a8'],
            'a version already removed' => ['1-0192f3a8'],
        ]);
    }

    /**
     * Register "order-fulfilment" in several versions, each with a factory that notes what it is called with.
     */
    protected function versionsOn(string $backend, int ...$versions): void
    {
        $executions = $this->benchOn($backend)->executions;

        foreach ($versions as $version) {
            $this->bench->cloud->register(
                self::DEFINITION,
                function (mixed ...$arguments) use ($version, $executions): Workflow {
                    $this->built[] = [$version, $arguments];

                    return new WaitingWorkflow($executions);
                },
                $version,
            );
        }
    }
}
