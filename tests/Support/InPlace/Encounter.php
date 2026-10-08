<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\InPlace;

/**
 * A start run in place while a run held the workflow ID.
 */
final class Encounter
{
    /**
     * @param Played $start what the start that met the holder left behind
     * @param array<string, mixed>|null $heldBefore the holder as the store showed it just before that start
     * @param array<string, mixed>|null $heldAfter the run the store held just after it
     * @param Played|null $holder what the holder left behind in the end, when it was executing in this process
     */
    public function __construct(
        public readonly Played $start,
        public readonly ?array $heldBefore,
        public readonly ?array $heldAfter,
        public readonly ?Played $holder,
    ) {
    }
}
