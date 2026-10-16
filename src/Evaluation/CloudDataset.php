<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Evaluation;

use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Runtime\PlatformClient;

/**
 * A dataset kept by Neuron Cloud, for an evaluator to run on.
 *
 * Build it from the SDK root in getDataset(), with the NeuronCloud the
 * evaluator receives from the application's container:
 *
 *   public function getDataset(): DatasetInterface
 *   {
 *       return $this->cloud->dataset('refund-requests');
 *   }
 *
 * The items are read from the platform in load(), which Neuron's runner calls
 * once per evaluator, before the first item runs and before it forks for
 * --concurrency. Unlike the evaluation output it fails loud: without its items
 * there is nothing to evaluate, and Neuron's command reports the evaluator as
 * one that could not run.
 */
class CloudDataset implements DatasetInterface
{
    /**
     * @internal built by {@see \NeuronCore\Cloud\NeuronCloud::dataset()}
     */
    public function __construct(
        protected readonly PlatformClient $platform,
        protected readonly string $slug,
    ) {
    }

    /**
     * @return list<array<array-key, mixed>> the active items of the dataset, in the order the platform keeps them
     * @throws CloudException unauthorized, when the platform refused the API key; report_undelivered, for a slug
     *                        it does not know and when it gave no dataset; invalid_request or unsupported_protocol,
     *                        when it refused the request with that code
     */
    public function load(): array
    {
        return $this->platform->dataset($this->slug);
    }
}
