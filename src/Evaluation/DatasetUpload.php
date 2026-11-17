<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Evaluation;

/**
 * What the platform did with the items of a dataset sent to it by
 * {@see \NeuronCore\Cloud\NeuronCloud::uploadDataset()}: whether it created the
 * dataset, and how many items it added, updated and found unchanged.
 */
class DatasetUpload
{
    public function __construct(
        public readonly string $slug,
        public readonly bool $created = false,
        public readonly int $added = 0,
        public readonly int $updated = 0,
        public readonly int $unchanged = 0,
    ) {
    }

    /**
     * This upload with the answer to one more request of it.
     *
     * @param array{slug: string, created: bool, added: int, updated: int, unchanged: int} $answer
     */
    public function with(array $answer): self
    {
        return new self(
            $answer['slug'],
            $this->created || $answer['created'],
            $this->added + $answer['added'],
            $this->updated + $answer['updated'],
            $this->unchanged + $answer['unchanged'],
        );
    }

    /**
     * One line for a person, e.g. "refund-requests: created, 12 added, 0 updated, 0 unchanged".
     */
    public function summary(): string
    {
        return $this->slug . ': ' . ($this->created ? 'created, ' : '')
            . "{$this->added} added, {$this->updated} updated, {$this->unchanged} unchanged";
    }
}
