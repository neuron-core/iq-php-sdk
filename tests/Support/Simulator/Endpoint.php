<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

use function array_keys;
use function array_map;
use function bin2hex;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function filter_var;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function parse_url;
use function random_bytes;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROXY;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_HOST;

/**
 * The one signed URL of the application, as the platform holds and calls it:
 * the URL the last sync published, whether deliveries to it are stopped or
 * paused, and every message sent to it, one at a time (rule 17).
 */
final class Endpoint
{
    protected const URL = 'url';

    protected const TUNNELS = 'tunnels';

    protected const APPROVED_HOST = 'approved_host';

    protected const STOPPED = 'stopped';

    protected const PING_AT = 'ping_at';

    protected const PAUSED_UNTIL = 'paused_until';

    /** The schema of every answer whose body the platform reads, by the verb it answers and its status. */
    protected const ANSWERS = [
        'ping' => [200 => 'ping-answer'],
        'inspect' => [200 => 'inspect-answer'],
        'abandon' => [200 => 'inspect-answer', 409 => 'error'],
    ];

    /** The headers of an answer that the platform reads. */
    protected const READ_HEADERS = ['content-type', 'retry-after', 'location'];

    public function __construct(
        protected Database $database,
        protected Signer $signer,
        protected Schemas $schemas,
        protected Wire $wire,
        protected Settings $settings,
        protected Alerts $alerts,
    ) {
    }

    /**
     * @return string|null the URL the last sync published; null before the first sync
     */
    public function url(): ?string
    {
        return $this->fact(self::URL);
    }

