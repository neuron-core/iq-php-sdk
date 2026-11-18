<?php

declare(strict_types=1);

/*
 * public/iq.php of example (a): the signed endpoint the platform calls.
 */

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use function file_get_contents;
use function getallheaders;
use function header;
use function http_response_code;

/** @var Shop $shop */
$shop = require __DIR__ . '/../bootstrap.php';

$response = $shop->iq->handle((string) $_SERVER['REQUEST_METHOD'], getallheaders(), (string) file_get_contents('php://input'));
$shop->faults->at('endpoint.answer');

http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo $response->body;
