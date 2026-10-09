<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndpointFlows;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Runtime\Protocol;
use NeuronCore\Cloud\Testing\FakePlatform;

use function array_column;

/**
 * The fake platform, opened for a simulator that plays its part and for the
 * test that checks the wire: what it recorded, every request it received and
 * every exchange it had with an endpoint.
 */
final class ReadablePlatform extends FakePlatform
{
    /** @var array<int, array{message: array<string, mixed>, answer: HttpResponse}> */
    protected array $exchanges = [];

    public function deliver(NeuronCloud $cloud, array $message, ?int $timestamp = null): HttpResponse
    {
        $answer = parent::deliver($cloud, $message, $timestamp);
        $this->exchanges[] = ['message' => $message + ['protocol' => Protocol::VERSION], 'answer' => $answer];

        return $answer;
    }

    /**
     * @return array<int, array<string, mixed>> the announcements the platform recorded, in order
     */
    public function announcements(): array
    {
        return $this->recorded(self::ANNOUNCEMENTS);
    }

    /**
     * @return array<int, array<string, mixed>> the reports the platform recorded, in order
     */
    public function reports(): array
    {
        return $this->recorded(self::REPORTS);
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
    public function exchanges(): array
    {
        return $this->exchanges;
    }
}
