<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Evaluation;

use NeuronAI\Evaluation\Contracts\NamedDatasetInterface;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Runtime\PlatformClient;

/**
 * A dataset kept by IQ, for an evaluator to run on.
 *
 * Build it from the SDK root in getDataset(), with the IQ the
 * evaluator receives from the application's container:
 *
 *   public function getDataset(): DatasetInterface
 *   {
 *       return $this->iq->dataset('refund-requests');
 *   }
 *
 * The items are read from the platform in load(), which Neuron's runner calls
 * once per evaluator, before the first item runs and before it forks for
 * --concurrency. Unlike the evaluation output it fails loud: without its items
 * there is nothing to evaluate, and Neuron's command reports the evaluator as
 * one that could not run.
 *
 * Its name is the slug, which the evaluation output sends with the results so
 * that the platform ties the run to the dataset.
 */
class IQDataset implements NamedDatasetInterface
{
    /**
     * @internal built by {@see \NeuronCore\IQ\IQ::dataset()}
     */
    public function __construct(
        protected readonly PlatformClient $platform,
        protected readonly string $slug,
    ) {
    }

    public function getName(): string
    {
        return $this->slug;
    }

    /**
     * @return list<array<array-key, mixed>> the active items of the dataset, in the order the platform keeps them
     * @throws IQException unauthorized, when the platform refused the API key; report_undelivered, for a slug
     *                        it does not know and when it gave no dataset; invalid_request or unsupported_protocol,
     *                        when it refused the request with that code
     */
    public function load(): array
    {
        return $this->platform->dataset($this->slug);
    }
}
