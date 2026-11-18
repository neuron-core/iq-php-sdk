<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use Closure;
use Error;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\Faults\SecretiveWorkflow;
use NeuronCore\IQ\Tests\Stub\Requests\FailingQueue;
use NeuronCore\IQ\Tests\Stub\Requests\UnencodableRunWorkflow;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;
use NeuronCore\IQ\Tests\Stub\Runner\StoreUnavailable;
use NeuronCore\IQ\Tests\Support\Faults\Markers;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_column;
use function array_map;
use function base64_encode;
use function dirname;
use function json_encode;
use function serialize;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Step 7 of the endpoint, and "what crosses the wire": whatever fails behind
 * handle(), it answers a status of the table, and no answer holds the message,
 * the class or the file of what was thrown, nor anything a workflow keeps.
 */
class NothingLeaksTest extends RequestTestCase
{
    /** What everything that fails here says: no answer of the endpoint may repeat a word of it. */
    protected const FAILURE = 'MARKER-7c41e9 SQLSTATE[HY000] [2002] Connection refused to db.internal for user shop, card 4111111111111111';

    /** What tells an answer that speaks of the application's code. */
    protected const TRACES = ['Exception', 'Error', 'Unavailable', 'NeuronCore', 'NeuronAI', 'Stub', '.php', 'Stack', '#0', ' line '];

    /**
     * @param Closure(self): void $break
     */
    #[DataProvider('failuresBehindEachVerb')]
    public function test_whatever_fails_behind_the_endpoint_is_answered_a_status_of_the_table_that_says_nothing_of_it(
        string $backend,
        Closure $break,
        string $verb,
        int $status,
    ): void {
        $this->shop($backend);
        $break($this);

        $answer = $this->deliver($this->message($verb));

        self::assertSame($status, $answer->statusCode);
        self::assertSame([], $this->leaksIn([$answer], ['what was thrown' => self::FAILURE]));
    }

    #[DataProvider('backends')]
    public function test_no_answer_about_a_run_holds_what_its_workflow_keeps(string $backend): void
    {
        $this->shop($backend);
        $this->iq->register(self::DEFINITION, static fn (): Workflow => new SecretiveWorkflow());

        $answers = $this->askAtEveryStageOfTheSecretiveRun();

        self::assertSame(
            ['suspended', 'suspended', 'suspended', 'suspended', 'suspended', 'suspended', 'suspended', 'suspended', 'suspended', 'failed', 'failed', 'failed'],
            array_column(array_map(static fn (HttpResponse $answer): array => self::fieldsOf($answer)['run'], $answers), 'status'),
        );
        self::assertSame([], $this->leaksIn($answers, Markers::all()));
    }

