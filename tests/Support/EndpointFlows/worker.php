<?php

declare(strict_types=1);

/*
 * bin/worker.php of the plain PHP example, for one message: a process of its own that builds the NeuronCloud
 * of the shop from its only argument, gives its message to invoke() and prints the report that came back.
 * Its calls to the platform go through its standard output and input to the test that spawned it. What
 * invoke() throws ends the process with no report, which fails that test.
 */

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

use NeuronCore\Cloud\Invocation;

use function dirname;
use function json_decode;
use function json_encode;
use function pcntl_alarm;

use const JSON_THROW_ON_ERROR;
use const STDIN;
use const STDOUT;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A worker that a failed test left inside a node must not outlive it.
pcntl_alarm(30);

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$cloud = Shop::bootstrap($job['settings'], new PipedPlatform(STDIN, STDOUT));

$report = $cloud->invoke(Invocation::fromArray($job['message']));

echo json_encode(['report' => $report->toArray()], JSON_THROW_ON_ERROR), "\n";
