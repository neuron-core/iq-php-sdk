<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * "The platform sends three orders and asks two questions. Nothing else comes
 * in": a signed message under any other verb is answered 400 invalid_request.
 * Each one here carries every field a segment could need, so that it would be
 * queued if its verb were taken.
 */
class VerbTest extends RequestTestCase
{
    #[DataProvider('verbsOfTheApplicationsOwnQueue')]
    public function test_a_verb_that_exists_only_in_the_applications_queue_is_answered_400_invalid_request_and_does_nothing(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->segment(['verb' => $verb]));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('verbsTheProtocolDoesNotHave')]
    public function test_a_verb_the_protocol_does_not_have_is_answered_400_invalid_request_and_does_nothing(string $backend, mixed $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->segment(['verb' => $verb]));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_message_without_a_verb_is_answered_400_invalid_request_and_does_nothing(string $backend): void
    {
        $this->shop($backend);
        $fields = $this->segment();
        unset($fields['verb']);

        $answer = $this->deliver($fields);

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function verbsOfTheApplicationsOwnQueue(): array
    {
        return self::onEveryBackend([
            'answer, which is reserved' => ['answer'],
            'signal, which is reserved' => ['signal'],
            'announce' => ['announce'],
            'observe' => ['observe'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function verbsTheProtocolDoesNotHave(): array
    {
        return self::onEveryBackend([
            'an unknown verb' => ['retire'],
            'an empty verb' => [''],
            'start in upper case' => ['START'],
            'start with a capital' => ['Start'],
            'start followed by a space' => ['start '],
            'start followed by a line feed' => ["start\n"],
            'start followed by a NUL' => ["start\u{0}"],
            'two verbs' => ['start,wake'],
            'the name of a field' => ['runId'],
            'a number' => [1],
            'zero' => [0],
            'true' => [true],
            'null' => [null],
            'a list that holds a verb' => [['start']],
            'an object' => [['start' => true]],
        ]);
    }

    /**
     * Everything an invocation of the queue can hold, in the form the wire would carry it.
     *
     * @param array<string, mixed> $fields the fields that differ
     * @return array<string, mixed>
     */
    protected function segment(array $fields = []): array
    {
        return $fields + [
            'attempt' => 1,
            'origin' => 'application',
            'signal' => 'payment.received',
            'payload' => ['amount' => 120],
            'event' => json_encode(['amount' => 120], JSON_THROW_ON_ERROR),
            'body' => ['invocationId' => 'inv_start', 'verb' => 'start'],
            'expiresAt' => time() + 900,
        ] + $this->start();
    }
}
