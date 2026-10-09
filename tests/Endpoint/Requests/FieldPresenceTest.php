<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function in_array;

/**
 * The fields each verb carries: one that is missing, sent as null or of
 * another type makes the message malformed. A field of another verb is one
 * the receiver does not know, and is ignored.
 */
class FieldPresenceTest extends RequestTestCase
{
    protected const REQUIRED = [
        'start' => ['invocationId', 'definition', 'workflowId', 'runId', 'expiresAt'],
        'wake' => ['invocationId', 'definition', 'workflowId', 'runId', 'attempt'],
        'inspect' => ['definition', 'workflowId'],
        'abandon' => ['definition', 'workflowId', 'runId'],
    ];

    protected const OPTIONAL = ['inspect' => ['runId'], 'abandon' => ['attempt']];

    protected const INSTANTS_AND_COUNTERS = ['expiresAt', 'attempt'];

    #[DataProvider('requiredFields')]
    public function test_a_message_without_a_field_its_verb_requires_is_answered_400_invalid_request_and_does_nothing(string $backend, string $verb, string $field): void
    {
        $this->shop($backend);
        $message = $this->message($verb);
        unset($message[$field]);

        $answer = $this->deliver($message);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('optionalFields')]
    public function test_a_message_without_a_field_its_verb_may_omit_is_answered_200(string $backend, string $verb, string $field): void
    {
        $this->shop($backend);
        $message = $this->message($verb);
        unset($message[$field]);

        $answer = $this->deliver($message);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('everyField')]
    public function test_a_field_sent_as_null_is_answered_400_invalid_request_and_does_nothing(string $backend, string $verb, string $field): void
    {
        $this->shop($backend);

        $answer = $this->deliver([$field => null] + $this->message($verb));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('fieldsOfAnotherType')]
    public function test_a_field_of_another_type_is_answered_400_invalid_request_and_does_nothing(string $backend, string $verb, string $field, mixed $value): void
    {
        $this->shop($backend);

        $answer = $this->deliver([$field => $value] + $this->message($verb));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('fieldsOfAnotherVerb')]
    public function test_a_field_of_another_verb_is_ignored_whatever_it_holds(string $backend, string $verb, int $taken, string $field): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->message($verb) + [$field => ['not' => 'a value of this field']]);

        self::assertSame($taken, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_ping_is_answered_whatever_fields_of_the_other_verbs_it_carries(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['verb' => 'ping', 'definition' => 7, 'workflowId' => '__ledger', 'runId' => null, 'attempt' => 0, 'expiresAt' => 'never']);

        self::assertSame([200, '{"protocols":[1]}'], self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field
     */
    public static function requiredFields(): array
    {
        return self::onEveryBackend(self::fields(self::REQUIRED));
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field
     */
    public static function optionalFields(): array
    {
        return self::onEveryBackend(self::fields(self::OPTIONAL));
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field
     */
    public static function everyField(): array
    {
        return self::onEveryBackend(self::fields(self::REQUIRED) + self::fields(self::OPTIONAL));
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, the field, a value of a type the field is not of
     */
    public static function fieldsOfAnotherType(): array
    {
        $strangers = [
            'text' => [
                'a number' => 123,
                'a decimal' => 1.5,
                'true' => true,
                'false' => false,
                'a list that holds a valid value' => ['order-fulfilment'],
                'an object' => ['value' => 'order-fulfilment'],
            ],
            'integer' => [
                'a number in a string' => '1',
                'a decimal' => 1.5,
                'true' => true,
                'a list that holds a valid value' => [1],
                'an object' => ['value' => 1],
            ],
        ];
        $rows = [];

        foreach (self::fields(self::REQUIRED) + self::fields(self::OPTIONAL) as $case => [$verb, $field]) {
            foreach ($strangers[in_array($field, self::INSTANTS_AND_COUNTERS, true) ? 'integer' : 'text'] as $type => $value) {
                $rows["{$case}, sent as {$type}"] = [$verb, $field, $value];
            }
        }

        return self::onEveryBackend($rows);
    }

    /**
     * @return array<string, array<int, mixed>> the backend, the verb, what it is answered, the field of another verb
     */
    public static function fieldsOfAnotherVerb(): array
    {
        return self::onEveryBackend([
            'a start that carries an attempt' => ['start', 202, 'attempt'],
            'a wake that carries a use-by time' => ['wake', 202, 'expiresAt'],
            'an inspect that carries an attempt' => ['inspect', 200, 'attempt'],
            'an inspect that carries an invocation ID' => ['inspect', 200, 'invocationId'],
            'an inspect that carries a use-by time' => ['inspect', 200, 'expiresAt'],
            'an abandon that carries an invocation ID' => ['abandon', 200, 'invocationId'],
            'an abandon that carries a use-by time' => ['abandon', 200, 'expiresAt'],
        ]);
    }

    /**
     * @param array<string, string[]> $fieldsByVerb
     * @return array<string, array{string, string}>
     */
    protected static function fields(array $fieldsByVerb): array
    {
        $rows = [];

        foreach ($fieldsByVerb as $verb => $fields) {
            foreach ($fields as $field) {
                $rows["{$verb}, {$field}"] = [$verb, $field];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed> a message of the verb that carries every field the verb can
     */
    protected function message(string $verb): array
    {
        return match ($verb) {
            'start' => $this->start(),
            'wake' => $this->wake(attempt: 1),
            'inspect' => $this->inspect(self::RUN_ID),
            default => $this->abandon(attempt: 1),
        };
    }
}
