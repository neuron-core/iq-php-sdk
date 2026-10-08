<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Assert;

use function array_fill;
use function array_filter;
use function array_shift;
use function base64_encode;
use function in_array;
use function is_array;
use function json_encode;
use function parse_url;
use function random_bytes;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * Neuron Cloud as the SDK sees it over HTTP: it records what it is told and
 * answers as the platform would. Give it to NeuronCloud as its HTTP client.
 */
class FakePlatform implements HttpClientInterface
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    public readonly string $signingKey;

    /** @var array<int, array{request: HttpRequest, status: int}> every request received, with the status it was answered */
    protected array $requests = [];

    /** @var int[] the statuses the next requests are answered with, instead of being recorded */
    protected array $failures = [];

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

        return in_array($this->path($request), [self::ANNOUNCEMENTS, self::REPORTS], true)
            ? new HttpResponse(200, '{"recorded":true}')
            : $this->refusal(404);
    }

    protected function refusal(int $status): HttpResponse
    {
        $code = match ($status) {
            400 => Code::InvalidRequest,
            401, 403 => Code::Unauthorized,
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

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

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
}
