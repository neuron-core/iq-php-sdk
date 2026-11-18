<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Evaluation;

use NeuronAI\Evaluation\Assertions\IsValidJson;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Its run gives an array, as an evaluator of structured output does.
 */
class ProfileEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([['name' => 'Ada']]);
    }

    public function run(array $datasetItem): mixed
    {
        return ['name' => $datasetItem['name'], 'languages' => ['en', 'it']];
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new IsValidJson(), json_encode($output, JSON_THROW_ON_ERROR));
    }
}
