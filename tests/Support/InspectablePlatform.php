<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Testing\FakePlatform;

use function array_column;

/**
 * The fake platform, opened for the SDK's own tests: they read every request
 * the SDK sent and every message the endpoint was delivered, which an
 * application's test has no reason to do.
 */
final class InspectablePlatform extends FakePlatform
{
    /** @var array<int, array{message: array<string, mixed>, answer: HttpResponse}> */
    protected array $delivered = [];

    public function deliver(NeuronCloud $cloud, array $message, ?int $timestamp = null): HttpResponse
    {
        $answer = parent::deliver($cloud, $message, $timestamp);
        $this->delivered[] = ['message' => $message + ['protocol' => 1], 'answer' => $answer];

        return $answer;
    }

    /**
     * @return HttpRequest[] every request received, recorded or not, in order
     */
    public function received(): array
    {
        return array_column($this->requests, 'request');
    }

    /**
     * @return array<int, array{message: array<string, mixed>, answer: HttpResponse}> every message delivered to an
     *                                                                                endpoint, a wake included, with
     *                                                                                its answer, in order
     */
    public function delivered(): array
    {
        return $this->delivered;
    }
}
