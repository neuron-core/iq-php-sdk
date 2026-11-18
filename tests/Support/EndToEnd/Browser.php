<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use RuntimeException;

use function count;
use function explode;
use function fclose;
use function fgets;
use function fsockopen;
use function fwrite;
use function json_encode;
use function parse_url;
use function str_starts_with;
use function stream_set_timeout;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * A browser on a response that is streamed as server-sent events: it reads
 * the frames as they arrive, to the end of the response or until it has
 * seen enough and closes the connection, as a customer who leaves the page.
 */
final class Browser
{
    /** Seconds the server may take between two lines. */
    protected const PATIENCE = 15;

    protected const FRAME = 'data: ';

    /**
     * @param mixed $json the body of the POST, sent as JSON
     * @param string|null $leavesAt the frame after which the browser closes the connection; null: it reads to the end
     * @return array{status: int, frames: string[]} the status of the answer and the frames received
     * @throws RuntimeException when the server cannot be reached
     */
    public static function watch(string $url, mixed $json, ?string $leavesAt = null): array
    {
        $address = (array) parse_url($url);
        $connection = fsockopen((string) $address['host'], (int) $address['port'], $errorCode, $errorMessage, self::PATIENCE);

        if ($connection === false) {
            throw new RuntimeException("No connection to {$url}: {$errorMessage}");
        }

        $body = json_encode($json, JSON_THROW_ON_ERROR);
        stream_set_timeout($connection, self::PATIENCE);
        fwrite($connection, "POST {$address['path']} HTTP/1.1\r\nHost: {$address['host']}\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}");

        $status = (int) (explode(' ', (string) fgets($connection))[1] ?? 0);
        $frames = [];

        while (($line = fgets($connection)) !== false) {
            if (!str_starts_with($line, self::FRAME)) {
                continue;
            }

            $frames[] = trim(substr($line, strlen(self::FRAME)));

            if ($frames[count($frames) - 1] === $leavesAt) {
                break;
            }
        }

        fclose($connection);

        return ['status' => $status, 'frames' => $frames];
    }
}
