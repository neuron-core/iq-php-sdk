<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Report;
use Throwable;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_int;
use function rtrim;

/**
 * Posts the SDK's announcements and reports to the platform, publishes a
 * sync, and hands it the trace of a segment or the results of an evaluation,
 * one try each.
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

    protected const TIMEOUT_SECONDS = 3.0;

    protected const SYNC_TIMEOUT_SECONDS = 15.0;

    /** An evaluation is sent once, when a command line run ends: nothing waits behind it. */
    protected const EVALUATION_TIMEOUT_SECONDS = 15.0;

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
     * @throws CloudException report_undelivered, or the reason the platform refused it for good
     */
    public function announce(array $announcement): void
    {
        $this->post(self::ANNOUNCEMENTS, $announcement);
    }

    /**
     * @throws CloudException report_undelivered, or the reason the platform refused it for good
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
     * @throws CloudException the reason the platform refused the sync for good; report_undelivered, when it gave
     *                        no usable answer
     */
    public function sync(array $manifest): array
    {
        $answer = $this->send(self::SYNC, $manifest, self::SYNC_TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->statusCode === 200 && $this->isSyncAnswer($fields)) {
            return $fields;
        }

        $reason = $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_SYNC_CODES);
        // Only a refusal says that the list the platform holds was left as it was.
        $advice = $answer->isSuccessful()
            ? ' That is not the answer to a sync: it may have published the list. Run the sync again.'
            : '';

        throw new CloudException(
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
     * @throws CloudException unauthorized, for a 401 or a 403; report_undelivered, for any other answer that is
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
     * @throws CloudException as traces()
     */
    public function evaluation(array $evaluation): void
    {
        $this->hand(self::EVALUATIONS, $evaluation, self::EVALUATION_TIMEOUT_SECONDS);
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
     * @throws CloudException
     */
    protected function post(string $path, array $body): void
    {
        $answer = $this->send($path, $body, self::TIMEOUT_SECONDS);

        $fields = $this->fieldsOf($answer);
        if ($answer->statusCode === 200 && ($fields['recorded'] ?? null) === true) {
            return;
        }

        throw new CloudException(
            "The platform answered {$answer->statusCode} to {$path} and recorded nothing.",
            $this->reason($answer->statusCode, $fields['code'] ?? null, self::FINAL_CODES),
        );
    }

    /**
     * Post what the platform only takes in: any 2xx answer is enough, and nothing in it is read.
     *
     * @param array<string, mixed> $body
     * @throws CloudException
     */
    protected function hand(string $path, array $body, float $timeout): void
    {
        $answer = $this->send($path, $body, $timeout);

        if (!$answer->isSuccessful()) {
            throw new CloudException(
                "The platform answered {$answer->statusCode} to {$path}.",
                $this->reason($answer->statusCode, $this->fieldsOf($answer)['code'] ?? null, self::FINAL_CODES),
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return HttpResponse the answer of the platform, whatever its status
     * @throws CloudException report_undelivered, when the platform gave no answer
     */
    protected function send(string $path, array $body, float $timeout): HttpResponse
    {
        try {
            return $this->httpClient->request($this->request($path, $body, $timeout));
        } catch (HttpException $e) {
            // Neuron's clients throw an answer of 400 and above, and attach it.
            return $e->response ?? throw $this->undelivered($path, $e);
        } catch (Throwable $e) {
            throw $this->undelivered($path, $e);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function request(string $path, array $body, float $timeout): HttpRequest
    {
        return new HttpRequest(
            HttpMethod::POST,
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

    protected function undelivered(string $path, Throwable $failure): CloudException
    {
        return new CloudException("The platform gave no answer to {$path}.", Code::ReportUndelivered, $failure);
    }
}
