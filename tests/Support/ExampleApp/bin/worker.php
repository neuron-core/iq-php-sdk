<?php

declare(strict_types=1);

/*
 * bin/worker.php of example (a): one message, one call. What invoke() throws decides where the message goes
 * next, as section 9.5 of the design says: to the "later" list, which a cron moves back, or to the "failed" list,
 * which a person replays. Run one process per concurrent segment, and at least one on the list of the notices.
 *
 * Usage: php bin/worker.php [list]      the list defaults to the one of the segments
 *
 * SHOP_WORKER_LIFETIME bounds the life of the process in seconds (300), so that a worker a supervisor lost
 * does not run for ever.
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Verb;

use function getenv;
use function in_array;
use function json_decode;
use function pcntl_alarm;
use function time;

use const JSON_THROW_ON_ERROR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';
$list = $argv[1] ?? SpoolDispatcher::SEGMENTS;

pcntl_alarm((int) (getenv('SHOP_WORKER_LIFETIME') ?: 300));
echo "The worker of '{$list}' is up.\n";

/**
 * One line of the log per message. Nothing else tells that a start of the shop's own did not run although
 * dispatch() returned its run ID: rejected, refused, or aborted after its claim.
 */
function describe(Invocation $invocation, Report $report): string
{
    $line = "{$invocation->verb->value} of {$invocation->workflowId} ({$invocation->runId}): {$report->outcome->value}"
        . ($report->code === null ? '' : " {$report->code}");

    $didNotRun = in_array($report->outcome, [Outcome::Rejected, Outcome::Refused, Outcome::Aborted], true);

    return $invocation->verb === Verb::Start && $invocation->origin === 'application' && $didNotRun
        ? "{$line}. THIS START OF THE SHOP DID NOT RUN."
        : $line;
}

while ($job = $shop->spool->pop($list)) {
    $shop->faults->at('worker.taken');
    $invocation = Invocation::fromArray(json_decode($job, true, flags: JSON_THROW_ON_ERROR));

    try {
        $report = $shop->cloud->invoke($invocation);
        echo describe($invocation, $report), "\n";
    } catch (CloudException $e) {
        $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
        $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;
        $keptAside = $final || $spent;
        $shop->spool->push($keptAside ? "{$list}:failed" : "{$list}:later", $job);

        echo "{$invocation->verb->value} of {$invocation->workflowId} ({$invocation->runId}): {$e->reason->value}, "
            . ($keptAside ? 'kept aside for a person' : 'left for later') . "\n";
    }

    $shop->faults->at('worker.done');
}
