<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

/**
 * An HTTP client that gives every request the answer the test scripted, and
 * records what it was sent.
 */
class ScriptedHttpClient implements HttpClientInterface
{
    /** @var HttpRequest[] */
    protected array $received = [];

    /**
     * @param HttpResponse|Throwable $answer what every request gets: a response, or what the client throws
     * @param bool $throwsStatusErrors true: a response of 400 and above is thrown with the response attached, as
     *                                 Neuron's clients do; false: it is returned, as a client with its own policy may
     * @param StreamInterface|null $stream what a streamed request reads; without one it fails
     */
    public function __construct(
        protected HttpResponse|Throwable $answer,
        protected bool $throwsStatusErrors = true,
        protected ?StreamInterface $stream = null,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->received[] = $request;

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        if ($this->throwsStatusErrors && $this->answer->statusCode >= 400) {
            throw HttpException::statusError($request, $this->answer);
        }

        return $this->answer;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $this->received[] = $request;

        return $this->stream ?? throw HttpException::networkError($request, 'The scripted client has no stream.');
    }

    /**
     * @return HttpRequest[] every request received, in order
     */
    public function received(): array
    {
        return $this->received;
    }
}
