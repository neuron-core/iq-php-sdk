<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

/**
 * A set of processes and temporary files that a test started and must end.
 */
interface Stage
{
    /**
     * @return string what every process wrote: the log a failing test prints
     */
    public function logs(): string;

    /**
     * Stop every process and remove every file.
     */
    public function tearDown(): void;
}
