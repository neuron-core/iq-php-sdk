<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use function array_filter;
use function count;
use function str_contains;

/**
 * Every operation a store performed, with its answer: what really happened,
 * whatever the callers were told.
 */
final class StoreJournal
{
    /** @var array<int, array{operation: StoreOperation, answer: bool|string|null}> */
    protected array $performed = [];

    public function record(StoreOperation $operation, bool|string|null $answer): void
    {
        $this->performed[] = ['operation' => $operation, 'answer' => $answer];
    }

    /**
     * How many of the operations performed a fault of this mode could have hit.
     */
    public function exposedTo(FaultMode $mode): int
    {
        return count(array_filter(
            $this->performed,
            static fn (array $entry): bool => $mode === FaultMode::BeforeOperation || $entry['operation']->writes(),
        ));
    }

    /**
     * How many times a run was created for the workflow ID.
     */
    public function ignitions(string $workflowId): int
    {
        return $this->succeeded('initializeIfAbsent', $workflowId);
    }

    /**
     * How many times the records of a run of the workflow ID were removed: a
     * run that completes under the SDK ends with that write.
     */
    public function removals(string $workflowId): int
    {
        return $this->succeeded('deleteIfUnchanged', $workflowId);
    }

    /**
     * Whether a value written to the store contained the text.
     */
    public function wrote(string $text): bool
    {
        foreach ($this->performed as ['operation' => $operation, 'answer' => $answer]) {
            foreach ($operation->records as $value) {
                if ($answer === true && $value !== null && str_contains($value, $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function succeeded(string $method, string $partition): int
    {
        return count(array_filter(
            $this->performed,
            static fn (array $entry): bool => $entry['operation']->method === $method
                && $entry['operation']->partition === $partition
                && $entry['answer'] === true,
        ));
    }
}
