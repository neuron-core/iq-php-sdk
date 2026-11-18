<?php

declare(strict_types=1);

/*
 * The checkout of the shop for a browser that shows the order as it is taken: POST {"orderId": "...", "note": "..."}.
 * The first segment runs in this request with events(), as the chat turn of example (b) does, and every step
 * the order reaches is sent as a server-sent event, then "suspended" when the order waits. An order that
 * already has a run is a 409.
 *
 * SHOP_OUTLIVES_ITS_BROWSER=1 makes the request go on when its browser has left: it then sees that nobody
 * reads and lets go of the stream. Without it PHP itself ends the request at the first frame it cannot send.
 */

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use NeuronAI\Exceptions\RunInFlightException;

use function connection_aborted;
use function file_get_contents;
use function flush;
use function getenv;
use function header;
use function http_response_code;
use function ignore_user_abort;
use function json_decode;
use function ob_end_flush;
use function ob_get_level;

use const JSON_THROW_ON_ERROR;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

ignore_user_abort((bool) getenv('SHOP_OUTLIVES_ITS_BROWSER'));

$order = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$orderId = (string) $order['orderId'];
$events = $shop->iq->workflow(Shop::DEFINITION, OrderWorkflow::workflowIdOf($orderId))
    ->start(new OrderPlaced($orderId, (string) ($order['note'] ?? '')))
    ->events();

try {
    $events->valid();                                // admit now: a refusal is an HTTP status, not a frame
} catch (RunInFlightException) {
    http_response_code(409);

    exit;
}

header('Content-Type: text/event-stream');
while (ob_get_level() > 0) {
    ob_end_flush();
}

foreach ($events as $event) {
    // One write per frame: the write after a browser has left is answered by a reset, and only a write that
    // comes after the reset fails. A frame sent in pieces could fail halfway, a frame earlier than expected.
    echo 'data: ' . ($event instanceof OrderProgress ? $event->step : 'suspended') . "\n\n";
    flush();

    if (connection_aborted() === 1) {
        break;
    }
}

// Nobody reads any more: a stream that is let go of now is settled while the queue can still take its observe.
unset($events);
