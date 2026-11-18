<?php

declare(strict_types=1);

namespace NeuronCore\IQ;

/**
 * What the platform answered to a sync.
 */
final class SyncResult
{
    /**
     * @internal IQ::sync() builds it
     *
     * @param bool $modified false: nothing changed
     * @param array<string, int[]> $retirable name => older versions with no open run
     * @param array<string, array<int, int>> $stranded name => [version => open runs]: gone from the code, runs remain
     */
    public function __construct(
        public readonly bool $modified,
        public readonly array $retirable,
        public readonly array $stranded,
    ) {
    }
}
