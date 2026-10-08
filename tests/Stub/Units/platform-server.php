<?php

declare(strict_types=1);

/*
 * A platform for PHP's built-in server. It writes every request it receives,
 * one JSON object per line, in the file NEURON_CLOUD_REQUEST_LOG names.
 *
 *   /moved/...   is answered 307, to the same path without that prefix
 *   /silent/...  is never answered
 *   anything else is recorded
 *
 * Usage: NEURON_CLOUD_REQUEST_LOG=<file> php -S 127.0.0.1:<port> platform-server.php
 */

$path = (string) \parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

\file_put_contents(
    (string) \getenv('NEURON_CLOUD_REQUEST_LOG'),
    \json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'headers' => getallheaders(),
        'body' => (string) \file_get_contents('php://input'),
    ], \JSON_THROW_ON_ERROR) . "\n",
    \FILE_APPEND | \LOCK_EX,
);

if (\str_starts_with($path, '/moved/')) {
    \header('Location: ' . \substr($path, \strlen('/moved')), true, 307);

    return;
}

if (\str_starts_with($path, '/silent/')) {
    // Nobody writes to the input of this server: reading it waits, with no timer, until the test ends the process.
    \fgets(\fopen('php://stdin', 'r'));

    return;
}

\header('Content-Type: application/json');
echo '{"recorded":true}';
