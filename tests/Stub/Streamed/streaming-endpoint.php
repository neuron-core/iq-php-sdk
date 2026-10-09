<?php

declare(strict_types=1);

/*
 * The streaming endpoint of an application, for PHP's built-in server. It starts a run of "tale" through the SDK and
 * sends each word it streams to the browser as a server-sent event, as the endpoint of example (b) of the design does.
 *
 *   /idle          is answered at once
 *   anything else  streams a new run
 *
 * Usage: NEURON_CLOUD_STREAM_FILES=<directory> php -S 127.0.0.1:<port> streaming-endpoint.php
 */

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronCore\Cloud\Tests\Support\Streamed\StreamingEndpoint;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

if (\parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH) === StreamingEndpoint::IDLE) {
    echo 'idle';

    return;
}

$events = StreamingEndpoint::application((string) \getenv('NEURON_CLOUD_STREAM_FILES'))
    ->workflow(StreamingEndpoint::DEFINITION, StreamingEndpoint::WORKFLOW_ID)
    ->start()
    ->events();

// Admit now: a refusal would be an HTTP status, not a frame.
$events->valid();

\header('Content-Type: text/event-stream');

while (\ob_get_level() > 0) {
    \ob_end_flush();
}

foreach ($events as $frame) {
    echo 'data: ' . ($frame instanceof TextChunk ? $frame->content : $frame::class) . "\n\n";
    \flush();

    // PHP learns that the browser has gone when a write to it fails, and the first write after the browser left
    // still succeeds: the network needs a moment to say that nobody is there.
    \usleep(100_000);
}
