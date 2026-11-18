<?php

declare(strict_types=1);

/*
 * A platform for PHP's built-in server. It writes every request it receives,
 * one JSON object per line, in the file IQ_REQUEST_LOG names.
 *
 *   /moved/...   is answered 307, to the same path without that prefix
 *   /silent/...  is never answered
 *   GET /v1/datasets/<slug> is answered with <slug>.json of the directory IQ_DATASETS names, or 404
 *   POST /v1/datasets/<slug>/items is answered as a write that created the dataset and added every item
 *   anything else is recorded
 *
 * Usage: IQ_REQUEST_LOG=<file> IQ_DATASETS=<directory> php -S 127.0.0.1:<port> platform-server.php
 */

$path = (string) \parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

\file_put_contents(
    (string) \getenv('IQ_REQUEST_LOG'),
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && \str_starts_with($path, '/v1/datasets/')) {
    $dataset = \getenv('IQ_DATASETS') . '/' . \basename(\rawurldecode(\substr($path, \strlen('/v1/datasets/')))) . '.json';

    if (!\is_file($dataset)) {
        // What the platform answers for a slug it does not know.
        \http_response_code(404);
        echo '{"message":"Not found."}';

        return;
    }

    echo \file_get_contents($dataset);

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && \preg_match('#^/v1/datasets/([^/]+)/items$#', $path, $matches) === 1) {
    $items = \json_decode((string) \file_get_contents('php://input'), true)['items'] ?? [];
    echo \json_encode(['slug' => \rawurldecode($matches[1]), 'created' => true, 'added' => \count($items), 'updated' => 0, 'unchanged' => 0]);

    return;
}

echo '{"recorded":true}';
