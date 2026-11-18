<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use function array_map;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function time;

/**
 * The application's side of the protocol, played by a test: it sends the
 * simulator the sync, the announcements and the reports an SDK would send,
 * so that the test decides what the platform hears and when.
 */
final class PlayedApplication
{
    public const DEFINITION = 'order-fulfilment';

    public const ENDPOINT = ScriptedEndpoint::PUBLIC_URL . '/iq';

    public const SDK = 'iq-php/1.0.0';

    /**
     * @param string $apiKey the key it presents; another one than the platform's plays a wrong or revoked key
     */
    public function __construct(protected SimulatedPlatform $platform, protected string $apiKey = SimulatedPlatform::API_KEY)
    {
    }

    /**
     * App Sync: publish the versions of the one definition and the endpoint.
     *
     * @param int[] $versions
     * @return array{status: int, body: array<string, mixed>}
     */
    public function sync(array $versions = [1], string $endpoint = self::ENDPOINT, int $leaseSeconds = 60): array
    {
        return $this->post('/v1/sync', [
            'protocols' => [1],
            'sdk' => self::SDK,
            'endpoint' => $endpoint,
            'definitions' => array_map(
                static fn (int $version): array => ['definition' => self::DEFINITION, 'version' => $version, 'leaseSeconds' => $leaseSeconds],
                $versions,
            ),
        ]);
    }

    /**
     * "A segment of this run is starting": by default the start of a run the application's own code issued.
     *
     * @param array<string, mixed> $fields what this announcement says differently
     * @return int the status of the platform's answer
     */
    public function announce(string $runId, string $workflowId, array $fields = []): int
    {
        return $this->post('/v1/announcements', $fields + [
            'protocol' => 1,
            'invocationId' => self::invocationId(),
            'verb' => 'start',
            'origin' => 'application',
            'definition' => self::DEFINITION,
            'workflowId' => $workflowId,
            'runId' => $runId,
            'attempt' => null,
            'leaseSeconds' => 60,
            'at' => time(),
        ])['status'];
    }

    /**
     * "This is the run now": by default the report of a start the application's own code issued.
     *
     * @param array<string, mixed>|null $run the run holding the workflow ID, as Shown writes it; null: none does
     * @param array<string, mixed> $fields what this report says differently
     * @return int the status of the platform's answer
     */
    public function report(string $runId, string $workflowId, string $outcome, ?array $run, array $fields = []): int
    {
        return $this->post('/v1/reports', $fields + [
            'protocol' => 1,
            'invocationId' => self::invocationId(),
            'verb' => 'start',
            'origin' => 'application',
            'definition' => self::DEFINITION,
            'workflowId' => $workflowId,
            'runId' => $runId,
            'outcome' => $outcome,
            'code' => null,
            'errorClass' => null,
            'executedAttempt' => null,
            'run' => $run,
            'claimed' => null,
            'snapshotRead' => true,
            'at' => time(),
        ])['status'];
    }

    /**
     * @param array<string, mixed>|string $body a message, or the bytes to send in its place
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $path, array|string $body): array
    {
        $answer = Http::send('POST', $this->platform->url() . $path, $body, ["Authorization: Bearer {$this->apiKey}"]);

        return ['status' => $answer['status'], 'body' => (array) json_decode($answer['body'], true)];
    }

    /**
     * The ID of a segment the application's own code issued.
     */
    public static function invocationId(): string
    {
        return 'inv_' . bin2hex(random_bytes(8));
    }
}
