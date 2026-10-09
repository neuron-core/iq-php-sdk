<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Support\Endpoint\EndpointSchema;
use NeuronCore\Cloud\Tests\Support\ProtocolSchema;
use Throwable;

use function array_filter;
use function array_map;
use function extension_loaded;
use function is_array;
use function json_encode;
use function parse_url;
use function str_contains;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_PATH;

/**
 * A test class that plays one flow of the shop, with real worker processes
 * and real waits, once for all its tests: each test then checks one thing
 * about what happened.
 */
trait PlaysOneFlow
{
    protected static ?Shop $shop = null;

    /** What stopped the flow: every test of the class fails with it, and the flow is not played again. */
    protected static ?Throwable $stoppedBy = null;

    /**
     * The shop the flow is played on.
     */
    abstract protected static function open(): Shop;

    /**
     * Play the flow, and keep what the tests will check.
     */
    abstract protected static function play(Shop $shop): void;

    public static function tearDownAfterClass(): void
    {
        self::$shop?->cleanUp();
        self::$shop = null;
        self::$stoppedBy = null;
    }

    protected function setUp(): void
    {
        foreach (['pcntl', 'pdo_sqlite'] as $extension) {
            if (!extension_loaded($extension)) {
                self::markTestSkipped("Worker processes on SQLite need the {$extension} extension.");
            }
        }

        if (!self::$shop instanceof Shop) {
            self::$shop = static::open();

            try {
                static::play(self::$shop);
            } catch (Throwable $stopped) {
                self::$stoppedBy = $stopped;
            }
        }

        if (self::$stoppedBy instanceof Throwable) {
            throw self::$stoppedBy;
        }
    }

    protected static function shop(): Shop
    {
        return self::$shop ?? self::fail('The flow was not played.');
    }

    /**
     * Neuron reads the real clock: wait for an instant, which no flow puts more than two seconds away.
     */
    protected static function awaitInstant(?int $instant): void
    {
        self::assertNotNull($instant, 'The platform has planned nothing to wait for.');
        self::assertLessThanOrEqual(2, $instant - time(), 'No wait of a flow is longer than two seconds.');

        while (time() < $instant) {
            usleep(20_000);
        }
    }

    /**
     * Every body the shop sent the platform, every message its endpoint took and every answer the endpoint
     * gave must match the JSON Schema of its message.
     */
    protected static function assertProtocolRespected(Shop $shop): void
    {
        foreach ($shop->platform->received() as $request) {
            $message = parse_url($request->uri, PHP_URL_PATH) === '/v1/reports' ? 'report' : 'announcement';

            ProtocolSchema::assertValid($message, is_array($request->body) ? $request->body : []);
        }

        foreach ($shop->platform->exchanges() as ['message' => $message, 'answer' => $answer]) {
            EndpointSchema::assertRespected($message, $answer);
        }
    }

    /**
     * Whatever was planted in an order, in clear or in the form a queued message carries it, must be in
     * nothing that crossed the wire, in either direction.
     */
    protected static function assertNothingPlantedCrossedTheWire(Shop $shop): void
    {
        $crossed = self::crossedTheWire($shop);
        self::assertNotSame([], $crossed, 'Nothing crossed the wire: there is no boundary to check.');

        $leaks = [];
        foreach (Secrets::planted() + self::carriedInTheQueue($shop) as $where => $secret) {
            foreach ($crossed as $sent) {
                if (str_contains($sent, $secret)) {
                    $leaks[] = "What was planted in {$where} crossed the wire in {$sent}";
                }
            }
        }

        self::assertSame([], $leaks);
    }

    /**
     * @return string[] every request the platform received and every exchange of its with the endpoint, as JSON
     */
    protected static function crossedTheWire(Shop $shop): array
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        $crossed = [];

        foreach ($shop->platform->received() as $request) {
            $crossed[] = json_encode([$request->uri, $request->headers, $request->body], $flags);
        }

        foreach ($shop->platform->exchanges() as ['message' => $message, 'answer' => $answer]) {
            $crossed[] = json_encode([$message, $answer->statusCode, $answer->headers, $answer->body], $flags);
        }

        return $crossed;
    }

    /**
     * @return array<string, string> the payload and the start event of every message the queue held, as the
     *                               message carries them
     */
    protected static function carriedInTheQueue(Shop $shop): array
    {
        $carried = [];

        foreach (array_map(static fn (Invocation $message): array => $message->toArray(), $shop->taken()) as $number => $message) {
            foreach (array_filter(['payload' => $message['payload'], 'event' => $message['event']]) as $field => $value) {
                $carried["the {$field} that message {$number} of the queue carried"] = $value;
            }
        }

        return $carried;
    }
}
