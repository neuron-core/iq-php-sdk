<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use RuntimeException;

use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function json_decode;
use function json_encode;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROXY;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_THROW_ON_ERROR;

/**
 * How a test talks to a server it started: as a browser, a payment provider
 * or an operator would, over real HTTP.
 */
final class Http
{
    /** Seconds a server of the test may take to answer. */
    protected const TIMEOUT = 15;

    /**
     * @param mixed $json the body, sent as JSON; a string is sent as it is; null: no body
     * @param string[] $headers each "Name: value"
     * @return array{status: int, body: string}
     * @throws RuntimeException when the server gave no answer
     */
    public static function send(string $method, string $url, mixed $json = null, array $headers = []): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', ...$headers],
        ] + ($json === null ? [] : [CURLOPT_POSTFIELDS => is_string($json) ? $json : json_encode($json, JSON_THROW_ON_ERROR)]));

        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new RuntimeException("No answer to {$method} {$url}: " . curl_error($handle));
        }

        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    /**
     * @param mixed $json the body, sent as JSON; null: no body
     * @return mixed the body of the answer, decoded
     * @throws RuntimeException when the server gave no answer, or one that is not of the 2xx class
     */
    public static function ask(string $method, string $url, mixed $json = null): mixed
    {
        $answer = self::send($method, $url, $json);

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            throw new RuntimeException("{$method} {$url} was answered {$answer['status']}: {$answer['body']}");
        }

        return json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);
    }
}