    /**
     * A test has no public address and no certificate: a tunnel says which local address stands behind a
     * public https origin, as DNS and the TLS termination of a real deployment would.
     *
     * @param array<string, string> $tunnels public origin => local origin, such as "https://shop.example.com" => "http://127.0.0.1:8123"
     */
    public function tunnel(array $tunnels): void
    {
        $this->remember(self::TUNNELS, json_encode((object) $tunnels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    public function tunnels(): array
    {
        return json_decode($this->fact(self::TUNNELS) ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Rule 16: only an https endpoint that is publicly routable. An address of a private or reserved range
     * is refused whatever tunnel leads to it, and a name is routable when a tunnel stands for its DNS.
     */
    public function isRoutable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !str_starts_with($url, 'https://') || strtolower($host) === 'localhost') {
            return false;
        }

        $address = trim($host, '[]');
        $isPrivateAddress = filter_var($address, FILTER_VALIDATE_IP) !== false
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        return !$isPrivateAddress && $this->transport($url) !== null;
    }

    /**
     * Rule 16: an owner confirms before the endpoint of an environment moves to another host.
     */
    public function wouldMoveUnconfirmed(string $url): bool
    {
        $current = $this->url();
        $host = parse_url($url, PHP_URL_HOST);

        return $current !== null
            && parse_url($current, PHP_URL_HOST) !== $host
            && $this->fact(self::APPROVED_HOST) !== $host;
    }

    public function approveHost(string $host): void
    {
        $this->remember(self::APPROVED_HOST, $host);
    }

    public function moveTo(string $url): void
    {
        $this->remember(self::URL, $url);
    }

    /**
     * Whether an order or a question may be sent now: not after a 401 until a ping succeeds, and not before
     * the time a 503 asked the platform to wait.
     */
    public function isOpen(float $now): bool
    {
        return !$this->isStopped() && $now >= (float) $this->fact(self::PAUSED_UNTIL);
    }

    public function isStopped(): bool
    {
        return $this->fact(self::STOPPED) === '1';
    }

    /**
     * A 401: deliveries stop and timers are kept, until a ping succeeds.
     */
    public function stop(float $now): void
    {
        $this->remember(self::STOPPED, '1');
        $this->remember(self::PING_AT, (string) ($now + $this->settings->get('pingRetry')));
    }

    public function resume(): void
    {
        $this->remember(self::STOPPED, '0');
    }

    /**
     * Rule 17: honour Retry-After, for every message to this endpoint.
     */
    public function pauseUntil(float $instant): void
    {
        $this->remember(self::PAUSED_UNTIL, (string) max($instant, (float) $this->fact(self::PAUSED_UNTIL)));
    }

    /**
     * After a 401, ping when the next try is due, and resume when the endpoint verifies the signature again.
     *
     * @return bool whether deliveries resumed
     */
    public function pingToResume(float $now): bool
    {
        if (!$this->isStopped() || $now < (float) $this->fact(self::PING_AT)) {
            return false;
        }

        if ($this->ping() !== 200) {
            $this->remember(self::PING_AT, (string) ($now + $this->settings->get('pingRetry')));

            return false;
        }

        $this->resume();

        return true;
    }

    /**
     * @param string|null $url the endpoint to ask; null: the one the last sync published
     * @return int the status of the answer; 0 when it is no answer to a ping
     */
    public function ping(?string $url = null): int
    {
        return $this->send('ping', ['protocol' => 1, 'verb' => 'ping'], $url)->status;
    }

    /**
     * Send the endpoint one signed message and read its answer. Redirects are never followed (rule 16).
     *
     * @param string $verb start, wake, inspect, abandon or ping
     * @param array<string, mixed> $message
     * @param string|null $url the endpoint to call; null: the one the last sync published
     */
    public function send(string $verb, array $message, ?string $url = null): Answer
    {
        $url ??= (string) $this->url();
        $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // The webhook-id of a start or a wake is its invocation ID, the same on every re-send.
        $id = $message['invocationId'] ?? 'msg_' . bin2hex(random_bytes(12));
        $headers = ['content-type' => 'application/json'] + $this->signer->headers($id, (int) $this->settings->now(), $body);

        [$status, $answer, $answerHeaders] = $this->post($this->transport($url), $headers, $body);
        $this->wire->record(Wire::SENT, $verb, $url, $headers, $body, $status, $answer, $answerHeaders);

        return $this->read($verb, $status, $answer, $answerHeaders);
    }

    /**
     * The platform reads the body of a 200 and of a 409 only, and plans from nothing that breaks its schema.
     *
     * @param array<string, string> $headers
     */
    protected function read(string $verb, int $status, string $body, array $headers): Answer
    {
        $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : null;

        if ($status !== 200 && $status !== 409) {
            return new Answer($status, [], $retryAfter);
        }

        $schema = self::ANSWERS[$verb][$status] ?? null;
        $fields = $schema === null ? null : $this->schemas->read($schema, $body);

        if ($fields === null) {
            $this->alerts->raise(
                Alerts::PROTOCOL_VIOLATION,
                "{$verb}:{$status}",
                "The {$status} answer of the endpoint to a {$verb} is not the answer the protocol gives it.",
                time(),
            );

            return new Answer(0);
        }

        return new Answer($status, $fields, $retryAfter);
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, string, array<string, string>} the status, the body and the headers of the answer; status 0
     *                                                    when there is none
     */
    protected function post(?string $url, array $headers, string $body): array
    {
        if ($url === null) {
            return [0, '', []];
        }

        $answerHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => "{$name}: {$value}",
                array_keys($headers),
                $headers,
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) max(50, $this->settings->get('endpointTimeout') * 1000),
            CURLOPT_PROXY => '',
            CURLOPT_HEADERFUNCTION => static function (mixed $handle, string $line) use (&$answerHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $answerHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $answer = curl_exec($handle);
        if (!is_string($answer)) {
            return [0, '', []];
        }

        $read = [];
        foreach (self::READ_HEADERS as $name) {
            if (isset($answerHeaders[$name])) {
                $read[$name] = $answerHeaders[$name];
            }
        }

        return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $answer, $read];
    }

    /**
     * @return string|null the address the URL is reached at; null when no tunnel leads to its origin
     */
    protected function transport(string $url): ?string
    {
        foreach ($this->tunnels() as $public => $local) {
            if (str_starts_with($url, $public . '/') || $url === $public) {
                return $local . substr($url, strlen($public));
            }
        }

        return null;
    }

    protected function fact(string $name): ?string
    {
        $value = $this->database->value('SELECT value FROM endpoint WHERE name = ?', [$name]);

        return is_string($value) ? $value : null;
    }

    protected function remember(string $name, string $value): void
    {
        $this->database->write('INSERT OR REPLACE INTO endpoint (name, value) VALUES (?, ?)', [$name, $value]);
    }
}
