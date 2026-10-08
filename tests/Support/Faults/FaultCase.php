<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Faults;

use NeuronAI\HttpClient\HttpRequest;

use function str_contains;

/**
 * What one drill left behind: everything the SDK said, beside what really happened.
 */
final class FaultCase
{
    /**
     * @param string $label names the case in a failure message
     * @param StoreOperation|null $struck the operation the fault hit; null when the drill had no fault
     * @param bool $committed whether the store did what that operation asked for before it failed
     * @param Sighting[] $sightings every report the platform received, in order
     * @param array<string, int> $executions how many times each node body ran, by node class
     * @param int $ignitions how many times a run was created for the workflow ID
     * @param bool $ended whether Neuron removed the records of the run, as its completion does
     * @param array<string, mixed>|null $left the run the store holds at the end
     * @param HttpRequest[] $requests everything the platform was sent
     */
    public function __construct(
        public readonly string $label,
        public readonly ?StoreOperation $struck,
        public readonly bool $committed,
        public readonly array $sightings,
        public readonly array $executions,
        public readonly int $ignitions,
        public readonly bool $ended,
        public readonly ?array $left,
        public readonly array $requests,
    ) {
    }

    /**
     * Whether the fault kept the step of a node from being committed: that
     * node body, and no other, has work to do again. The store failed either
     * while the body ran, which shows as an operation on a memo of the step,
     * or at the write of the step itself, without performing it.
     */
    public function leftUncommitted(string $node): bool
    {
        foreach ($this->struck?->keys() ?? [] as $key) {
            if (str_contains($key, "/{$node}-")) {
                return str_contains($key, '::') || ($this->struck->writes() && !$this->committed);
            }
        }

        return false;
    }
}
