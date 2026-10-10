<?php

declare(strict_types=1);

/*
 * An endpoint that answers the platform what a test told it to, in the place of an application: the front
 * controller of ScriptedEndpoint on PHP's built-in server. It verifies nothing. The file "answers.<verb>" of
 * the directory SCRIPTED_ENDPOINT_HOME names holds one JSON line per answer the test scripted for that verb;
 * a message takes the first away. A verb with no line left gets the plain success of the protocol.
 *
 * Usage: SCRIPTED_ENDPOINT_HOME=<directory> php -S 127.0.0.1:<port> scripted-endpoint.php
 */

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use function file_get_contents;
use function getenv;
use function header;
use function http_response_code;
use function json_decode;
use function json_encode;
use function time;
use function usleep;

use const JSON_THROW_ON_ERROR;

require __DIR__ . '/../../../vendor/autoload.php';

$verb = (string) (json_decode((string) file_get_contents('php://input'), true)['verb'] ?? '');
$scripted = ScriptedEndpoint::take((string) getenv('SCRIPTED_ENDPOINT_HOME'), $verb);

$answer = $scripted ?? match ($verb) {
    'ping' => ['status' => 200, 'body' => ['protocols' => [1]]],
    'start', 'wake' => ['status' => 202, 'body' => []],
    'inspect' => ['status' => 200, 'body' => ['run' => null, 'claimed' => false, 'snapshotRead' => true, 'at' => time()]],
    'abandon' => ['status' => 200, 'body' => ['run' => null, 'claimed' => true, 'snapshotRead' => true, 'at' => time()]],
    default => ['status' => 400, 'body' => ['code' => 'invalid_request']],
};

usleep((int) (($answer['delay'] ?? 0) * 1_000_000));

http_response_code($answer['status']);
header('Content-Type: application/json');
foreach ($answer['headers'] ?? [] as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode((object) $answer['body'], JSON_THROW_ON_ERROR);
