<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Continuations;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronCore\IQ\PendingSegment;
use NeuronCore\IQ\Tests\Stub\Continuations\ContractWorkflow;
use NeuronCore\IQ\Tests\Stub\Local\PaidAmountTranslator;
use NeuronCore\IQ\Tests\Support\Continuations\ContinuationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 3 of section 9.7, for the translator: it is applied in the request,
 * against the wait the run is suspended on, and what it returns is the answer.
 */
class TranslatorTest extends ContinuationTestCase
{
    protected const WEBHOOK = ['paid' => '100', 'currency' => 'EUR'];

    protected const TRANSLATED = ['amount' => 100];

    #[DataProvider('backends')]
    public function test_with_no_translator_the_wait_receives_the_payload_as_it_was_given(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();

        $state = $contract->submitInputs(self::WEBHOOK)->run();

        self::assertSame(self::WEBHOOK, $state->get('buyer'));
    }

    #[DataProvider('backends')]
    public function test_with_a_translator_the_wait_receives_what_the_translator_returned(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();

        $state = $contract->submitInputs(self::WEBHOOK, new PaidAmountTranslator())->run();

        self::assertSame(self::TRANSLATED, $state->get('buyer'));
    }

    #[DataProvider('backends')]
    public function test_a_dispatched_answer_carries_what_the_translator_returned_not_what_it_was_given(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $queued = $this->countQueued();

        $contract->submitInputs(self::WEBHOOK, new PaidAmountTranslator())->dispatch();

        [$answer] = $this->queuedAfter($queued);
        self::assertSame(self::TRANSLATED, $answer->payload);
    }

    #[DataProvider('backends')]
    public function test_the_translator_is_given_the_wait_the_run_is_suspended_on_now(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $contract->submitInputs(self::BUYER)->run();
        $translator = new PaidAmountTranslator();

        $contract->submitInputs(self::WEBHOOK, $translator);

        self::assertInstanceOf(WaitForEventRequest::class, $translator->translatedAgainst);
        self::assertSame(
            [2, ContractWorkflow::EVENT],
            [$translator->translatedAgainst->getId(), $translator->translatedAgainst->getEventName()],
        );
    }

    #[DataProvider('backends')]
    public function test_what_the_translator_throws_is_thrown_as_it_is_and_nothing_is_queued(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $queued = $this->countQueued();

        $refusal = self::thrownFrom(
            static fn (): PendingSegment => $contract->submitInputs(['currency' => 'EUR'], new PaidAmountTranslator()),
        );

        self::assertSame(InputTranslationException::class, $refusal::class);
        self::assertSame('The webhook names no paid amount.', $refusal->getMessage());
        self::assertSame([], $this->queuedAfter($queued));
    }

    #[DataProvider('backends')]
    public function test_an_answer_the_translator_refused_leaves_the_wait_open_for_the_next_one(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        self::thrownFrom(static fn (): PendingSegment => $contract->submitInputs(['currency' => 'EUR'], new PaidAmountTranslator()));

        $state = $contract->submitInputs(self::WEBHOOK, new PaidAmountTranslator())->run();

        self::assertSame(self::TRANSLATED, $state->get('buyer'));
    }

    #[DataProvider('backends')]
    public function test_the_json_rule_applies_to_what_the_translator_returned_not_to_what_it_was_given(string $backend): void
    {
        $this->benchOn($backend);
        $contract = $this->contract();
        $contract->start()->run();
        $webhook = self::WEBHOOK + ['signature' => "\xB1\x31"];

        $state = $contract->submitInputs($webhook, new PaidAmountTranslator())->run();

        self::assertSame(self::TRANSLATED, $state->get('buyer'));
    }
}
