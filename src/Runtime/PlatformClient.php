<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Report;
use Throwable;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function rawurlencode;
use function rtrim;

/**
 * Posts the SDK's announcements and reports to the platform, publishes a
 * sync, hands it the trace of a segment or the results of an evaluation,
 * reads a dataset it keeps and writes the items of one, one try each.
 *
 * @internal
 */
final class PlatformClient
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    protected const SYNC = '/v1/sync';

    /** The path OTLP over HTTP gives an export of traces. */
    protected const TRACES = '/v1/traces';

    protected const EVALUATIONS = '/v1/evaluations';

    /** Followed by the slug of the dataset. */
    protected const DATASETS = '/v1/datasets/';

    /** Follows the slug of a dataset whose items are written. */
    protected const DATASET_ITEMS = '/items';

    /** Followed by the ID of the suite. */
    protected const EVALUATION_SUITES = '/v1/evaluations/suites/';

    /** The most items one write of a dataset carries: the platform refuses more. */
    public const UPLOAD_ITEMS = 1000;

    protected const TIMEOUT_SECONDS = 3.0;

    protected const SYNC_TIMEOUT_SECONDS = 15.0;

    /** An evaluation is sent once, when a command line run ends: nothing waits behind it. */
    protected const EVALUATION_TIMEOUT_SECONDS = 15.0;

    /** A dataset is read once per evaluator, before its items run: only the developer waits for it. */
    protected const DATASET_TIMEOUT_SECONDS = 15.0;

    /** A 4xx answer that carries one of these will never be accepted, however often it is sent. */
    protected const FINAL_CODES = [Code::InvalidRequest, Code::UnsupportedProtocol];

    /** A sync is also refused for good when the platform could not ping the endpoint it names. */
    protected const FINAL_SYNC_CODES = [...self::FINAL_CODES, Code::EndpointUnverified];

    public function __construct(
        protected HttpClientInterface $httpClient,
        protected string $platformUrl,
        protected string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $announcement
     * @throws IQException report_undelivered, or the reason the platform refused it for good
     */
    public function announce(array $announcement): void
    {
        $this->post(self::ANNOUNCEMENTS, $announcement);
    }

    /**
     * @throws IQException report_undelivered, or the reason the platform refused it for good
     */
    public function report(Report $report): void
    {
        $this->post(self::REPORTS, $report->toArray());
    }

    /**
     * Publish the complete list of definitions and the endpoint of this deployment.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed> the answer: whether anything changed, and the runs still open on each version
     * @throws IQException the reason the platform refused the sync for good; report_undelivered, when it gave
     *                        no usable answer
     */
    public function sync(array $manifest): array
    {
        $answer = $this->send(HttpMethod::POST, self::SYNC, $manifest, self::SYNC_TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->statusCode === 200 && $this->isSyncAnswer($fields)) {
            return $fields;
        }

        $reason = $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_SYNC_CODES);
        // Only a refusal says that the list the platform holds was left as it was.
        $advice = $answer->isSuccessful()
            ? ' That is not the answer to a sync: it may have published the list. Run the sync again.'
            : '';

        throw new IQException(
            "The platform did not confirm the sync: it answered {$answer->statusCode} to " . self::SYNC
            . " ({$reason->value}).{$advice}",
            $reason,
        );
    }

    /**
     * The spans of one segment, as an OTLP export request in JSON. It is posted in the process that executed
     * the segment, so it waits no longer than an announcement does.
     *
     * @param array<string, mixed> $trace
     * @throws IQException unauthorized, for a 401 or a 403; report_undelivered, for any other answer that is
     *                        not a 2xx, when the platform gave none and when the trace cannot be encoded
     */
    public function traces(array $trace): void
    {
        $this->hand(self::TRACES, $trace, self::TIMEOUT_SECONDS);
    }

    /**
     * What one evaluator found, with the inputs and the outputs it ran on.
     *
     * @param array<string, mixed> $evaluation
     * @throws IQException as traces()
     */
    public function evaluation(array $evaluation): void
    {
        $this->hand(self::EVALUATIONS, $evaluation, self::EVALUATION_TIMEOUT_SECONDS);
    }

    /**
     * What the platform says about the evaluators of a suite.
     *
     * @return array<string, mixed>
     * @throws IQException unauthorized, for a 401 or a 403; invalid_request or unsupported_protocol, for a 4xx
     *                        that carries it; report_undelivered, for any other answer that is not a 2xx with a
     *                        verdict, a 404 for a suite the platform does not know included, and when the platform
     *                        gave none
     */
    public function evaluationSuite(string $suiteId): array
    {
        $path = self::EVALUATION_SUITES . rawurlencode($suiteId);
        $answer = $this->send(HttpMethod::GET, $path, null, self::DATASET_TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->isSuccessful() && is_string($fields['verdict'] ?? null)) {
            return $fields;
        }

        $explanation = $answer->statusCode === 404 ? ' It holds no evaluation of this suite.' : '';

        throw new IQException(
            "The platform answered {$answer->statusCode} to {$path}.{$explanation}",
            $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_CODES),
        );
    }

    /**
     * The items of a dataset the platform keeps, in the environment of the API key.
     *
     * @return list<array<array-key, mixed>> each item as the platform holds it
     * @throws IQException unauthorized, for a 401 or a 403; invalid_request or unsupported_protocol, for a 4xx
     *                        that carries it; report_undelivered, for any other answer that is not a 2xx with a list
     *                        of items, a 404 for a slug the platform does not know included, and when the platform
     *                        gave none
     */
    public function dataset(string $slug): array
    {
        $path = self::DATASETS . rawurlencode($slug);
        $answer = $this->send(HttpMethod::GET, $path, null, self::DATASET_TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        $items = $fields['items'] ?? null;
        if ($answer->isSuccessful() && $this->isListOfItems($items)) {
            return $items;
        }

        $explanation = match (true) {
            $answer->statusCode === 404 => " It keeps no dataset with the slug '{$slug}' in the environment of the API key.",
            $answer->isSuccessful() => ' That is not a dataset: it holds no list of items.',
            default => '',
        };

        throw new IQException(
            "The platform answered {$answer->statusCode} to {$path}.{$explanation}",
            $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_CODES),
        );
    }

    /**
     * Merge items into the dataset of the environment of the API key with the slug, which the platform creates
     * when there is none: an item whose input is the input of one of its items replaces the expected output and
     * the metadata of that item, any other is added, and the items not sent stay.
     *
     * @param list<array<array-key, mixed>> $items at most {@see self::UPLOAD_ITEMS}, each with an input
     * @param string|null $name what a dataset the request creates is called; its slug when null
     * @return array{slug: string, created: bool, added: int, updated: int, unchanged: int}
     * @throws IQException unauthorized, for a 401 or a 403; invalid_request, for a 422, whose message says what
     *                        the platform refused, and for a 4xx that carries it; unsupported_protocol, for a 4xx
     *                        that carries it; report_undelivered, for any other answer that is not a 2xx with the
     *                        counts, and when the platform gave none
     */
    public function uploadDatasetItems(string $slug, array $items, ?string $name = null): array
    {
        $path = self::DATASETS . rawurlencode($slug) . self::DATASET_ITEMS;
        $answer = $this->send(HttpMethod::POST, $path, ['name' => $name, 'items' => $items], self::DATASET_TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->isSuccessful() && $this->isUploadAnswer($fields)) {
            return $fields;
        }

        $message = $fields['message'] ?? null;
        $explanation = $answer->isSuccessful()
            ? ' That is not what a write of a dataset answers.'
            : (is_string($message) && $message !== '' ? " {$message}" : '');

        throw new IQException(
            "The platform answered {$answer->statusCode} to {$path}.{$explanation}",
            $answer->statusCode === 422 ? Code::InvalidRequest : $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_CODES),
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @phpstan-assert-if-true array{slug: string, created: bool, added: int, updated: int, unchanged: int} $fields
     */
    protected function isUploadAnswer(array $fields): bool
    {
        return is_string($fields['slug'] ?? null) && is_bool($fields['created'] ?? null)
            && is_int($fields['added'] ?? null) && is_int($fields['updated'] ?? null) && is_int($fields['unchanged'] ?? null);
    }

    /**
     * Whether the items of a dataset are what an evaluator runs on: a list of JSON objects or arrays. An empty
     * dataset is one.
     *
     * @phpstan-assert-if-true list<array<array-key, mixed>> $items
     */
    protected function isListOfItems(mixed $items): bool
    {
        if (!is_array($items) || !array_is_list($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list the answer leaves out counts as empty.
     *
     * @param array<string, mixed> $fields
     */
    protected function isSyncAnswer(array $fields): bool
    {
        return is_bool($fields['modified'] ?? null)
            && $this->listsOpenRuns($fields['versions'] ?? [])
            && $this->listsOpenRuns($fields['stranded'] ?? []);
    }

    /**
     * Whether a list of a sync answer holds what the protocol says: versions of definitions, each with its open runs.
     */
    protected function listsOpenRuns(mixed $versions): bool
    {
        if (!is_array($versions) || !array_is_list($versions)) {
            return false;
        }

        foreach ($versions as $entry) {
            $isWellFormed = is_array($entry)
                && Format::isDefinition($entry['definition'] ?? null)
                && is_int($entry['version'] ?? null) && $entry['version'] >= 1
                && is_int($entry['openRuns'] ?? null) && $entry['openRuns'] >= 0;

            if (!$isWellFormed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $body
     * @throws IQException
     */
    protected function post(string $path, array $body): void
    {
        $answer = $this->send(HttpMethod::POST, $path, $body, self::TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->statusCode === 200 && ($fields['recorded'] ?? null) === true) {
            return;
        }

        throw new IQException(
            "The platform answered {$answer->statusCode} to {$path} and recorded nothing.",
            $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_CODES),
        );
    }

    /**
     * Post what the platform only takes in: any 2xx answer is enough, and nothing in it is read.
     *
     * @param array<string, mixed> $body
     * @throws IQException
     */
    protected function hand(string $path, array $body, float $timeout): void
    {
        $answer = $this->send(HttpMethod::POST, $path, $body, $timeout);

        if (!$answer->isSuccessful()) {
            throw new IQException(
                "The platform answered {$answer->statusCode} to {$path}.",
                $this->reason($answer->statusCode, $this->fieldsOf($answer)['code'] ?? null, self::FINAL_CODES),
            );
        }
    }

    /**
     * @param array<string, mixed>|null $body null for a GET
     * @return HttpResponse the answer of the platform, whatever its status
     * @throws IQException report_undelivered, when the platform gave no answer
     */
    protected function send(HttpMethod $method, string $path, ?array $body, float $timeout): HttpResponse
    {
        try {
            return $this->httpClient->request($this->request($method, $path, $body, $timeout));
        } catch (HttpException $e) {
            // Neuron's clients throw an answer of 400 and above, and attach it.
            return $e->response ?? throw $this->undelivered($path, $e);
        } catch (Throwable $e) {
            throw $this->undelivered($path, $e);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function request(HttpMethod $method, string $path, ?array $body, float $timeout): HttpRequest
    {
        return new HttpRequest(
            $method,
            rtrim($this->platformUrl, '/') . $path,
            ['Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => Protocol::SDK],
            $body,
            $timeout,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldsOf(HttpResponse $answer): array
    {
        try {
            return $answer->json();
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * A 401 or a 403, and a 4xx that carries one of the final codes of the
     * message, are final. Any other answer counts as undelivered.
     *
     * @param Code[] $finalCodes
     */
    protected function reason(int $status, mixed $code, array $finalCodes): Code
    {
        if ($status === 401 || $status === 403) {
            return Code::Unauthorized;
        }

        foreach ($finalCodes as $final) {
            if ($status >= 400 && $status < 500 && $code === $final->value) {
                return $final;
            }
        }

        return Code::ReportUndelivered;
    }

    protected function undelivered(string $path, Throwable $failure): IQException
    {
        return new IQException("The platform gave no answer to {$path}.", Code::ReportUndelivered, $failure);
    }
}
