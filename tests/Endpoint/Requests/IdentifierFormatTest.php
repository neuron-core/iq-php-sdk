<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_repeat;

/**
 * The table of identifiers of the wire protocol: every verb, with each of its
 * identifiers at and just outside each limit of its format.
 */
class IdentifierFormatTest extends RequestTestCase
{
    protected const ADDRESSED = ['start', 'wake', 'inspect', 'abandon'];

    /** The verbs whose run ID follows Neuron's rule; the run ID of a start is minted. */
    protected const FENCED = ['wake', 'inspect', 'abandon'];

    protected const QUEUED = ['start', 'wake'];

    /** What a well-formed message of each verb is answered. */
    protected const TAKEN = ['start' => 202, 'wake' => 202, 'inspect' => 200, 'abandon' => 200];

    #[DataProvider('identifiersInsideTheirFormat')]
    public function test_a_message_whose_identifier_is_inside_its_format_is_taken(string $backend, string $verb, string $field, string $value): void
    {
        $this->shop($backend);
        if ($field === 'definition') {
            $this->cloud->register($value, static fn (): Workflow => new Workflow());
        }

        $answer = $this->deliver([$field => $value] + $this->message($verb));

        self::assertSame(self::TAKEN[$verb], $answer->statusCode);
    }

    #[DataProvider('identifiersOutsideTheirFormat')]
    public function test_a_message_whose_identifier_is_outside_its_format_is_answered_400_invalid_request_and_does_nothing(
        string $backend,
        string $verb,
        string $field,
        string $value,
    ): void {
        $this->shop($backend);

        $answer = $this->deliver([$field => $value] + $this->message($verb));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_start_on_the_highest_version_a_run_id_can_carry_is_queued(string $backend): void
    {
        $this->shop($backend);
        $this->cloud->register(self::DEFINITION, static fn (): Workflow => new Workflow(), version: 999999);

        $answer = $this->deliver($this->start(['runId' => '999999-0192f3a8']));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('charactersOfSeveralBytes')]
    public function test_a_workflow_id_of_255_characters_is_queued_however_many_bytes_each_takes(string $backend, string $verb, string $character): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['workflowId' => str_repeat($character, 255)] + $this->message($verb));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('charactersOfSeveralBytes')]
    public function test_a_workflow_id_of_256_characters_is_answered_400_invalid_request_however_many_bytes_each_takes(string $backend, string $verb, string $character): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['workflowId' => str_repeat($character, 256)] + $this->message($verb));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    public function test_an_inspect_of_a_workflow_id_of_255_multibyte_characters_reads_a_store_that_holds_such_a_name(): void
    {
        $this->shop(TestBench::IN_MEMORY);

        $answer = $this->deliver(['workflowId' => str_repeat('é', 255)] + $this->inspect(self::RUN_ID));

        self::assertSame([200, true], [$answer->statusCode, self::fieldsOf($answer)['snapshotRead']]);
    }

    public function test_an_inspect_of_a_workflow_id_of_255_multibyte_characters_reads_no_snapshot_from_a_store_that_refuses_510_bytes(): void
    {
        $this->shop(TestBench::SQLITE);

        $answer = $this->deliver(['workflowId' => str_repeat('é', 255)] + $this->inspect(self::RUN_ID));

        self::assertSame([200, false], [$answer->statusCode, self::fieldsOf($answer)['snapshotRead']]);
    }

    public function test_an_abandon_of_a_workflow_id_of_255_multibyte_characters_is_obeyed_on_a_store_that_holds_such_a_name(): void
    {
        $this->shop(TestBench::IN_MEMORY);

        $answer = $this->deliver(['workflowId' => str_repeat('é', 255)] + $this->abandon());

        self::assertSame([200, true], [$answer->statusCode, self::fieldsOf($answer)['claimed']]);
    }

    public function test_an_abandon_of_a_workflow_id_of_255_multibyte_characters_is_answered_503_unavailable_on_a_store_that_refuses_510_bytes(): void
    {
        $this->shop(TestBench::SQLITE);

        $answer = $this->deliver(['workflowId' => str_repeat('é', 255)] + $this->abandon());

        self::assertSame([503, '{"code":"unavailable"}'], self::statusAndBody($answer));
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field, its value
     */
    public static function identifiersInsideTheirFormat(): array
    {
        return self::onEveryBackend(
            self::rows(self::ADDRESSED, 'definition', [
                'of one character' => 'a',
                'that begins with a digit' => '0rders',
                'of 64 characters' => 'a' . str_repeat('b', 63),
                'with a dot, an underscore and a hyphen' => 'order.fulfilment_v2-eu',
            ])
            + self::rows(self::ADDRESSED, 'workflowId', [
                'of one character' => 'o',
                'of 255 characters' => str_repeat('o', 255),
                'that begins with one underscore' => '_order',
                'with two underscores after its first character' => 'order__123',
                'with spaces and punctuation' => 'order 123/eu?paid=1&#:%',
                'with characters of several bytes' => 'ordine:città:東京',
                'with a character just above the ASCII controls' => "order\u{80}123",
            ])
            + self::rows(self::FENCED, 'runId', [
                'of one character' => 'r',
                'of 128 characters' => str_repeat('r', 128),
                'that is not of the minted form' => '0192f3a8-7c1e',
                'with an underscore and a hyphen' => 'Run_0192-f3a8',
                'whose number is too long for a version' => '1000000-0192f3a8',
            ])
            + self::rows(['start'], 'runId', [
                'of the shortest minted form' => '1-a',
                'whose unique part has 120 characters' => '1-' . str_repeat('a', 120),
                'whose unique part holds an underscore and a hyphen' => '1-A_b-9',
            ])
            + self::rows(self::QUEUED, 'invocationId', [
                'of one character' => 'i',
                'of 128 characters' => str_repeat('i', 128),
                'with an underscore and a hyphen' => 'inv_01JB-7K4T',
            ]),
        );
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field, its value
     */
    public static function identifiersOutsideTheirFormat(): array
    {
        return self::onEveryBackend(
            self::rows(self::ADDRESSED, 'definition', [
                'that is empty' => '',
                'of 65 characters' => 'a' . str_repeat('b', 64),
                'that begins with a dot' => '.orders',
                'that begins with an underscore' => '_orders',
                'that begins with a hyphen' => '-orders',
                'with a capital' => 'Order-fulfilment',
                'with a space' => 'order fulfilment',
                'with a slash' => 'order/fulfilment',
                'with a colon' => 'order:fulfilment',
                'with a character of several bytes' => 'ordini-città',
                'that ends with a line feed' => "order-fulfilment\n",
                'with a NUL' => "order\u{0}fulfilment",
            ])
            + self::rows(self::ADDRESSED, 'workflowId', [
                'that is empty' => '',
                'of 256 characters' => str_repeat('o', 256),
                'of 256 multibyte characters' => str_repeat('é', 256),
                'that begins with two underscores' => '__order',
                'that is two underscores' => '__',
                'that is the partition of the ledger' => '__neuron_cloud:9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
                'that ends with a line feed' => "order:123\n",
                'with a tab' => "order\t123",
                'with a NUL' => "order\u{0}123",
                'that begins with a NUL' => "\u{0}order",
                'with the last ASCII control' => "order\u{1F}123",
                'with a DEL' => "order\u{7F}123",
            ])
            + self::rows(self::FENCED, 'runId', [
                'that is empty' => '',
                'of 129 characters' => str_repeat('r', 129),
                'that begins with a hyphen' => '-0192f3a8',
                'that begins with an underscore' => '_0192f3a8',
                'with a dot' => '1-0192.f3a8',
                'with a colon' => '1-0192:f3a8',
                'with a space' => '1-0192 f3a8',
                'with a character of several bytes' => '1-città',
                'that ends with a line feed' => "1-0192f3a8\n",
            ])
            + self::rows(['start'], 'runId', [
                'that is empty' => '',
                'that is not of the minted form' => '0192f3a8',
                'that follows Neuron\'s rule and carries no version' => '01JB2M8Q3W',
                'of version 0' => '0-0192f3a8',
                'whose version begins with a zero' => '01-0192f3a8',
                'whose version has seven digits' => '1000000-0192f3a8',
                'whose version is negative' => '-1-0192f3a8',
                'whose version is a decimal' => '1.5-0192f3a8',
                'whose version follows a letter' => 'v1-0192f3a8',
                'that is a version and nothing' => '1-',
                'that is a version alone' => '1',
                'whose unique part begins with a hyphen' => '1--0192f3a8',
                'whose unique part begins with an underscore' => '1-_0192f3a8',
                'whose unique part has 121 characters' => '1-' . str_repeat('a', 121),
                'whose unique part holds a dot' => '1-0192.f3a8',
                'whose unique part holds a character of several bytes' => '1-città',
                'that ends with a line feed' => "1-0192f3a8\n",
                'that begins with a space' => ' 1-0192f3a8',
            ])
            + self::rows(self::QUEUED, 'invocationId', [
                'that is empty' => '',
                'of 129 characters' => str_repeat('i', 129),
                'that begins with an underscore' => '_inv',
                'that begins with a hyphen' => '-inv',
                'with a dot' => 'inv.01JB',
                'with a space' => 'inv 01JB',
                'with a character of several bytes' => 'inv_città',
                'that ends with a line feed' => "inv_01JB\n",
            ]),
        );
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, one character
     */
    public static function charactersOfSeveralBytes(): array
    {
        $rows = [];

        foreach (self::QUEUED as $verb) {
            $rows += [
                "{$verb}, characters of two bytes" => [$verb, 'é'],
                "{$verb}, characters of three bytes" => [$verb, '東'],
                "{$verb}, characters of four bytes" => [$verb, '😀'],
            ];
        }

        return self::onEveryBackend($rows);
    }

    /**
     * @param string[] $verbs
     * @param array<string, string> $values what is particular about each value => the value
     * @return array<string, array<int, mixed>>
     */
    protected static function rows(array $verbs, string $field, array $values): array
    {
        $rows = [];

        foreach ($verbs as $verb) {
            foreach ($values as $particular => $value) {
                $rows["{$verb} with a {$field} {$particular}"] = [$verb, $field, $value];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed> a well-formed message of the verb, about a run of version 1
     */
    protected function message(string $verb): array
    {
        return match ($verb) {
            'start' => $this->start(),
            'wake' => $this->wake(attempt: 1),
            'inspect' => $this->inspect(self::RUN_ID),
            default => $this->abandon(),
        };
    }
}
