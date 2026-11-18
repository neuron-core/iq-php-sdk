<?php

declare(strict_types=1);

/*
 * The platform simulator on PHP's built-in server: every request goes through here, one at a time.
 *
 * Usage: SIMULATOR_DATABASE=<file> SIMULATOR_WIRE_TAP=<file> SIMULATOR_API_KEYS=<keys> SIMULATOR_SIGNING_KEYS=<keys> \
 *        php -S 127.0.0.1:<port> server.php
 */

namespace NeuronCore\IQ\Tests\Support\Simulator;

use function dirname;
use function file_get_contents;
use function getallheaders;
use function header;
use function http_response_code;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$status, $answer] = Simulator::fromEnvironment()->handle(
    (string) $_SERVER['REQUEST_METHOD'],
    (string) $_SERVER['REQUEST_URI'],
    getallheaders(),
    (string) file_get_contents('php://input'),
);

http_response_code($status);
header('Content-Type: application/json');
echo $answer;
