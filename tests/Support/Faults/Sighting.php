<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use NeuronCore\IQ\Code;

/**
 * A report the platform received, beside what was true when it was sent.
 */
final class Sighting
{
    /**
     * @param array<string, mixed> $report the body of the report
     * @param array<string, mixed>|null $stored the run the store held for the workflow ID, read without the SDK
     * @param bool $ended whether Neuron had removed the records of the run by then, as its completion does
     * @param Code|null $thrown the reason invoke() threw with after its job posted this report; null when it returned
     */
    public function __construct(
        public readonly array $report,
        public readonly ?array $stored,
        public readonly bool $ended,
        public readonly ?Code $thrown,
    ) {
    }
}
