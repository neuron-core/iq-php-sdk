<?php

declare(strict_types=1);

/*
 * The payment webhook of the shop, as a payment provider reaches it:
 * POST {"orderId": "...", "amount": 49.9, "reference": "..."}. It answers the wait of the order locally, as
 * example (a) does. An order that is not at that wait is a 409: the sender retries.
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowRefusedException;

use function file_get_contents;
use function http_response_code;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

$payment = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);

try {
    $shop->cloud->workflow(Shop::DEFINITION, OrderWorkflow::workflowIdOf((string) $payment['orderId']))
        ->signal(OrderWorkflow::PAYMENT_RECEIVED, ['amount' => $payment['amount'], 'reference' => $payment['reference']])
        ->dispatch();

    http_response_code(202);
} catch (InputTranslationException|WorkflowRefusedException) {
    http_response_code(409);                         // the run is not at that wait: the sender retries
}
