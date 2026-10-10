<?php

declare(strict_types=1);

/*
 * The back office of the shop, where a person approves or declines by hand a payment the provider could not
 * settle: POST {"orderId": "...", "approved": true, "reviewer": "..."}. The decision answers the wait of the
 * order with submitInputs(), on the queue. A declined order ends without shipping. An order that waits for
 * nothing is a 400, and one that waits for something else a 409.
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

$review = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);

try {
    $shop->cloud->workflow(Shop::DEFINITION, OrderWorkflow::workflowIdOf((string) $review['orderId']))
        ->submitInputs(['approved' => (bool) $review['approved'], 'reviewer' => (string) $review['reviewer']])
        ->dispatch();

    http_response_code(202);
} catch (InputTranslationException) {
    http_response_code(400);                         // no order waits for a decision
} catch (WorkflowRefusedException) {
    http_response_code(409);                         // the order waits for something a decision cannot answer
}
