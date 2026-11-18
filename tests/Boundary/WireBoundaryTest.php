<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Boundary;

use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Runtime\Origin;
use NeuronCore\IQ\Tests\Stub\CompletingWorkflow;
use NeuronCore\IQ\Tests\Stub\Faults\FaultyPersistence;
use NeuronCore\IQ\Tests\Stub\Faults\SecretiveWorkflow;
use NeuronCore\IQ\Tests\Stub\Faults\SecretStart;
use NeuronCore\IQ\Tests\Stub\Faults\StoreFailed;
use NeuronCore\IQ\Tests\Stub\Faults\ThrowAnonymousNode;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\Faults\FaultMode;
use NeuronCore\IQ\Tests\Support\Faults\Markers;
use NeuronCore\IQ\Tests\Support\Faults\StoreFault;
use NeuronCore\IQ\Tests\Support\Faults\StoreJournal;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

use function array_column;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function base64_encode;
use function dirname;
use function is_array;
use function json_encode;
use function parse_url;
use function preg_match;
use function serialize;
use function str_contains;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_PATH;

/**
 * "What crosses the wire" (section 1 of the design): a workflow that holds a
 * marker everywhere it keeps the application's data is driven through every
 * verb, and what the platform was sent is read back.
 *
 * The scenario plays one run the platform starts, with the platform
 * answering; one run the application starts with an event it carries, with
 * the platform refusing every announcement and report first, so that all of
 * them reach it from the queue; and the starts that never run.
 */
