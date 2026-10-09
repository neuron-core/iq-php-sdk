<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

use function fgets;
use function fwrite;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The HTTP client of a worker process: every request goes down a pipe to the
 * test that spawned the worker, which hands it to the one fake platform and
 * sends the answer back.
 */
final class PipedPlatform implements HttpClientInterface
{
    /**
     * @param resource $answers where the test writes one answer per line
     * @param resource $requests where the worker writes one request per line
     */
    public function __construct(protected mixed $answers, protected mixed $requests)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        fwrite($this->requests, json_encode(['request' => [
            'uri' => $request->uri,
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeout,
        ]], JSON_THROW_ON_ERROR) . "\n");

        $answer = json_decode((string) fgets($this->answers), true, flags: JSON_THROW_ON_ERROR);
        $response = new HttpResponse($answer['status'], $answer['body']);

        if ($response->statusCode >= 400) {
            // Every client of Neuron throws such an answer, with the response attached.
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw HttpException::networkError($request, 'Neuron Cloud has no streaming endpoint.');
    }
}
