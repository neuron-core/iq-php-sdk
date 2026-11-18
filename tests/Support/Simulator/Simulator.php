<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function array_filter;
use function explode;
use function getenv;
use function hash_equals;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * IQ, small: the platform's side of protocol 1 as appendices A and
 * B of the design write it, built from that text alone. It shares no class
 * with the SDK, so when the two agree, two implementations agree with the
 * document. One request at a time, on PHP's built-in server.
 */
final class Simulator
{
    /** The message each path of the platform receives. */
    protected const MESSAGES = [
        '/v1/sync' => Intake::SYNC,
        '/v1/announcements' => Intake::ANNOUNCEMENT,
        '/v1/reports' => Intake::REPORT,
    ];

    protected const OPERATOR = '/_operator/';

    /** Not an act of an operator: a test asks the simulator to forget everything it holds and every setting. */
    protected const RESET = '/_operator/reset';

    /**
     * @param string[] $apiKeys the old and the new one, during a rotation
     */
    protected function __construct(
        protected Database $memory,
        protected Settings $settings,
        protected array $apiKeys,
        protected Intake $intake,
        protected Operator $operator,
        protected Wire $wire,
    ) {
    }

    /**
     * SIMULATOR_DATABASE names the SQLite file that is the memory of the platform and SIMULATOR_WIRE_TAP the one
     * a test reads the wire from; SIMULATOR_API_KEYS and SIMULATOR_SIGNING_KEYS list the active keys, separated
     * by spaces.
     */
    public static function fromEnvironment(): self
    {
        $database = new Database((string) getenv('SIMULATOR_DATABASE'));
        $settings = new Settings($database);
        $alerts = new Alerts($database);
        $wire = new Wire(new Database((string) getenv('SIMULATOR_WIRE_TAP'), Database::WIRE_TAP));
        $schemas = new Schemas();
        $manifest = new Manifest($database);
        $signer = new Signer(self::keys('SIMULATOR_SIGNING_KEYS'));
        $endpoint = new Endpoint($database, $signer, $schemas, $wire, $settings, $alerts);
        $runs = new Runs($database, $settings, $alerts, $manifest);
        $orders = new Orders($database);
        $segments = new Segments($database);
        $plan = new Plan($settings, $manifest, $runs, $orders, $segments);
        $clockwork = new Clockwork($settings, $endpoint, $runs, $orders, $segments, $alerts, $plan);

        return new self(
            $database,
            $settings,
            self::keys('SIMULATOR_API_KEYS'),
            new Intake($database, $schemas, $settings, $manifest, $endpoint, $runs, $orders, $segments, $alerts),
            new Operator($settings, $manifest, $endpoint, $runs, $orders, $segments, $alerts, $wire, $plan, $clockwork),
            $wire,
        );
    }

    /**
     * @param array<array-key, mixed> $headers as getallheaders() gives them
     * @return array{int, string} the status and the JSON body of the answer
     */
    public function handle(string $method, string $uri, array $headers, string $body): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $now = $this->settings->now();

        if ($path === self::RESET) {
            $this->memory->erase();
            $this->wire->erase();

            return [200, '{}'];
        }

        if (str_starts_with($path, self::OPERATOR)) {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $request = json_decode($body === '' ? '{}' : $body, true);

            // One commit for all a tick or an operator writes: only a notice of the application must be durable by itself.
            [$status, $answer] = $this->memory->atomically(
                fn (): array => $this->operator->handle(strtoupper($method), $path, $query, is_array($request) ? $request : [], $now),
            );

            return [$status, json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        }

        return isset(self::MESSAGES[$path])
            ? $this->receive(self::MESSAGES[$path], $path, strtoupper($method), self::lowerCased($headers), $body, $now)
            : [404, '{}'];
    }

    /**
     * A request of the application, logged as it arrived with the answer it got.
     *
     * @param array<string, mixed> $headers names in lower case
     * @return array{int, string}
     */
    protected function receive(string $message, string $path, string $method, array $headers, string $body, float $now): array
    {
        $isAuthorized = $this->isAuthorized((string) ($headers['authorization'] ?? ''));

        [$status, $answer] = match (true) {
            $method !== 'POST' => [405, ['code' => 'invalid_request']],
            // A 401 is final for the application: the key is wrong or revoked.
            !$isAuthorized => [401, ['code' => 'unauthorized']],
            default => $this->intake->receive($message, $body, $now),
        };

        $encoded = json_encode((object) $answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->wire->record(
            Wire::RECEIVED,
            $message,
            $path,
            [
                'authorization' => $isAuthorized ? 'accepted' : 'refused',
                'user-agent' => (string) ($headers['user-agent'] ?? ''),
                'content-type' => (string) ($headers['content-type'] ?? ''),
            ],
            $body,
            $status,
            $encoded,
        );

        return [$status, $encoded];
    }

    protected function isAuthorized(string $authorization): bool
    {
        if (!str_starts_with($authorization, 'Bearer ')) {
            return false;
        }

        foreach ($this->apiKeys as $apiKey) {
            if (hash_equals($apiKey, substr($authorization, strlen('Bearer ')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, mixed>
     */
    protected static function lowerCased(array $headers): array
    {
        $lowerCased = [];
        foreach ($headers as $name => $value) {
            $lowerCased[strtolower((string) $name)] = $value;
        }

        return $lowerCased;
    }

    /**
     * @return string[]
     */
    protected static function keys(string $variable): array
    {
        return array_filter(explode(' ', (string) getenv($variable)));
    }
}
