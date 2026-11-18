<?php

declare(strict_types=1);

/*
 * One request or one queue worker of an application, in a process of its own. It rebuilds the IQ of
 * the bench that spawned it from its only argument, says "ready", waits on its standard input for the instant
 * to act at, makes its call and prints what the call returned or threw.
 */

namespace NeuronCore\IQ\Tests\Support\Flows;

use function dirname;
use function fgets;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function pcntl_alarm;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const STDIN;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A process that a failed test left inside a node must not outlive it.
pcntl_alarm(30);

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$iq = LocalProcessBench::application($job['files']);

echo "ready\n";

$instant = fgets(STDIN);
if ($instant === false) {
    // The test went away before it gave the order.
    exit(1);
}
usleep((int) max(0, ((float) $instant - microtime(true)) * 1_000_000));

echo json_encode(ApplicationCall::perform($iq, $job['call']), JSON_THROW_ON_ERROR), "\n";
