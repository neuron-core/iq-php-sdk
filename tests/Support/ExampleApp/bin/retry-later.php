<?php

declare(strict_types=1);

/*
 * The cron of example (a): every minute it moves the "later" lists back to their queues, which stands for the
 * delays of section 9.5 of the design.
 *
 * Usage: php bin/retry-later.php
 */

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

foreach ([SpoolDispatcher::SEGMENTS, SpoolDispatcher::NOTICES] as $list) {
    $shop->spool->move("{$list}:later", $list);
}
