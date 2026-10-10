<?php

declare(strict_types=1);

/*
 * The checkout of the shop, as a browser reaches it: POST {"orderId": "...", "note": "...", "inPlace": false}.
 * It starts the order locally, as example (a) does: on the queue, answered 202 with the run ID, or with its first
 * segment run in this request when "inPlace" is true, answered 200. An order that already has a run is a 409.
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Exceptions\RunInFlightException;

use function file_get_contents;
use function header;
use function http_response_code;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

$order = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$orderId = (string) $order['orderId'];
$start = $shop->cloud->workflow(Shop::DEFINITION, OrderWorkflow::workflowIdOf($orderId))
    ->start(new OrderPlaced($orderId, (string) ($order['note'] ?? '')));

header('Content-Type: application/json');

try {
    if ($order['inPlace'] ?? false) {
        // ...or run the first segment in this process
        $state = $start->run();
        echo json_encode(['orderId' => $state->get('orderId')], JSON_THROW_ON_ERROR);
    } else {
        // checkout: start locally, on the queue...
        http_response_code(202);
        echo json_encode(['runId' => $start->dispatch()], JSON_THROW_ON_ERROR);
    }
} catch (RunInFlightException) {
    http_response_code(409);
    echo '{}';
}
