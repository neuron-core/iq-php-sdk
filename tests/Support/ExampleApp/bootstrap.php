<?php

declare(strict_types=1);

/*
 * bootstrap.php of example (a) of section 5 of the design: required by the endpoint, the web scripts, the worker
 * and the sync script. The environment configures it:
 *
 *   SHOP_HOME                   the directory with the SQLite files of the workflow store and of the queue
 *   NEURON_CLOUD_API_KEY        the API key
 *   NEURON_CLOUD_SIGNING_KEY    the signing key; only the endpoint needs it
 *   NEURON_CLOUD_PLATFORM_URL   where the platform is
 *   SHOP_LEASE                  seconds of the lease of a segment (600); a sync refuses less than 60
 *   SHOP_VERSIONS               the versions of the definition this code holds, separated by commas (1)
 *   SHOP_PAYMENT_DEADLINE       seconds an order waits for its payment (3600)
 *   SHOP_SHIPS_AFTER            seconds between the payment and the moment the order ships (86400)
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronCore\Cloud\NeuronCloud;

use function array_map;
use function dirname;
use function explode;
use function getenv;
use function intval;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$home = new Home((string) getenv('SHOP_HOME'));
$pdo = $home->store();
$spool = new Spool($home->queue());
$faults = new Faults($home->faults());
$runs = new NodeRuns($home->executions());

$paymentDeadline = (int) (getenv('SHOP_PAYMENT_DEADLINE') ?: 3600);
$shipsAfter = (int) (getenv('SHOP_SHIPS_AFTER') ?: 86400);

$cloud = (new NeuronCloud(
    apiKey: (string) getenv('NEURON_CLOUD_API_KEY'),
    signingKey: getenv('NEURON_CLOUD_SIGNING_KEY') ?: null,
    platformUrl: (string) getenv('NEURON_CLOUD_PLATFORM_URL'),
))
    ->setPersistence(static fn (): PersistenceInterface => new FaultyStore(new DatabasePersistence($pdo), $faults))
    ->setDispatcher(new SpoolDispatcher($spool));

// One register() call per version: a deploy that still has runs on an old version keeps it registered.
foreach (array_map(intval(...), explode(',', getenv('SHOP_VERSIONS') ?: '1')) as $version) {
    $cloud->register(
        Shop::DEFINITION,
        static fn (): OrderWorkflow => new OrderWorkflow($runs, $faults, $paymentDeadline, $shipsAfter),
        $version,
        (int) (getenv('SHOP_LEASE') ?: 600),
    );
}

return new Shop($cloud, $spool, $faults);
