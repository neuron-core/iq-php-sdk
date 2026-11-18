<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Concurrency;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_map;
use function array_values;
use function file;
use function file_put_contents;
use function in_array;
use function json_decode;
use function json_encode;
use function ksort;
use function parse_url;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const PHP_URL_PATH;

/**
 * The platform as several processes see it: each one gives its IQ a
 * client on the same file, which keeps every body any of them sent. Every
 * request is answered as recorded.
 */
final class SharedPlatform implements HttpClientInterface
{
    protected const ANNOUNCEMENTS = '/v1/announcements';

    protected const REPORTS = '/v1/reports';

    /**
     * @param string $path an existing file
     */
    public function __construct(public readonly string $path)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $received = ['path' => parse_url($request->uri, PHP_URL_PATH), 'body' => $request->body];
        file_put_contents($this->path, json_encode($received, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

        return new HttpResponse(200, '{"recorded":true}');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw HttpException::networkError($request, 'IQ has no streaming endpoint.');
    }

    /**
     * @return array<int, array<string, mixed>> the announcements every process sent, in the order they arrived
     */
    public function announcements(): array
    {
        return $this->sentTo(self::ANNOUNCEMENTS);
    }

    /**
     * @return array<int, array<string, mixed>> the reports every process sent, in the order they arrived
     */
    public function reports(): array
    {
        return $this->sentTo(self::REPORTS);
    }

    /**
     * How the invocations about some runs ended, as the platform heard it.
     *
     * @return array<string, int> how many reports say each "outcome" or "outcome: code", sorted by that text
     */
    public function outcomesOf(string ...$runIds): array
    {
        $outcomes = array_count_values(array_map(
            static fn (array $report): string => $report['outcome'] . ($report['code'] === null ? '' : ': ' . $report['code']),
            array_filter($this->reports(), static fn (array $report): bool => in_array($report['runId'], $runIds, true)),
        ));
        ksort($outcomes);

        return $outcomes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentTo(string $path): array
    {
        $received = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->path, FILE_IGNORE_NEW_LINES) ?: [],
        );

        return array_column(
            array_values(array_filter($received, static fn (array $request): bool => $request['path'] === $path)),
            'body',
        );
    }
}