class WireBoundaryTest extends IQTestCase
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    /** The fields appendix A lists for each message. */
    protected const FIELDS = [
        self::ANNOUNCEMENTS => ['protocol', 'invocationId', 'verb', 'origin', 'definition', 'workflowId', 'runId', 'attempt', 'leaseSeconds', 'at'],
        self::REPORTS => [
            'protocol', 'invocationId', 'verb', 'origin', 'definition', 'workflowId', 'runId', 'outcome', 'code',
            'errorClass', 'executedAttempt', 'run', 'claimed', 'snapshotRead', 'at',
        ],
    ];

    protected const RUN_FIELDS = ['runId', 'definition', 'status', 'attempt', 'leaseExpiresAt', 'wait'];

    protected const WAIT_FIELDS = ['interruptId', 'type', 'eventName', 'wakeAt'];

    protected StoreJournal $journal;

    protected StoreFault $fault;

    protected int $invocations = 0;

    #[DataProvider('backends')]
    public function test_nothing_planted_in_a_workflow_reaches_the_platform(string $backend): void
    {
        $bench = $this->playEveryVerb($backend);
        $secrets = Markers::all() + ['the start event a queued start carries' => $this->carriedStart()];
        $leaks = [];

        foreach ($bench->platform->received() as $request) {
            $sent = json_encode([$request->uri, $request->headers, $request->body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            foreach ($secrets as $where => $secret) {
                if (str_contains($sent, $secret)) {
                    $leaks[] = "What was planted in {$where} left the application in {$sent}";
                }
            }
        }

        self::assertSame([], $leaks);
    }

    #[DataProvider('backends')]
    public function test_a_request_carries_only_the_fields_the_protocol_lists_for_its_message(string $backend): void
    {
        $unlisted = [];

        foreach ($this->playEveryVerb($backend)->platform->received() as $request) {
            $body = is_array($request->body) ? $request->body : [];
            $run = is_array($body['run'] ?? null) ? $body['run'] : [];
            $wait = is_array($run['wait'] ?? null) ? $run['wait'] : [];

            $unlisted = [
                ...$unlisted,
                ...$this->beyond($body, self::FIELDS[(string) parse_url($request->uri, PHP_URL_PATH)] ?? [], ['run']),
                ...$this->beyond($run, self::RUN_FIELDS, ['wait']),
                ...$this->beyond($wait, self::WAIT_FIELDS),
            ];
        }

        self::assertSame([], array_values(array_unique($unlisted)));
    }

    #[DataProvider('backends')]
    public function test_a_request_is_a_post_to_one_of_the_two_urls_of_the_protocol_with_its_two_headers(string $backend): void
    {
        $strays = [];

        foreach ($this->playEveryVerb($backend)->platform->received() as $request) {
            $listed = $request->method === HttpMethod::POST
                && ($request->uri === TestBench::PLATFORM_URL . self::ANNOUNCEMENTS || $request->uri === TestBench::PLATFORM_URL . self::REPORTS)
                && array_keys($request->headers) === ['Authorization', 'User-Agent']
                && $request->headers['Authorization'] === 'Bearer ' . TestBench::API_KEY
                && preg_match('#^iq-php/\d+\.\d+\.\d+$#D', $request->headers['User-Agent']) === 1;

            if (!$listed) {
                $strays[] = json_encode([$request->method->value, $request->uri, $request->headers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }

        self::assertSame([], array_values(array_unique($strays)));
    }

    #[DataProvider('backends')]
    public function test_the_scenario_reaches_the_platform_with_every_verb_and_every_outcome(string $backend): void
    {
        $bench = $this->playEveryVerb($backend);
        $segments = [Verb::Start->value, Verb::Wake->value, Verb::Answer->value, Verb::Signal->value];
        $reports = $this->recorded($bench, self::REPORTS);

        self::assertEqualsCanonicalizing($segments, array_values(array_unique(array_column($this->recorded($bench, self::ANNOUNCEMENTS), 'verb'))));
        self::assertEqualsCanonicalizing($segments, array_values(array_unique(array_column($reports, 'verb'))));
        self::assertEqualsCanonicalizing(
            array_map(static fn (Outcome $outcome): string => $outcome->value, Outcome::cases()),
            array_values(array_unique(array_column($reports, 'outcome'))),
        );
        $bench->dispatcher->assertDispatched(Verb::Announce);
        $bench->dispatcher->assertDispatched(Verb::Observe);
    }

    #[DataProvider('backends')]
    public function test_every_marker_was_in_the_store_of_the_application(string $backend): void
    {
        $this->playEveryVerb($backend);

        $neverStored = array_filter(Markers::stored(), fn (string $marker): bool => !$this->journal->wrote($marker));

        self::assertSame([], array_keys($neverStored), 'The boundary would be checked on values no run ever held.');
    }

    #[DataProvider('backends')]
    public function test_a_failure_reaches_the_platform_as_the_class_of_its_exception(string $backend): void
    {
        $reports = $this->recorded($this->playEveryVerb($backend), self::REPORTS);

        $failed = array_filter($reports, static fn (array $report): bool => $report['outcome'] === Outcome::Failed->value);
        $aborted = array_filter($reports, static fn (array $report): bool => $report['outcome'] === Outcome::Aborted->value);

        self::assertSame([NodeFailed::class], array_values(array_unique(array_column($failed, 'errorClass'))));
        self::assertEqualsCanonicalizing([StoreFailed::class, NodeFailed::class], array_values(array_unique(array_column($aborted, 'errorClass'))));
    }

    #[DataProvider('backends')]
    public function test_an_approval_reaches_the_platform_as_the_number_the_type_the_event_name_and_the_deadline_of_its_wait(string $backend): void
    {
        $reports = $this->recorded($this->playEveryVerb($backend), self::REPORTS);

        $waits = array_column(array_column($reports, 'run'), 'wait');

        self::assertContains(['interruptId' => 1, 'type' => 'wait_for_event', 'eventName' => 'approval', 'wakeAt' => null], $waits);
    }

    public function test_an_exception_of_an_anonymous_class_reaches_the_platform_without_the_path_of_its_file(): void
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);
        $bench->iq->register('nameless', static fn (): Workflow => (new Workflow())->addNode(new ThrowAnonymousNode()));

        $bench->iq->invoke($this->start(Origin::PLATFORM, 'nameless', 'case:nameless'));

        $errorClass = (string) $this->recorded($bench, self::REPORTS)[0]['errorClass'];
        $sent = 'The platform was sent the class name ' . json_encode($errorClass, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . '.';
        self::assertStringNotContainsString(dirname((string) (new ReflectionClass(ThrowAnonymousNode::class))->getFileName()), $errorClass, $sent);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $errorClass, $sent);
    }

    protected function playEveryVerb(string $backend): TestBench
    {
        $bench = $this->benchOn($backend);
        $this->journal = new StoreJournal();
        $this->fault = StoreFault::none();
        $executions = $bench->executions;

        $bench->iq
            ->setPersistence(fn (): PersistenceInterface => new FaultyPersistence(($bench->persistence)(), $this->journal, $this->fault))
            ->register('vault', static fn (): SecretiveWorkflow => new SecretiveWorkflow())
            ->register('greeting', static fn (): CompletingWorkflow => new CompletingWorkflow($executions))
            ->register('broken', static function (): never {
                throw new NodeFailed(Markers::EXCEPTION);
            });

        $this->playRun($this->start(Origin::PLATFORM, 'vault', 'case:1'), platformDown: false);
        $this->playRun($this->start(Origin::APPLICATION, 'vault', 'case:2', ['event' => $this->carriedStart()]), platformDown: true);
        $this->playTheStartsThatNeverRun();

        return $bench;
    }

    /**
     * One run of the secretive workflow from its start to its end, with a
     * second copy of the start, of the answer and of the last wake.
     */
    protected function playRun(Invocation $start, bool $platformDown): void
    {
        $approval = $this->deliver($start, $platformDown);
        $this->deliver($start, $platformDown);
        // As after a job that died: a worker observes the start, with no outcome to carry.
        $this->bench->iq->invoke($this->observe($start));

        $answer = $this->continuation(Verb::Answer, $approval, ['payload' => base64_encode(serialize(['call_1' => ['reject', Markers::ANSWER]]))]);
        $event = $this->deliver($answer, $platformDown);
        $this->deliver($answer, $platformDown);

        $signal = $this->continuation(Verb::Signal, $event, ['signal' => SecretiveWorkflow::EVENT, 'payload' => base64_encode(serialize(['reference' => Markers::SIGNAL]))]);
        $timer = $this->deliver($signal, $platformDown);

        // The timer is due and the node after it throws; the next wake retries and completes.
        $failed = $this->deliver($this->continuation(Verb::Wake, $timer), $platformDown);
        $retry = $this->continuation(Verb::Wake, $failed);
        $this->deliver($retry, $platformDown);
        $this->deliver($retry, $platformDown);
    }

    protected function playTheStartsThatNeverRun(): void
    {
        $iq = $this->bench->iq;

        // Rejected: no node of "greeting" handles the event the start carries.
        $iq->invoke($this->start(Origin::APPLICATION, 'greeting', 'case:invalid', ['event' => $this->carriedStart()]));
        // Rejected: past its use-by time.
        $iq->invoke($this->start(Origin::PLATFORM, 'vault', 'case:late', ['expiresAt' => time() - 1]));

        // Aborted: the store fails at the claim.
        $this->fault = StoreFault::at(FaultMode::BeforeOperation, 1);
        $iq->invoke($this->start(Origin::PLATFORM, 'vault', 'case:down'));
        $this->fault = StoreFault::none();

        // Aborted: the factory throws.
        $iq->invoke($this->start(Origin::PLATFORM, 'broken', 'case:broken'));
    }

    /**
     * Give an order to invoke(). With the platform down it refuses the
     * announcement and the report of the segment, and a worker then posts
     * the announce and the observe the job left in the queue.
     */
    protected function deliver(Invocation $order, bool $platformDown): Report
    {
        if (!$platformDown) {
            return $this->bench->iq->invoke($order);
        }

        $this->bench->platform->failNext(2);
        $report = $this->bench->iq->invoke($order);
        $this->bench->dispatcher->runAll($this->bench->iq);

        return $report;
    }

    /**
     * @param array<string, mixed> $more fields that replace the ones of a start the platform sends
     */
    protected function start(string $origin, string $definition, string $workflowId, array $more = []): Invocation
    {
        return Invocation::fromArray($more + [
            'invocationId' => 'inv_' . ++$this->invocations,
            'verb' => Verb::Start->value,
            'origin' => $origin,
            'definition' => $definition,
            'workflowId' => $workflowId,
            'runId' => '1-run-' . $this->invocations,
            'expiresAt' => time() + 900,
        ]);
    }

    /**
     * A wake as the platform fences it, or an answer or a signal as the
     * application does: with the run ID and the attempt a report showed.
     *
     * @param array<string, mixed> $input the payload of an answer; the event name and the payload of a signal
     */
    protected function continuation(Verb $verb, Report $seen, array $input = []): Invocation
    {
        return Invocation::fromArray($input + [
            'invocationId' => 'inv_' . ++$this->invocations,
            'verb' => $verb->value,
            'origin' => $verb === Verb::Wake ? Origin::PLATFORM : Origin::APPLICATION,
            'definition' => $seen->definition,
            'workflowId' => $seen->workflowId,
            'runId' => $seen->run['runId'] ?? null,
            'attempt' => $seen->run['attempt'] ?? null,
        ]);
    }

    protected function observe(Invocation $segment): Invocation
    {
        return Invocation::fromArray([
            'invocationId' => 'obs_' . ++$this->invocations,
            'verb' => Verb::Observe->value,
            'body' => ['invocationId' => $segment->invocationId, 'verb' => $segment->verb->value],
        ] + $segment->toArray());
    }

    /**
     * The start event of a queued start, as the invocation carries it.
     */
    protected function carriedStart(): string
    {
        return base64_encode(serialize(new SecretStart(Markers::START_EVENT)));
    }

    /**
     * @param array<array-key, mixed> $object
     * @param string[] $listed
     * @param string[] $objects the listed fields that hold an object of their own
     * @return string[] what the object carries beyond its listed fields
     */
    protected function beyond(array $object, array $listed, array $objects = []): array
    {
        $beyond = array_map(static fn (int|string $field): string => "an unlisted field '{$field}'", array_diff(array_keys($object), $listed));

        foreach ($object as $field => $value) {
            if (is_array($value) && array_diff([$field], $objects) !== []) {
                $beyond[] = "a structure in '{$field}'";
            }
        }

        return $beyond;
    }

    /**
     * @return array<int, array<string, mixed>> the bodies sent to a path of the platform
     */
    protected function recorded(TestBench $bench, string $path): array
    {
        $bodies = [];

        foreach ($bench->platform->received() as $request) {
            if (parse_url($request->uri, PHP_URL_PATH) === $path && is_array($request->body)) {
                $bodies[] = $request->body;
            }
        }

        return $bodies;
    }
}
