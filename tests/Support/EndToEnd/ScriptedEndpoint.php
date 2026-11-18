<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use function array_map;
use function array_shift;
use function explode;
use function fclose;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function glob;
use function implode;
use function is_file;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;
use function time;
use function trim;
use function unlink;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * The endpoint of an application that does not exist: a server that answers
 * each message of the platform with what the test scripted for its verb, or
 * with the plain success of the protocol. It is how a test shows the
 * simulator every status and every snapshot an application could answer,
 * without making a real one misbehave.
 */
final class ScriptedEndpoint
{
    /** The public address a sync publishes for it: the simulator is given a tunnel to the server. */
    public const PUBLIC_URL = 'https://application.example.com';

    protected Server $server;

    /**
     * @param string $directory an existing directory, for the script and the log of the endpoint
     */
    public function __construct(protected string $directory)
    {
        $this->server = new Server(
            [__DIR__ . '/scripted-endpoint.php'],
            ['SCRIPTED_ENDPOINT_HOME' => $directory],
            "{$directory}/endpoint.log",
        );
    }

    /**
     * The constructor started the server: wait until it listens.
     */
    public function awaitUp(): void
    {
        $this->server->awaitListening();
    }

    /**
     * @return string the local address the endpoint listens on
     */
    public function url(): string
    {
        return $this->server->url;
    }

    /**
     * Script the answer to the next message of a verb that has no answer scripted yet.
     *
     * @param string $verb start, wake, inspect, abandon or ping
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param float $delay seconds the endpoint waits before it answers
     */
    public function answerNext(string $verb, int $status, array $body = [], array $headers = [], float $delay = 0.0): void
    {
        file_put_contents(
            self::scriptOf($this->directory, $verb),
            json_encode(['status' => $status, 'body' => $body, 'headers' => $headers, 'delay' => $delay], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Script the 200 answer to the next inspect: the run that holds the workflow ID, as the store shows it.
     *
     * @param array<string, mixed>|null $run
     * @param bool|null $claimed whether the run ID the inspect names is in the ledger
     * @param float $delay seconds the endpoint waits before it answers
     */
    public function showNext(?array $run, ?bool $claimed = true, bool $snapshotRead = true, ?int $at = null, float $delay = 0.0): void
    {
        $this->answerNext(
            'inspect',
            200,
            ['run' => $run, 'claimed' => $claimed, 'snapshotRead' => $snapshotRead, 'at' => $at ?? time()],
            delay: $delay,
        );
    }

    /**
     * Drop every answer that is still scripted.
     */
    public function forget(): void
    {
        array_map(unlink(...), glob("{$this->directory}/answers.*") ?: []);
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    public function log(): string
    {
        return $this->server->log();
    }

    /**
     * What the front controller calls: take away the answer scripted for the next message of a verb.
     *
     * @return array<string, mixed>|null null when nothing is scripted for it
     */
    public static function take(string $directory, string $verb): ?array
    {
        if (!is_file(self::scriptOf($directory, $verb))) {
            return null;
        }

        $script = fopen(self::scriptOf($directory, $verb), 'c+');
        if ($script === false) {
            return null;
        }

        flock($script, LOCK_EX);
        $answers = explode("\n", trim((string) stream_get_contents($script)));
        $next = array_shift($answers);
        ftruncate($script, 0);
        rewind($script);
        fwrite($script, $answers === [] ? '' : implode("\n", $answers) . "\n");
        fclose($script);

        return $next === '' ? null : json_decode($next, true, flags: JSON_THROW_ON_ERROR);
    }

    protected static function scriptOf(string $directory, string $verb): string
    {
        return "{$directory}/answers.{$verb}";
    }
}
