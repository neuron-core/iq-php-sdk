<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Datasets;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronCore\IQ\IQ;

/**
 * Runs on the dataset "refund-requests" of the platform, with the service it is
 * given as an application's container gives it: each item names an order in
 * its input and what the answer must contain in its expected output.
 */
class RefundRequestsEvaluator extends BaseEvaluator
{
    public const DATASET = 'refund-requests';

    public function __construct(protected IQ $iq)
    {
        parent::__construct();
    }

    public function getDataset(): DatasetInterface
    {
        return $this->iq->dataset(self::DATASET);
    }

    public function run(array $datasetItem): mixed
    {
        return "Order {$datasetItem['input']['order']} was refunded.";
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected_output']['contains']), $output);
    }
}
