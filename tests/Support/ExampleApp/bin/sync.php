<?php

declare(strict_types=1);

/*
 * bin/sync.php of example (a): the last step of a deploy. It publishes the definitions of the shop and the URL
 * of its endpoint, SHOP_URL followed by /neuron-cloud.php, and prints what the platform answered. A sync the
 * platform refuses ends the script with a status of 1, which fails the deploy.
 *
 * Usage: SHOP_URL=https://shop.example.com php bin/sync.php
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronCore\Cloud\CloudException;

use function fwrite;
use function getenv;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const STDERR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

try {
    $result = $shop->cloud->sync(getenv('SHOP_URL') . '/neuron-cloud.php');
} catch (CloudException $refused) {
    fwrite(STDERR, "The sync failed ({$refused->reason->value}).\n");

    exit(1);
}

echo json_encode(
    ['modified' => $result->modified, 'retirable' => $result->retirable, 'stranded' => $result->stranded],
    JSON_THROW_ON_ERROR,
), "\n";
