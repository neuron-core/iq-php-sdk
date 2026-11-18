<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndpointFlows;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\IQ;
use NeuronCore\IQ\Tests\Stub\Units\ScriptedHttpClient;
use NeuronCore\IQ\Tests\Support\Units\CatchesIQExceptions;
use PDO;
use PHPUnit\Framework\TestCase;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * A test of App Sync against a platform whose answer the test scripts: the
 * sync script of a deployment, on a store every process shares.
 */
abstract class SyncTestCase extends TestCase
{
    use CatchesIQExceptions;

    protected const PLATFORM_URL = 'https://platform.test';

    protected const API_KEY = 'nck_5d1c0e7a';

    protected const ENDPOINT = 'https://shop.example.com/iq';

    /**
     * bin/sync.php of a deployment that has registered nothing yet.
     */
    protected static function deployment(HttpClientInterface $platform, string $platformUrl = self::PLATFORM_URL): IQ
    {
        return (new IQ(self::API_KEY, null, $platformUrl, $platform))
            ->setPersistence(static fn (): PersistenceInterface => new DatabasePersistence(new PDO('sqlite::memory:')));
    }

    /**
     * A deployment whose code holds versions 1 and 2 of "order-fulfilment", and "nightly-reconciliation".
     */
    protected static function shop(HttpClientInterface $platform): IQ
    {
        return self::deployment($platform)
            ->register('order-fulfilment', self::workflow(...))
            ->register('order-fulfilment', self::workflow(...), version: 2)
            ->register('nightly-reconciliation', self::workflow(...));
    }

    protected static function workflow(): Workflow
    {
        return new Workflow();
    }

    /**
     * A platform that gives every request the same answer.
     *
     * @param array<array-key, mixed>|string $body an array is sent as JSON
     */
    protected static function platformAnswering(int $status, array|string $body): ScriptedHttpClient
    {
        return new ScriptedHttpClient(new HttpResponse($status, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * A platform that accepts every sync.
     *
     * @param array<int, array<string, mixed>> $versions the published versions, with the runs open on each
     * @param array<int, array<string, mixed>> $stranded the versions that left the code while runs remain
     */
    protected static function platformAccepting(array $versions = [], array $stranded = [], bool $modified = true): ScriptedHttpClient
    {
        return self::platformAnswering(200, ['protocol' => 1, 'modified' => $modified, 'versions' => $versions, 'stranded' => $stranded]);
    }

    /**
     * @return array<string, mixed> one entry of "versions" or "stranded" in the answer to a sync
     */
    protected static function openRuns(string $definition, int $version, int $openRuns): array
    {
        return ['definition' => $definition, 'version' => $version, 'openRuns' => $openRuns];
    }

    /**
     * The only request a sync sent.
     */
    protected static function published(ScriptedHttpClient $platform): HttpRequest
    {
        $received = $platform->received();
        self::assertCount(1, $received, 'A sync is one request.');

        return $received[0];
    }
}
