<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\UniqueIdGenerator;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Runtime\Protocol;
use NeuronCore\Cloud\Runtime\Signature;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Assert;

use function array_column;
use function array_fill;
use function array_filter;
use function array_map;
use function array_shift;
use function array_values;
use function base64_encode;
use function end;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function parse_url;
use function random_bytes;
use function rawurldecode;
use function str_starts_with;
use function strlen;
use function substr;
use function time;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * Neuron Cloud as the SDK sees it over HTTP: it records what it is told and
 * answers as the platform would. Give it to NeuronCloud as its HTTP client.
 * It takes in traces and the results of evaluations, which assertTraced()
 * and assertEvaluated() look for, and answers the read of a dataset with the
 * items keepDataset() gave it. It also plays the platform's side of the
 * endpoint, with deliver() and wake().
 */
class FakePlatform implements HttpClientInterface
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    protected const SYNC = '/v1/sync';

    protected const TRACES = '/v1/traces';

    protected const EVALUATIONS = '/v1/evaluations';

    /** Followed by the slug of the dataset. */
    protected const DATASETS = '/v1/datasets/';

    public readonly string $signingKey;

    /** @var array<int, array{request: HttpRequest, status: int}> every request received, with the status it was answered */
    protected array $requests = [];

    /** @var int[] the statuses the next requests are answered with, instead of being recorded */
    protected array $failures = [];

    /** @var array<string, list<array{input: mixed, expected_output: mixed}>> the items of every dataset kept, by slug, as the platform answers them */
    protected array $datasets = [];

    public function __construct()
    {
        $this->signingKey = 'whsec_' . base64_encode(random_bytes(24));
    }

    /**
     * Answer the next $times requests with $status and record nothing of them.
     */
    public function failNext(int $times = 1, int $status = 503): static
    {
        $this->failures = [...$this->failures, ...array_fill(0, $times, $status)];

        return $this;
    }

    /**
     * Keep a dataset: from now on a read of the slug is answered as the platform
     * answers it, with these items in this order, as a list whatever their keys.
     * Each is answered as the platform answers an item, with its input and its
     * expected output alone: an expected output it was not given is null, and
     * any other key is dropped. A failure failNext() queued still comes first,
     * and a slug it was not given is answered 404.
     *
     * @param array<int, array<array-key, mixed>> $items each as the platform holds it: ['input' => ..., 'expected_output' => ...]
     */
    public function keepDataset(string $slug, array $items): static
    {
        $this->datasets[$slug] = array_map(
            static fn (array $item): array => ['input' => $item['input'] ?? null, 'expected_output' => $item['expected_output'] ?? null],
            array_values($items),
        );

        return $this;
    }

    /**
     * Send the endpoint one message, signed as the platform signs it.
     *
     * @param array<string, mixed> $message the body; "protocol" is 1 unless the message says otherwise
     * @param int|null $timestamp when the message was signed; null: now
     */
    public function deliver(NeuronCloud $cloud, array $message, ?int $timestamp = null): HttpResponse
    {
        $body = json_encode($message + ['protocol' => Protocol::VERSION], JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        // The invocation ID of a start or a wake is its webhook-id; a question and an abandon carry none.
        $id = is_string($message['invocationId'] ?? null) ? $message['invocationId'] : UniqueIdGenerator::generateId('msg_');

        return $cloud->handle('POST', [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (string) (new Signature($this->signingKey))->sign($id, $timestamp, $body),
        ], $body);
    }

    /**
     * Send the endpoint a wake of the run that holds a workflow ID, fenced
     * with the run ID and the attempt of the last report recorded for it.
     */
    public function wake(NeuronCloud $cloud, string $workflowId): HttpResponse
    {
        $reports = array_filter(
            $this->recorded(self::REPORTS),
            static fn (array $report): bool => $report['workflowId'] === $workflowId,
        );
        $last = end($reports) ?: [];
        $run = $last['run'] ?? null;

        Assert::assertIsArray(
            $run,
            "The platform recorded no report that shows a run to wake for workflow ID '{$workflowId}': the last one shows none, or there is none.",
        );

        return $this->deliver($cloud, [
            'verb' => Verb::Wake->value,
            'invocationId' => UniqueIdGenerator::generateId('inv_'),
            'definition' => $last['definition'],
            'workflowId' => $workflowId,
            'runId' => $run['runId'],
            'attempt' => $run['attempt'],
        ]);
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $response = $this->answer($request);
        $this->requests[] = ['request' => $request, 'status' => $response->statusCode];

        if ($response->statusCode >= 400) {
            // Every client of Neuron throws such an answer, with the response attached.
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw HttpException::networkError($request, 'Neuron Cloud has no streaming endpoint.');
    }

    protected function answer(HttpRequest $request): HttpResponse
    {
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            return $this->refusal($failure);
        }

        $path = $this->path($request);

        return match ($path) {
            self::ANNOUNCEMENTS, self::REPORTS => new HttpResponse(200, '{"recorded":true}'),
            self::SYNC => $this->syncAnswer($request),
            // Taken in, as an OTLP collector answers an export.
            self::TRACES, self::EVALUATIONS => new HttpResponse(200, '{}'),
            default => $this->datasetAnswer($path),
        };
    }

    /**
     * The answer to the read of a dataset keepDataset() was given. Any other
     * path, the slug of a dataset it was not given included, is answered 404.
     */
    protected function datasetAnswer(string $path): HttpResponse
    {
        // The SDK percent-encodes the slug as one segment of the path.
        $slug = str_starts_with($path, self::DATASETS) ? rawurldecode(substr($path, strlen(self::DATASETS))) : null;
        if ($slug === null || !isset($this->datasets[$slug])) {
            return $this->refusal(404);
        }

        // The platform also gives the name and the description an operator chose: the fake names it by its slug.
        return new HttpResponse(200, json_encode([
            'slug' => $slug,
            'name' => $slug,
            'description' => null,
            'items' => $this->datasets[$slug],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The answer to a sync. The fake counts no runs: none is ever open on a
     * published version, and none is stranded.
     */
    protected function syncAnswer(HttpRequest $request): HttpResponse
    {
        $manifest = is_array($request->body) ? $request->body : [];
        $syncs = $this->recorded(self::SYNC);

        return new HttpResponse(200, json_encode([
            'protocol' => Protocol::VERSION,
            // The list replaces the previous one: nothing changed when it is the same.
            'modified' => $manifest !== end($syncs),
            'versions' => array_map(
                static fn (array $registration): array => [
                    'definition' => $registration['definition'],
                    'version' => $registration['version'],
                    'openRuns' => 0,
                ],
                $manifest['definitions'] ?? [],
            ),
            'stranded' => [],
        ], JSON_THROW_ON_ERROR));
    }

    protected function refusal(int $status): HttpResponse
    {
        $code = match ($status) {
            400 => Code::InvalidRequest,
            401, 403 => Code::Unauthorized,
            // What the platform answers a sync when the ping of its endpoint fails.
            422 => Code::EndpointUnverified,
            default => null,
        };

        return new HttpResponse($status, json_encode(['code' => $code?->value], JSON_THROW_ON_ERROR));
    }

    protected function path(HttpRequest $request): string
    {
        return (string) parse_url($request->uri, PHP_URL_PATH);
    }

    /**
     * @return array<int, array<string, mixed>> the bodies the platform recorded at a path
     */
    protected function recorded(string $path): array
    {
        $bodies = [];
        foreach ($this->requests as ['request' => $request, 'status' => $status]) {
            if ($status === 200 && $this->path($request) === $path && is_array($request->body)) {
                $bodies[] = $request->body;
            }
        }

        return $bodies;
    }

    /**
     * @return string[] the name of the parentless span of every trace the platform took in, in order
     */
    protected function parentlessSpans(): array
    {
        $names = [];
        foreach ($this->recorded(self::TRACES) as $trace) {
            foreach ($trace['resourceSpans'] ?? [] as $resource) {
                foreach ($resource['scopeSpans'] ?? [] as $scope) {
                    foreach ($scope['spans'] ?? [] as $span) {
                        // OTLP gives a span with no parent an empty parentSpanId, or none.
                        if (($span['parentSpanId'] ?? '') === '') {
                            $names[] = (string) ($span['name'] ?? '');
                        }
                    }
                }
            }
        }

        return $names;
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    /**
     * Assert the platform accepted a sync.
     */
    public function assertSynced(): void
    {
        Assert::assertNotEmpty($this->recorded(self::SYNC), 'The platform accepted no sync.');
    }

    /**
     * Assert the platform recorded the announcement of a segment.
     */
    public function assertAnnounced(string $workflowId, ?Verb $verb = null): void
    {
        $matching = array_filter(
            $this->recorded(self::ANNOUNCEMENTS),
            static fn (array $announcement): bool => $announcement['workflowId'] === $workflowId
                && (!$verb instanceof Verb || $announcement['verb'] === $verb->value),
        );

        Assert::assertNotEmpty(
            $matching,
            'The platform recorded no announcement' . ($verb instanceof Verb ? " of a '{$verb->value}'" : '')
            . " for workflow ID '{$workflowId}'.",
        );
    }

    /**
     * Assert the platform recorded a report with this outcome.
     *
     * @param string|null $code a RefusalReason value or an SDK code; null accepts any
     */
    public function assertReported(string $workflowId, Outcome $outcome, ?string $code = null): void
    {
        $matching = array_filter(
            $this->recorded(self::REPORTS),
            static fn (array $report): bool => $report['workflowId'] === $workflowId
                && $report['outcome'] === $outcome->value
                && ($code === null || $report['code'] === $code),
        );

        Assert::assertNotEmpty(
            $matching,
            "The platform recorded no '{$outcome->value}' report" . ($code === null ? '' : " with the code '{$code}'")
            . " for workflow ID '{$workflowId}'.",
        );
    }

    /**
     * Assert the platform took in a trace whose parentless span has this name:
     * 'invoke_agent X' for an agent and 'invoke_workflow X' for a workflow. X is
     * the short class name for a run the application runs with tracer(), and
     * the definition name for a run the SDK executes ('invoke_workflow
     * order-fulfilment', 'invoke_agent support-agent').
     */
    public function assertTraced(string $rootSpan): void
    {
        $names = $this->parentlessSpans();

        Assert::assertContains(
            $rootSpan,
            $names,
            "The platform took in no trace whose parentless span is '{$rootSpan}': "
            . ($names === [] ? 'it took in none.' : "the traces it took in have the parentless spans '" . implode("', '", $names) . "'."),
        );
    }

    /**
     * Assert the platform took in the results of an evaluator.
     *
     * @param string $evaluator the class of the evaluator, as Neuron's evaluation runner names it
     */
    public function assertEvaluated(string $evaluator): void
    {
        $names = array_column($this->recorded(self::EVALUATIONS), 'name');

        Assert::assertContains(
            $evaluator,
            $names,
            "The platform took in no evaluation of '{$evaluator}': "
            . ($names === [] ? 'it took in none.' : "it took in the evaluations of '" . implode("', '", $names) . "'."),
        );
    }
}
