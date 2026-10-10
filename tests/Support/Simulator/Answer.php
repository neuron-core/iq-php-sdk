<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

/**
 * What the endpoint answered to one message of the platform.
 */
final class Answer
{
    /**
     * @param int $status the HTTP status; 0 when no usable answer came: a timeout, a refused connection, or a
     *                    body that breaks the schema of its answer
     * @param array<string, mixed> $fields the body, when the platform reads it: of a 200 and of a 409
     * @param int|null $retryAfter seconds, as the Retry-After header of the answer asks
     */
    public function __construct(
        public readonly int $status,
        public readonly array $fields = [],
        public readonly ?int $retryAfter = null,
    ) {
    }
}
