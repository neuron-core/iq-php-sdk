<?php

declare(strict_types=1);

/*
 * The streaming endpoint of example (b) of the design on PHP's built-in server: the front controller of a chat
 * application whose home is the directory STREAMED_CHAT_HOME names. The body of the request is the JSON that
 * ChatEndpoint takes. Four query parameters set the scene of a test:
 *
 *   pause         microseconds the model waits between two chunks of its answer
 *   lease         seconds of the lease the application registered
 *   dies_after    the number of frames after which the request dies of a fatal error (memory exhausted)
 *   ignore_abort  1: the request goes on when its client has left, as ignore_user_abort(true) makes it
 *
 * Usage: STREAMED_CHAT_HOME=<directory> php -S 127.0.0.1:<port> front-controller.php
 */

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\PacedModel;

use function dirname;
use function file_get_contents;
use function flush;
use function getenv;
use function header;
use function http_response_code;
use function ignore_user_abort;
use function ini_set;
use function json_decode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ignore_user_abort((bool) ($_GET['ignore_abort'] ?? false));

$home = new ChatHome((string) getenv('STREAMED_CHAT_HOME'));
$model = new PacedModel((int) ($_GET['pause'] ?? 0), new AssistantMessage(ChatServer::ANSWER));
$cloud = $home->application($model, $home->sentByTheServer, new Broadcast(), (int) ($_GET['lease'] ?? 600));

$response = (new ChatEndpoint($cloud))(json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR));

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}

$sent = 0;
foreach ($response->frames() as $frame) {
    echo $frame;
    flush();

    if (++$sent === (int) ($_GET['dies_after'] ?? 0)) {
        exhaustMemory();
    }
}

/**
 * A fatal error, as a request that runs out of memory raises it: the allocation fails before anything exits.
 */
function exhaustMemory(): never
{
    ini_set('memory_limit', '32M');

    exit(str_repeat('x', 64 * 1024 * 1024));
}
