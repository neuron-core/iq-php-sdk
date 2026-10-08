<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Local\InPlace;

use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Stub\Matrix\Ending;
use NeuronCore\Cloud\Tests\Support\InPlace\InPlaceTestCase;
use NeuronCore\Cloud\Tests\Support\InPlace\Kind;
use NeuronCore\Cloud\Tests\Support\InPlace\Stage;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_slice;
use function array_unique;
use function count;

/**
 * What names a segment run in the calling process: the version it runs on,
 * the run ID minted for a start, the invocation IDs of appendix A of the
 * design, and what the factory of the definition is given.
 */
class IdentifiersTest extends InPlaceTestCase
{
    #[DataProvider('backends')]
    public function test_a_start_runs_on_the_highest_version_registered_whatever_the_order_of_the_calls(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, Ending::Completes);
        $stage->register(3, Ending::Waits);
        $stage->register(2, Ending::Fails);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(WorkflowStatus::Suspended, $played->returned->getStatus(), 'Only version 3 stops at a wait.');
    }

    #[DataProvider('backends')]
    public function test_the_run_id_of_a_start_has_the_minted_form_with_the_current_version_in_front(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start, Ending::Completes);
        $stage->register(3, Ending::Waits);
        $stage->register(2, Ending::Fails);

        $runId = self::runIdOf($stage, $stage->play());

        self::assertMatchesRegularExpression(self::MINTED_RUN_ID, $runId);
        self::assertStringStartsWith('3-', $runId);
    }

    #[DataProvider('backends')]
    public function test_the_run_id_the_caller_reads_in_the_state_is_the_one_both_notices_carry(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(
            [$played->returned->getRunId(), $played->returned->getRunId()],
            [$played->announce()->body['runId'] ?? null, $played->observe()->runId],
        );
    }

    #[DataProvider('backends')]
    public function test_every_start_is_given_a_run_id_of_its_own(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);

        $runIds = [self::runIdOf($stage, $stage->play()), self::runIdOf($stage, $stage->play())];

        self::assertCount(2, array_unique($runIds));
    }

    #[DataProvider('kinds')]
    public function test_both_notices_name_the_segment_by_one_invocation_id_of_the_format_of_the_protocol(string $backend, Kind $kind): void
    {
        $played = $this->stage($backend, $kind)->play();

        $named = $played->announce()->body['invocationId'] ?? '';
        self::assertMatchesRegularExpression(self::INVOCATION_ID, $named);
        self::assertSame($named, $played->observe()->body['invocationId'] ?? null);
    }

    #[DataProvider('kinds')]
    public function test_each_notice_travels_under_an_invocation_id_of_its_own_in_the_format_of_the_protocol(string $backend, Kind $kind): void
    {
        $played = $this->stage($backend, $kind)->play();

        $ids = [$played->announce()->invocationId, $played->observe()->invocationId, $played->announce()->body['invocationId'] ?? ''];
        self::assertMatchesRegularExpression(self::INVOCATION_ID, $ids[0]);
        self::assertMatchesRegularExpression(self::INVOCATION_ID, $ids[1]);
        self::assertCount(3, array_unique($ids));
    }

    #[DataProvider('kinds')]
    public function test_two_segments_never_share_an_invocation_id(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Fails);
        $first = $stage->play();

        // The run failed: recover() is a second segment whatever the first one was.
        $second = $stage->play($stage->thread->recover(...));

        self::assertNotSame($first->announce()->body['invocationId'] ?? null, $second->announce()->body['invocationId'] ?? null);
    }

    #[DataProvider('backends')]
    public function test_the_factory_is_called_once_for_a_start_with_the_workflow_id_and_the_minted_run_id(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Start);

        $played = $stage->play();

        self::assertSame([[1, Stage::WORKFLOW_ID, self::runIdOf($stage, $played)]], $stage->builds());
    }

    #[DataProvider('continuations')]
    public function test_the_factory_is_given_no_run_id_to_read_the_snapshot_and_the_run_id_to_build_the_continuation(
        string $backend,
        Kind $kind,
    ): void {
        $stage = $this->stage($backend, $kind);
        $runId = (string) ($stage->stored()['runId'] ?? '');
        $built = count($stage->builds());

        $stage->play();

        self::assertSame(
            [[1, Stage::WORKFLOW_ID, null], [1, Stage::WORKFLOW_ID, $runId]],
            array_slice($stage->builds(), $built),
        );
    }

    #[DataProvider('continuations')]
    public function test_a_continuation_executes_on_the_version_its_run_id_pins_not_on_the_current_one(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Completes);
        $runId = (string) ($stage->stored()['runId'] ?? '');
        $stage->register(2, Ending::Fails);

        $played = $stage->play();

        self::assertInstanceOf(WorkflowState::class, $played->returned);
        self::assertSame(WorkflowStatus::Completed, $played->returned->getStatus(), 'The last node of version 2 throws.');
        self::assertSame([1, Stage::WORKFLOW_ID, $runId], $stage->builds()[count($stage->builds()) - 1]);
    }

    #[DataProvider('backends')]
    public function test_a_start_takes_the_current_version_while_a_run_of_an_older_one_is_continued_on_its_own(string $backend): void
    {
        $stage = $this->stage($backend, Kind::Answer, Ending::Completes);
        $stage->register(2, Ending::Waits);
        $stage->play();

        $next = $stage->play($stage->thread->start()->run(...));

        self::assertInstanceOf(WorkflowState::class, $next->returned);
        self::assertStringStartsWith('2-', (string) $next->returned->getRunId());
    }
}
