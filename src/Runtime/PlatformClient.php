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

use function rtrim;

/**
 * Posts the SDK's announcements and reports to the platform, one try each.
 *
 * @internal
 */
final class PlatformClient
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    protected const TIMEOUT_SECONDS = 3.0;

    /** A 4xx answer that carries one of these will never be accepted, however often it is sent. */
    protected const FINAL_CODES = [Code::InvalidRequest, Code::UnsupportedProtocol];

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
     * @param array<string, mixed> $body
     * @throws CloudException
     */
    protected function post(string $path, array $body): void
    {
        try {
            $answer = $this->httpClient->request($this->request($path, $body));
        } catch (HttpException $e) {
            // Neuron's clients throw an answer of 400 and above, and attach it.
            $answer = $e->response ?? throw $this->undelivered($path, $e);
        } catch (Throwable $e) {
            throw $this->undelivered($path, $e);
        }

        $fields = $this->fieldsOf($answer);
        if ($answer->statusCode === 200 && ($fields['recorded'] ?? null) === true) {
            return;
        }

        throw new CloudException(
            "The platform answered {$answer->statusCode} to {$path} and recorded nothing.",
            $this->reason($answer->statusCode, $fields['code'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function request(string $path, array $body): HttpRequest
    {
        return new HttpRequest(
            HttpMethod::POST,
            rtrim($this->platformUrl, '/') . $path,
            ['Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => Protocol::SDK],
            $body,
            self::TIMEOUT_SECONDS,
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
     * A 401 or a 403, and a 4xx that carries invalid_request or
     * unsupported_protocol, are final. Any other answer counts as undelivered.
     */
    protected function reason(int $status, mixed $code): Code
    {
        if ($status === 401 || $status === 403) {
            return Code::Unauthorized;
        }

        foreach (self::FINAL_CODES as $final) {
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
