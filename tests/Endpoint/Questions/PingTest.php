<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Questions;

use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Support\Questions\QuestionsTestCase;
use NeuronCore\Cloud\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

use function count;

/**
 * A ping asks only whether the endpoint verifies the platform's signature:
 * it is answered whatever else the application has, or has not, set up.
 */
class PingTest extends QuestionsTestCase
{
    #[DataProvider('backends')]
    public function test_a_ping_is_answered_200_with_the_protocols_and_no_other_field(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver(['verb' => 'ping']);

        self::assertSame([200, '{"protocols":[1]}'], [$answer->statusCode, $answer->body]);
    }

    public function test_a_ping_is_answered_by_an_endpoint_that_was_given_no_persistence_no_dispatcher_and_no_definition(): void
    {
        $platform = $this->benchOn(TestBench::IN_MEMORY)->platform;
        $bare = new NeuronCloud(TestBench::API_KEY, $platform->signingKey);

        $answer = $platform->deliver($bare, ['verb' => 'ping']);

        self::assertSame([200, ['protocols' => [1]]], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_a_ping_is_answered_while_the_store_is_down(string $backend): void
    {
        $this->ordersOn($backend);
        $this->failTheStore();

        $answer = $this->deliver(['verb' => 'ping']);

        self::assertSame([200, ['protocols' => [1]]], [$answer->statusCode, self::fieldsOf($answer)]);
    }

    #[DataProvider('backends')]
    public function test_a_ping_asks_nothing_of_the_store(string $backend): void
    {
        $this->ordersOn($backend);
        $operations = $this->watchTheStore();

        $this->deliver(['verb' => 'ping']);

        self::assertCount(0, $operations);
    }

    #[DataProvider('backends')]
    public function test_a_ping_puts_nothing_on_the_queue_and_sends_the_platform_nothing(string $backend): void
    {
        $bench = $this->ordersOn($backend);

        $this->deliver(['verb' => 'ping']);

        self::assertSame([[], 0], [$this->work(), count($bench->platform->received())]);
    }

    #[DataProvider('backends')]
    public function test_a_ping_is_answered_whatever_else_its_body_carries(string $backend): void
    {
        $this->ordersOn($backend);

        $answer = $this->deliver(['verb' => 'ping', 'definition' => 'nightly-reconciliation', 'workflowId' => '__reserved', 'runId' => 7]);

        self::assertSame([200, ['protocols' => [1]]], [$answer->statusCode, self::fieldsOf($answer)]);
    }
}
