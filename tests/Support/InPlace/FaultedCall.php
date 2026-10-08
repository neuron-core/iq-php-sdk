<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

use NeuronCore\Cloud\Tests\Support\Faults\StoreOperation;

/**
 * What one segment run in the calling process left behind when the store
 * failed once meanwhile, and what a worker then told the platform.
 */
final class FaultedCall
{
    /**
     * @param string $label names the case in a failure message
     * @param StoreOperation|null $struck the operation the fault hit; null when the call had no fault
     * @param Played $played what the call left behind
     * @param array<class-string, int> $bodiesRun how many times each node body ran during the call
     * @param int $runsCreated how many times the store created a run for the workflow ID during the call
     * @param int $exposed how many operations of the call a fault of the mode could have hit
     * @param array<string, mixed>|null $reported the report a worker posted once it ran the queue; null when the
     *                                            call queued nothing
     * @param array<string, mixed>|null $leftWhenReported the run the store held when that report was posted
     */
    public function __construct(
        public readonly string $label,
        public readonly ?StoreOperation $struck,
        public readonly Played $played,
        public readonly array $bodiesRun,
        public readonly int $runsCreated,
        public readonly int $exposed,
        public readonly ?array $reported,
        public readonly ?array $leftWhenReported,
    ) {
    }
}
