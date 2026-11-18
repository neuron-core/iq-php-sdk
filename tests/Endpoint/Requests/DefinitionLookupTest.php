<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 4 of the endpoint: a definition this deployment lacks is a 404 for
 * every verb that names one; a version it lacks is a 404 for a start and a
 * wake only, because an inspect and an abandon read and free a store that
 * every version shares.
 */
class DefinitionLookupTest extends RequestTestCase
{
    protected const UNKNOWN_DEFINITION = [404, '{"code":"unknown_definition"}'];

    protected const UNKNOWN_VERSION = [404, '{"code":"unknown_version"}'];

    /** A run of version 7, which nothing registers. */
    protected const RUN_OF_A_LATER_DEPLOY = '7-0192f3a8-7c1e';

    #[DataProvider('verbsThatNameADefinition')]
    public function test_a_message_for_a_definition_this_deployment_lacks_is_answered_404_unknown_definition_and_does_nothing(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->message($verb, self::RUN_ID));

        self::assertSame(self::UNKNOWN_DEFINITION, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('queuedVerbs')]
    public function test_a_start_and_a_wake_on_a_version_this_deployment_lacks_are_answered_404_unknown_version_and_do_nothing(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->message($verb, self::RUN_OF_A_LATER_DEPLOY));

        self::assertSame(self::UNKNOWN_VERSION, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('verbsAnsweredInTheRequest')]
    public function test_an_inspect_and_an_abandon_of_a_run_on_a_version_this_deployment_lacks_are_answered_200(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver($this->message($verb, self::RUN_OF_A_LATER_DEPLOY));

        self::assertSame([200, true], [$answer->statusCode, self::fieldsOf($answer)['snapshotRead']]);
    }

    #[DataProvider('verbsThatNameADefinition')]
    public function test_a_malformed_message_for_a_definition_this_deployment_lacks_is_answered_400_not_404(string $backend, string $verb): void
    {
        $this->shop($backend);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation', 'workflowId' => '__order'] + $this->message($verb, self::RUN_ID));

        self::assertSame(self::INVALID_REQUEST, self::statusAndBody($answer));
    }

    #[DataProvider('queuedVerbs')]
    public function test_a_start_and_a_wake_on_an_older_version_this_deployment_still_has_are_queued(string $backend, string $verb): void
    {
        $this->shop($backend);
        $this->iq->register(self::DEFINITION, static fn (): Workflow => new Workflow(), version: 2);

        $answer = $this->deliver($this->message($verb, self::RUN_ID));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_the_sdk_did_not_mint_is_queued_by_a_deployment_that_begins_at_version_2(string $backend): void
    {
        $this->shop($backend);
        $this->iq->register('nightly-reconciliation', static fn (): Workflow => new Workflow(), version: 2);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->message('wake', '0192f3a8-7c1e'));

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_a_wake_of_a_run_of_version_1_is_answered_404_by_a_deployment_that_begins_at_version_2(string $backend): void
    {
        $this->shop($backend);
        $this->iq->register('nightly-reconciliation', static fn (): Workflow => new Workflow(), version: 2);

        $answer = $this->deliver(['definition' => 'nightly-reconciliation'] + $this->message('wake', self::RUN_ID));

        self::assertSame(self::UNKNOWN_VERSION, self::statusAndBody($answer));
    }

    public function test_a_ping_is_answered_by_a_deployment_that_registers_nothing(): void
    {
        $this->shop(TestBench::IN_MEMORY);
        $this->iq = new IQ(TestBench::API_KEY, $this->bench->platform->signingKey);

        $answer = $this->post(self::PING);

        self::assertSame([200, '{"protocols":[1]}'], self::statusAndBody($answer));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function verbsThatNameADefinition(): array
    {
        return self::onEveryBackend(['a start' => ['start'], 'a wake' => ['wake'], 'an inspect' => ['inspect'], 'an abandon' => ['abandon']]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function queuedVerbs(): array
    {
        return self::onEveryBackend(['a start' => ['start'], 'a wake' => ['wake']]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function verbsAnsweredInTheRequest(): array
    {
        return self::onEveryBackend(['an inspect' => ['inspect'], 'an abandon' => ['abandon']]);
    }

    /**
     * @return array<string, mixed> a well-formed message of the verb about one run
     */
    protected function message(string $verb, string $runId): array
    {
        return match ($verb) {
            'start' => $this->start(['runId' => $runId]),
            'wake' => $this->wake(attempt: 1, fields: ['runId' => $runId]),
            'inspect' => $this->inspect($runId),
            default => $this->abandon($runId),
        };
    }
}
