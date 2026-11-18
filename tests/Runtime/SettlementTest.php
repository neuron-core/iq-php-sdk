<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Runtime\Settlement;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function addcslashes;
use function array_combine;
use function array_map;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class SettlementTest extends TestCase
{
    public function test_a_state_that_completed_settles_as_completed_under_the_attempt_that_ran(): void
    {
        $settlement = Settlement::ran(WorkflowStatus::Completed, 2);

        self::assertSame(Outcome::Completed, $settlement->outcome);
        self::assertSame(2, $settlement->executedAttempt);
    }

    public function test_a_state_that_waits_settles_as_suspended(): void
    {
        self::assertSame(Outcome::Suspended, Settlement::ran(WorkflowStatus::Suspended, 1)->outcome);
    }

    public function test_a_state_returned_without_a_segment_has_no_executed_attempt(): void
    {
        self::assertNull(Settlement::ran(WorkflowStatus::Suspended, null)->executedAttempt);
    }

    public function test_a_failure_carries_the_class_of_the_exception_and_the_attempt_that_ran(): void
    {
        $settlement = Settlement::failed(new NodeFailed('Card 4111 1111 1111 1111 was declined.'), 3);

        self::assertSame(Outcome::Failed, $settlement->outcome);
        self::assertSame(NodeFailed::class, $settlement->errorClass);
        self::assertSame(3, $settlement->executedAttempt);
    }

    public function test_a_failure_never_carries_the_message_of_the_exception(): void
    {
        $settlement = Settlement::failed(new NodeFailed('Card 4111 1111 1111 1111 was declined.'), 3);

        $facts = json_encode($settlement->facts(self::segment()), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('4111', $facts);
    }

    #[DataProvider('refusalReasons')]
    public function test_a_refusal_carries_the_reason_of_neuron_as_its_code(RefusalReason $reason): void
    {
        $settlement = Settlement::refused($reason);

        self::assertSame(Outcome::Refused, $settlement->outcome);
        self::assertSame($reason->value, $settlement->code);
    }

    public function test_a_refusal_that_followed_an_opened_segment_keeps_its_attempt(): void
    {
        self::assertSame(2, Settlement::refused(RefusalReason::Conflict, 2)->executedAttempt);
    }

    #[DataProvider('rejectedCodes')]
    public function test_a_rejection_carries_the_code_of_the_sdk(string $code): void
    {
        $settlement = Settlement::rejected(Code::from($code));

        self::assertSame(Outcome::Rejected, $settlement->outcome);
        self::assertSame($code, $settlement->code);
    }

    #[DataProvider('abortedCodes')]
    public function test_an_abort_carries_the_code_of_the_sdk(string $code): void
    {
        $settlement = Settlement::aborted(Code::from($code));

        self::assertSame(Outcome::Aborted, $settlement->outcome);
        self::assertSame($code, $settlement->code);
    }

    public function test_an_abort_with_no_cause_names_no_exception_class(): void
    {
        self::assertNull(Settlement::aborted(Code::UnknownDefinition)->errorClass);
    }

    public function test_an_abort_carries_the_class_of_what_caused_it(): void
    {
        $settlement = Settlement::aborted(Code::NotExecuted, new NodeFailed('The store lost the answer.'));

        self::assertSame(NodeFailed::class, $settlement->errorClass);
    }

    #[DataProvider('settlementsOfAnAnonymousException')]
    public function test_the_class_of_an_anonymous_exception_carries_no_file_path(Settlement $settlement): void
    {
        self::assertStringNotContainsString(__FILE__, addcslashes((string) $settlement->errorClass, "\0"));
    }

    public function test_an_anonymous_exception_is_named_after_its_nearest_named_parent(): void
    {
        $declined = new class ('The card was declined.') extends NodeFailed {
        };

        self::assertSame(NodeFailed::class, Settlement::failed($declined, 1)->errorClass);
    }

    public function test_an_abort_keeps_the_throwable_that_caused_it(): void
    {
        $cause = new NodeFailed('The store lost the answer.');

        self::assertSame($cause, Settlement::aborted(Code::NotExecuted, $cause)->cause);
    }

    public function test_a_claim_added_to_an_abort_keeps_its_cause(): void
    {
        $cause = new NodeFailed('The store lost the answer.');

        self::assertSame($cause, Settlement::aborted(Code::NotExecuted, $cause)->withClaim()->cause);
    }

    public function test_nothing_is_known_of_the_ledger_until_a_claim_is_added(): void
    {
        self::assertNull(Settlement::rejected(Code::Expired)->claimed);
    }

    public function test_a_claim_is_added_to_the_same_ending(): void
    {
        $aborted = Settlement::aborted(Code::NotExecuted, new NodeFailed());

        $claimed = $aborted->withClaim();

        self::assertTrue($claimed->claimed);
        self::assertSame($aborted->facts(self::segment()), $claimed->facts(self::segment()));
    }

    public function test_adding_a_claim_leaves_the_settlement_it_was_added_to_unclaimed(): void
    {
        $rejected = Settlement::rejected(Code::AlreadyClaimed);

        $rejected->withClaim();

        self::assertNull($rejected->claimed);
    }

    public function test_the_facts_of_a_settlement_are_the_report_fields_it_settled_for_its_segment(): void
    {
        $facts = Settlement::failed(new NodeFailed(), 2)->withClaim()->facts(self::segment());

        self::assertSame([
            'invocationId' => 'inv_01JB7K4T9QW2',
            'verb' => 'wake',
            'outcome' => 'failed',
            'code' => null,
            'errorClass' => NodeFailed::class,
            'executedAttempt' => 2,
        ], $facts);
    }

    public function test_the_facts_of_a_settlement_survive_a_json_queue(): void
    {
        $facts = Settlement::refused(RefusalReason::StaleAttempt)->facts(self::segment());

        self::assertSame($facts, json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * PHP names an anonymous class after the file and the line that declare it.
     *
     * @return array<string, array{Settlement}>
     */
    public static function settlementsOfAnAnonymousException(): array
    {
        $declined = new class ('The card was declined.') extends RuntimeException {
        };

        return [
            'a failure' => [Settlement::failed($declined, 1)],
            'an abort' => [Settlement::aborted(Code::NotExecuted, $declined)],
        ];
    }

    /**
     * @return array<string, array{RefusalReason}>
     */
    public static function refusalReasons(): array
    {
        $rows = [];
        foreach (RefusalReason::cases() as $reason) {
            $rows[$reason->value] = [$reason];
        }

        return $rows;
    }

    /**
     * @return array<string, array{string}> the codes the wire protocol lists for a rejected report
     */
    public static function rejectedCodes(): array
    {
        return self::rows('expired', 'invalid_start', 'already_claimed', 'wrong_definition');
    }

    /**
     * @return array<string, array{string}> the codes the wire protocol lists for an aborted report
     */
    public static function abortedCodes(): array
    {
        return self::rows('not_executed', 'unknown_definition', 'unknown_version', 'announce_failed', 'unavailable');
    }

    /**
     * @return array<string, array{string}>
     */
    protected static function rows(string ...$codes): array
    {
        return array_combine($codes, array_map(static fn (string $code): array => [$code], $codes));
    }

    protected static function segment(): Invocation
    {
        return new Invocation(
            'inv_01JB7K4T9QW2',
            Verb::Wake,
            'platform',
            'order-fulfilment',
            'order:123',
            '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12',
            attempt: 1,
        );
    }
}
