<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\EndpointFlows;

use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\NeuronCloud;

/**
 * A cloud whose endpoint answers as it always does, and remembers every
 * request it was handed: what the platform put on the wire.
 */
class TappedCloud extends NeuronCloud
{
    /** @var array<int, array{method: string, headers: array<array-key, mixed>, body: string}> */
    protected array $handled = [];

    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        $this->handled[] = ['method' => $method, 'headers' => $headers, 'body' => $body];

        return parent::handle($method, $headers, $body);
    }

    /**
     * @return array<int, array{method: string, headers: array<array-key, mixed>, body: string}> every request, in order
     */
    public function handled(): array
    {
        return $this->handled;
    }
}
