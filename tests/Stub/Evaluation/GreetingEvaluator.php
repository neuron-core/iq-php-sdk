<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Evaluation;

use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Dataset\ArrayDataset;

/**
 * Greets two people and expects each greeting to name someone: the first does, the second does not.
 */
class GreetingEvaluator extends BaseEvaluator
{
    public function namespace(): ?string
    {
        return 'support';
    }

    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            ['name' => 'Ada', 'expected' => 'Ada'],
            ['name' => 'Bob', 'expected' => 'Zed'],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        return "Hello, {$datasetItem['name']}!";
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContains($datasetItem['expected']), $output);
    }
}
