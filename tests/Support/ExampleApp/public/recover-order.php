<?php

declare(strict_types=1);

/*
 * The back office of the shop, where a person has an order that failed tried again now, without waiting for the
 * retry of the platform: POST {"orderId": "..."}. recover() finishes the failed run in this request. A start
 * would not: a start never replaces a run. Answered 200 with whether there was something to finish.
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Workflow\WorkflowState;

use function file_get_contents;
use function header;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

$order = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$state = $shop->cloud->workflow(Shop::DEFINITION, OrderWorkflow::workflowIdOf((string) $order['orderId']))->recover();

header('Content-Type: application/json');
echo json_encode(['recovered' => $state instanceof WorkflowState], JSON_THROW_ON_ERROR);