    #[DataProvider('backends')]
    public function test_an_approval_is_shown_as_the_number_the_type_the_event_name_and_the_deadline_of_its_wait(string $backend): void
    {
        $this->shop($backend);
        $this->iq->register(self::DEFINITION, static fn (): Workflow => new SecretiveWorkflow());
        $this->deliver($this->start());
        $this->work();

        $answer = $this->deliver($this->inspect());

        self::assertSame(
            ['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'approval', 'wakeAt' => null],
            self::fieldsOf($answer)['run']['wait'],
        );
    }

    /**
     * @return array<string, array<int, mixed>> the backend, what breaks, the verb, the status it is answered
     */
    public static function failuresBehindEachVerb(): array
    {
        $failures = [
            'a factory that throws an exception' => [
                static function (self $test): void {
                    $test->iq->register(self::DEFINITION, static function (): never {
                        throw new RuntimeException(self::FAILURE);
                    });
                },
                ['start' => 202, 'wake' => 202, 'inspect' => 200, 'abandon' => 503, 'ping' => 200],
            ],
            'a factory that throws an Error' => [
                static function (self $test): void {
                    $test->iq->register(self::DEFINITION, static function (): never {
                        throw new Error(self::FAILURE);
                    });
                },
                ['start' => 202, 'wake' => 202, 'inspect' => 200, 'abandon' => 503, 'ping' => 200],
            ],
            'a persistence factory that throws' => [
                static function (self $test): void {
                    $test->iq->setPersistence(static function (): never {
                        throw new StoreUnavailable(self::FAILURE);
                    });
                },
                ['start' => 202, 'wake' => 202, 'inspect' => 200, 'abandon' => 503, 'ping' => 200],
            ],
            'a store whose every operation throws' => [
                static function (self $test): void {
                    $persistence = $test->bench->persistence;
                    $test->iq->setPersistence(static fn (): PersistenceInterface => new InterceptedPersistence(
                        $persistence(),
                        static fn () => throw new StoreUnavailable(self::FAILURE),
                    ));
                },
                ['start' => 202, 'wake' => 202, 'inspect' => 200, 'abandon' => 503, 'ping' => 200],
            ],
            'a queue that throws' => [
                static function (self $test): void {
                    $test->iq->setDispatcher(new FailingQueue(new RuntimeException(self::FAILURE)));
                },
                ['start' => 503, 'wake' => 503, 'inspect' => 200, 'abandon' => 200, 'ping' => 200],
            ],
            'an answer that cannot be encoded' => [
                static function (self $test): void {
                    $test->iq->register(self::DEFINITION, static fn (): Workflow => new UnencodableRunWorkflow());
                },
                ['start' => 202, 'wake' => 202, 'inspect' => 500, 'abandon' => 500, 'ping' => 200],
            ],
        ];
        $rows = [];

        foreach ($failures as $failure => [$break, $statuses]) {
            foreach ($statuses as $verb => $status) {
                $rows["{$verb} with {$failure}"] = [$break, $verb, $status];
            }
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

    /**
     * The secretive run is played to each of its waits and to its failure, and at each the platform asks about
     * it: with the run ID, without, and with an abandon the run refuses, which shows the run too.
     *
     * @return HttpResponse[] three answers at the approval, at the event, at the timer and after the failure
     */
    protected function askAtEveryStageOfTheSecretiveRun(): array
    {
        $this->deliver($this->start());
        [$approval] = $this->work();
        $answers = $this->ask();

        $event = $this->iq->invoke($this->continuation(Verb::Answer, $approval, ['payload' => base64_encode(serialize(['call_1' => ['reject', Markers::ANSWER]]))]));
        $answers = [...$answers, ...$this->ask()];

        $timer = $this->iq->invoke($this->continuation(Verb::Signal, $event, [
            'signal' => SecretiveWorkflow::EVENT,
            'payload' => base64_encode(serialize(['reference' => Markers::SIGNAL])),
        ]));
        $answers = [...$answers, ...$this->ask()];

        // The timer is due, and the node after it throws an exception whose message is a marker.
        $this->deliver($this->wake((int) ($timer->run['attempt'] ?? 0)));
        $this->work();

        return [...$answers, ...$this->ask()];
    }

    /**
     * @return HttpResponse[]
     */
    protected function ask(): array
    {
        return [
            $this->deliver($this->inspect(self::RUN_ID)),
            $this->deliver($this->inspect()),
            $this->deliver($this->abandon(self::RUN_ID, attempt: 99)),
        ];
    }

    /**
     * An answer or a signal of the application, fenced with the run a report showed.
     *
     * @param array<string, mixed> $input the payload of an answer; the event name and the payload of a signal
     */
    protected function continuation(Verb $verb, Report $seen, array $input): Invocation
    {
        return Invocation::fromArray($input + [
            'invocationId' => 'inv_' . $verb->value,
            'verb' => $verb->value,
            'origin' => 'application',
            'definition' => $seen->definition,
            'workflowId' => $seen->workflowId,
            'runId' => $seen->run['runId'] ?? null,
            'attempt' => $seen->run['attempt'] ?? null,
        ]);
    }

    /**
     * @param HttpResponse[] $answers
     * @param array<string, string> $planted where it was planted => what must not leave the application
     * @return string[] every leak found in the status line, the headers or the body of an answer
     */
    protected function leaksIn(array $answers, array $planted): array
    {
        $leaks = [];
        $traces = [...self::TRACES, dirname(__DIR__, 3)];

        foreach ($answers as $answer) {
            $said = json_encode([$answer->headers, $answer->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            foreach ($planted as $where => $secret) {
                if (str_contains($said, $secret)) {
                    $leaks[] = "{$where} left the application in {$said}";
                }
            }

            foreach ($traces as $trace) {
                if (str_contains($said, $trace)) {
                    $leaks[] = "'{$trace}' speaks of the application's code in {$said}";
                }
            }
        }

        return $leaks;
    }
}
