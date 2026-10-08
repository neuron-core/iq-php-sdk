<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support;

use NeuronAI\HttpClient\HttpRequest;
use NeuronCore\Cloud\Testing\FakePlatform;

use function array_column;

/**
 * The fake platform, opened for the SDK's own tests: they read every request
 * the SDK sent, which an application's test has no reason to do.
 */
final class InspectablePlatform extends FakePlatform
{
    /**
     * @return HttpRequest[] every request received, recorded or not, in order
     */
    public function received(): array
    {
        return array_column($this->requests, 'request');
    }
}
