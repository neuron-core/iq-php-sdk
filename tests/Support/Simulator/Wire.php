<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function error_log;
use function json_decode;
use function json_encode;
use function microtime;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The log of every message the simulator received or sent, with its answer,
 * exactly as each crossed the wire. It is what a test reads to check the
 * protocol and the boundary; the platform itself never plans from it.
 *
 * It is an instrument of the tests and no part of a platform. Rule 15 of
 * appendix B forbids a platform to keep a body as it arrived, in its logs
 * too: this tap keeps every byte because it must show what the SDK must
 * never send.
 */
final class Wire
{
    public const RECEIVED = 'received';

    public const SENT = 'sent';

    public function __construct(protected Database $database)
    {
    }

    /**
     * @param string $direction RECEIVED from the application, or SENT to its endpoint
     * @param string $message sync, announcement, report, start, wake, inspect, abandon or ping
     * @param string $target the path the application called, or the URL the platform called
     * @param array<string, string> $headers
     * @param array<string, string> $answerHeaders
     */
    public function record(
        string $direction,
        string $message,
        string $target,
        array $headers,
        string $body,
        int $status,
        string $answer,
        array $answerHeaders = [],
    ): void {
        $this->database->write(
            'INSERT INTO wire (at, direction, message, target, headers, body, status, answer, answer_headers) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                microtime(true),
                $direction,
                $message,
                $target,
                json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $body,
                $status,
                $answer,
                json_encode((object) $answerHeaders, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        );

        // The same line in the log of the process, for whoever reads why a test failed.
        error_log("{$direction} {$message} {$body} => {$status} {$answer}");
    }

    public function erase(): void
    {
        $this->database->erase();
    }

    /**
     * @return array<int, array<string, mixed>> in the order the messages crossed
     */
    public function all(): array
    {
        $exchanges = $this->database->rows(
            'SELECT id, at, direction, message, target, headers, body, status, answer, answer_headers AS answerHeaders '
            . 'FROM wire ORDER BY id',
        );

        foreach ($exchanges as &$exchange) {
            $exchange['headers'] = json_decode($exchange['headers'], true, flags: JSON_THROW_ON_ERROR);
            $exchange['answerHeaders'] = json_decode($exchange['answerHeaders'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $exchanges;
    }
}
