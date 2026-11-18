<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function str_repeat;

/**
 * "For a platform message the invocation ID equals the webhook-id header":
 * the ID a start or a wake carries in its body must be the one its signature
 * covers. A question and an abandon carry none.
 */
class InvocationIdTest extends RequestTestCase
{
    #[DataProvider('queuedVerbs')]
    public function test_a_start_and_a_wake_are_queued_under_the_webhook_id_they_were_signed_with(string $backend, string $verb): void
    {
        $this->shop($backend);
        $body = self::encode(['invocationId' => 'inv_01JB7K4T9QW2'] + $this->message($verb));

        $answer = $this->post($body, $this->signed($body, 'inv_01JB7K4T9QW2'));

        self::assertSame(202, $answer->statusCode);
        self::assertSame(['inv_01JB7K4T9QW2'], array_map(static fn (Invocation $queued): string => $queued->invocationId, $this->queue->dispatched()));
    }

    #[DataProvider('webhookIdsOfAnotherMessage')]
    public function test_a_start_whose_invocation_id_is_not_its_webhook_id_is_answered_400_invalid_request_and_does_nothing(string $backend, string $webhookId): void
    {
        $this->shop($backend);
        $body = self::encode(['invocationId' => 'inv_01JB7K4T9QW2'] + $this->start());

        $answer = $this->post($body, $this->signed($body, $webhookId));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('webhookIdsOfAnotherMessage')]
    public function test_a_wake_whose_invocation_id_is_not_its_webhook_id_is_answered_400_invalid_request_and_does_nothing(string $backend, string $webhookId): void
    {
        $this->shop($backend);
        $body = self::encode(['invocationId' => 'inv_01JB7K4T9QW2'] + $this->wake(attempt: 1));

        $answer = $this->post($body, $this->signed($body, $webhookId));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('webhookIdsThatAreNoInvocationId')]
    public function test_a_message_answered_in_the_request_is_answered_under_any_webhook_id(string $backend, string $verb, string $webhookId): void
    {
        $this->shop($backend);
        $body = self::encode($this->message($verb));

        $answer = $this->post($body, $this->signed($body, $webhookId));

        self::assertSame(200, $answer->statusCode);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function queuedVerbs(): array
    {
        return self::onEveryBackend(['a start' => ['start'], 'a wake' => ['wake']]);
    }

    /**
     * @return array<string, array<int, mixed>> a webhook-id that is not "inv_01JB7K4T9QW2"
     */
    public static function webhookIdsOfAnotherMessage(): array
    {
        return self::onEveryBackend([
            'the ID of another message' => ['inv_01JB7K4T9QW3'],
            'the same ID in another case' => ['INV_01JB7K4T9QW2'],
            'the same ID followed by a space' => ['inv_01JB7K4T9QW2 '],
            'the same ID, longer' => ['inv_01JB7K4T9QW2_2'],
            'the same ID, cut short' => ['inv_01JB7K4T9QW'],
            'an ID of the platform for a question' => ['msg_01JB7K4T9QW2'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the verb, a webhook-id outside the format of an invocation ID
     */
    public static function webhookIdsThatAreNoInvocationId(): array
    {
        $rows = [];

        foreach (['inspect', 'abandon', 'ping'] as $verb) {
            $rows += [
                "{$verb} under an ID with dots and spaces" => [$verb, 'msg 2026.10.07 #42'],
                "{$verb} under an ID of 200 characters" => [$verb, str_repeat('m', 200)],
            ];
        }

        return self::onEveryBackend($rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(string $verb): array
    {
        return match ($verb) {
            'start' => $this->start(),
            'wake' => $this->wake(attempt: 1),
            'inspect' => $this->inspect(self::RUN_ID),
            'abandon' => $this->abandon(),
            default => ['verb' => 'ping'],
        };
    }
}
