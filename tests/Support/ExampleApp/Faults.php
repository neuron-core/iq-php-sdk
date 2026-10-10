<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use function array_shift;
use function explode;
use function fclose;
use function file;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function implode;
use function is_file;
use function posix_getpid;
use function posix_kill;
use function rewind;
use function stream_get_contents;
use function trim;
use function unlink;
use function usleep;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const LOCK_EX;
use const SIGKILL;

/**
 * How a test makes the shop misbehave without editing it: a directory with
 * one file per point of the code, holding what the next passages through
 * that point do, one line each. A passage takes the first line away, so a
 * plan holds across processes and is spent once.
 *
 *   fail          the passage throws a PlannedFailure
 *   die           the process kills itself there, as kill -9 would
 *   sleep <n>     the passage takes n seconds longer
 *   hold          the passage stands there until the test releases it
 *   pass          the passage goes through, so that the next line meets a later one
 *
 * The points: "await-payment.<order>" and "ship.<order>" in the two node bodies, "await-payment.<order>.checked"
 * and "await-payment.<order>.told" further down the first one, after each of the two steps it tells once it has
 * checked the order, "worker.taken" and
 * "worker.done" around the invoke() of a worker, "store.read" and "store.write" before every operation of the
 * workflow store, "store.written" after every write of it, and "endpoint.answer" between the moment the
 * endpoint has dealt with a message of the platform and the moment it answers.
 */
final class Faults
{
    public const FAIL = 'fail';

    public const DIE = 'die';

    public const SLEEP = 'sleep';

    public const HOLD = 'hold';

    public const PASS = 'pass';

    /** Microseconds between two looks of a passage that holds. */
    protected const PATIENCE = 2_000;

    /**
     * @param string $directory an existing directory
     */
    public function __construct(protected string $directory)
    {
    }

    /**
     * What a test calls: plan what the next passages through a point do, after those already planned.
     *
     * @param string ...$passages one of the four lines above for each passage, in order
     */
    public function plan(string $point, string ...$passages): void
    {
        file_put_contents($this->scriptOf($point), implode("\n", $passages) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * What a test calls to know where a process stands: what is planned for a point and no passage took yet.
     *
     * @return string[]
     */
    public function pending(string $point): array
    {
        return is_file($this->scriptOf($point)) ? file($this->scriptOf($point), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [];
    }

    /**
     * What a test calls to let one passage that holds at a point go on: before or after the passage got there.
     */
    public function release(string $point): void
    {
        // Not touch(), which sets the time of the file after it made it: the passage may have taken it away by then.
        file_put_contents($this->releaseOf($point), '');
    }

    /**
     * What the shop calls where it can be made to misbehave.
     *
     * @throws PlannedFailure
     */
    public function at(string $point): void
    {
        [$action, $argument] = explode(' ', $this->next($point) . ' ');

        if ($action === self::DIE) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        if ($action === self::SLEEP) {
            usleep((int) ((float) $argument * 1_000_000));
        }

        // A passage that holds takes its release away: nothing depends on how long the test takes to give it.
        while ($action === self::HOLD && !@unlink($this->releaseOf($point))) {
            usleep(self::PATIENCE);
        }

        if ($action === self::FAIL) {
            throw new PlannedFailure(Confidential::EXCEPTION);
        }
    }

    /**
     * @return string what this passage does; empty when nothing is planned for it
     */
    protected function next(string $point): string
    {
        if (!is_file($this->scriptOf($point))) {
            return '';
        }

        $script = fopen($this->scriptOf($point), 'c+');
        if ($script === false) {
            return '';
        }

        // Several processes pass the same point: each takes a line of its own.
        flock($script, LOCK_EX);
        $passages = explode("\n", trim((string) stream_get_contents($script)));
        $next = array_shift($passages);
        ftruncate($script, 0);
        rewind($script);
        fwrite($script, $passages === [] ? '' : implode("\n", $passages) . "\n");
        fclose($script);

        return $next;
    }

    protected function scriptOf(string $point): string
    {
        return "{$this->directory}/{$point}";
    }

    protected function releaseOf(string $point): string
    {
        return "{$this->directory}/{$point}.released";
    }
}
