<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Tests\Stub\Questions\AwaitEventNode;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_repeat;

/**
 * Neuron lets the name of an awaited event hold any bytes, and JSON holds
 * only UTF-8: the platform is shown the name with its invalid bytes
 * replaced, in every body that carries the run.
 */
class UnencodableEventNameTest extends QuestionsTestCase
{
    #[DataProvider('namesThatAreNotUtf8')]
    public function test_an_inspect_shows_the_name_with_each_invalid_byte_replaced(string $backend, string $name, string $shown): void
    {
        $this->orderWaitingFor($backend, $name);

        $answer = $this->deliver($this->inspect(self::RUN_ID));

        $said = self::fieldsOf($answer);
        self::assertSame([200, true], [$answer->statusCode, $said['snapshotRead']]);
        self::assertSame($shown, $said['run']['wait']['eventName']);
    }

    #[DataProvider('namesThatAreNotUtf8')]
    public function test_the_report_of_the_run_is_built_and_carries_the_name_the_inspect_shows(string $backend, string $name, string $shown): void
    {
        $this->orderWaitingFor($backend, $name);

        [$report] = $this->settled;

        self::assertInstanceOf(Report::class, $report);
        self::assertSame([Outcome::Suspended, $shown], [$report->outcome, $report->run['wait']['eventName'] ?? null]);
    }

    #[DataProvider('namesThatAreNotUtf8')]
    public function test_a_refused_abandon_answers_409_with_the_run_and_the_name_replaced(string $backend, string $name, string $shown): void
    {
        $this->orderWaitingFor($backend, $name);

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 2));

        $refusal = self::fieldsOf($answer);
        self::assertSame([409, 'stale_attempt', $shown], [$answer->statusCode, $refusal['code'], $refusal['run']['wait']['eventName']]);
    }

    #[DataProvider('namesThatAreNotUtf8')]
    public function test_an_abandon_discards_the_run_whatever_bytes_the_name_holds(string $backend, string $name): void
    {
        $this->orderWaitingFor($backend, $name);

        $answer = $this->deliver($this->abandon(self::RUN_ID, attempt: 1));

        self::assertSame(self::DISCARDED, self::answered($answer));
    }

    #[DataProvider('namesThatAreUtf8')]
    public function test_a_name_that_is_utf8_is_shown_as_it_is(string $backend, string $name): void
    {
        $this->orderWaitingFor($backend, $name);

        $answer = $this->deliver($this->inspect());

        self::assertSame($name, self::fieldsOf($answer)['run']['wait']['eventName']);
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the name the node waits for, the name the platform is shown
     */
    public static function namesThatAreNotUtf8(): array
    {
        return self::onEachBackend([
            'a byte no encoding of UTF-8 has' => ["payment\xFFreceived", "payment\u{FFFD}received"],
            'a sequence cut short at the end' => ["caf\xC3", "caf\u{FFFD}"],
            'a sequence followed by the wrong byte' => ["\xC3(", "\u{FFFD}("],
            'a surrogate' => ["\xED\xA0\x80", "\u{FFFD}"],
            'an overlong encoding' => ["\xC0\xAF", "\u{FFFD}\u{FFFD}"],
            'Latin-1 text' => ["paiement re\xE7u", "paiement re\u{FFFD}u"],
            'nothing but invalid bytes, many of them' => [str_repeat("\xFF", 5000), str_repeat("\u{FFFD}", 5000)],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the backend and the name the node waits for
     */
    public static function namesThatAreUtf8(): array
    {
        return self::onEachBackend([
            'accents and an emoji' => ["paiement reçu \u{1F4B6}"],
            'what JSON escapes' => ["a \"quoted\" name\\\n\twith a \0 in it"],
            'the replacement character itself' => ["payment\u{FFFD}received"],
            'the empty name' => [''],
        ]);
    }

    /**
     * The platform starts an order whose only node waits for an event of that name: a worker runs it and
     * attempt 1 is suspended.
     */
    protected function orderWaitingFor(string $backend, string $eventName): void
    {
        $this->benchOn($backend);

        $this->afterTheFirstSegmentOf(static fn (): Workflow => (new Workflow())->addNode(new AwaitEventNode($eventName)));
    }
}
