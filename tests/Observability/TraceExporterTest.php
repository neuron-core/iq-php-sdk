<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Observability;

use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\Observability\TraceExporter;
use NeuronCore\Cloud\Runtime\PlatformClient;
use NeuronCore\Cloud\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\Cloud\Tests\Support\Units\CapturesErrorLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class TraceExporterTest extends TestCase
{
    use CapturesErrorLog;

    protected const TRACE = ['resourceSpans' => [['scopeSpans' => [['spans' => [['name' => 'invoke_workflow order-fulfilment']]]]]]];

    public function test_a_trace_is_posted_as_it_is_to_the_traces_path_with_the_api_key(): void
    {
        $platform = new ScriptedHttpClient(new HttpResponse(200, '{}'));

        self::exporter($platform)->send(self::TRACE);

        [$request] = $platform->received();
        self::assertSame(
            [HttpMethod::POST, 'https://platform.test/v1/traces', 'Bearer test-api-key', self::TRACE],
            [$request->method, $request->uri, $request->headers['Authorization'] ?? null, $request->body],
        );
    }

    public function test_a_trace_the_platform_took_in_logs_nothing(): void
    {
        $exporter = self::exporter(new ScriptedHttpClient(new HttpResponse(200, '{}')));

        self::assertSame('', self::loggedBy(static fn () => $exporter->send(self::TRACE)));
    }

    #[DataProvider('platformsThatTakeNothingIn')]
    public function test_a_trace_the_platform_did_not_take_in_is_logged_once_and_never_thrown(HttpResponse|Throwable $answer, string $logged): void
    {
        $platform = new ScriptedHttpClient($answer);
        $exporter = self::exporter($platform);

        $log = self::loggedBy(static fn () => $exporter->send(self::TRACE));

        self::assertCount(1, $platform->received());
        self::assertStringContainsString('[TraceExporter] Failed to send trace: ' . $logged, $log);
    }

    /**
     * @return array<string, array{HttpResponse|Throwable, string}>
     */
    public static function platformsThatTakeNothingIn(): array
    {
        return [
            'a platform that is down' => [new HttpResponse(503, ''), 'The platform answered 503 to /v1/traces.'],
            'an API key the platform refuses' => [new HttpResponse(401, ''), 'The platform answered 401 to /v1/traces.'],
            'a platform that gives no answer' => [
                new RuntimeException('Connection timed out after 3001 milliseconds'),
                'The platform gave no answer to /v1/traces. Connection timed out after 3001 milliseconds',
            ],
        ];
    }

    protected static function exporter(ScriptedHttpClient $platform): TraceExporter
    {
        return new TraceExporter(new PlatformClient($platform, 'https://platform.test', 'test-api-key'));
    }
}
