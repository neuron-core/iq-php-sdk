<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Endpoint;

use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\IQ;

/**
 * A service whose endpoint only records the requests it is handed, and gives
 * each the answer the test chose.
 */
class RecordingIQ extends IQ
{
    /** @var array<int, array{method: string, headers: array<array-key, mixed>, body: string}> */
    protected array $handled = [];

    public function __construct(protected HttpResponse $answer = new HttpResponse(202, '{}'))
    {
        parent::__construct('test-api-key');
    }

    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        $this->handled[] = ['method' => $method, 'headers' => $headers, 'body' => $body];

        return $this->answer;
    }

    /**
     * @return array<int, array{method: string, headers: array<array-key, mixed>, body: string}> every request, in order
     */
    public function handled(): array
    {
        return $this->handled;
    }
}
