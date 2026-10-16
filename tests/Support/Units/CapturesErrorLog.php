<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Units;

use Closure;

use function file_get_contents;
use function ini_get;
use function ini_set;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * What is sent to the platform on a best effort is logged with error_log()
 * when it fails, and never thrown: a test reads the log.
 */
trait CapturesErrorLog
{
    /**
     * @param Closure(): mixed $call
     * @return string what PHP's error log received while the call ran
     */
    protected static function loggedBy(Closure $call): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'neuron-cloud-log-');
        $configured = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $call();

            return (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $configured);
            unlink($log);
        }
    }
}
