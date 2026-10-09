<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Requests;

use NeuronAI\HttpClient\HttpResponse;

/**
 * One request handed to handle(), and the answer it gave.
 */
final class Exchange
{
    public function __construct(
        public readonly string $method,
        public readonly string $body,
        public readonly HttpResponse $answer,
    ) {
    }
}
