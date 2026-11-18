<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Requests;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Stub\Runner\InterceptedPersistence;

/**
 * What a request made the application do behind the endpoint: the stores it
 * opened, the operations it asked of them and the workflows it built.
 */
final class Footprint
{
    protected int $storesOpened = 0;

    protected int $storeOperations = 0;

    protected int $workflowsBuilt = 0;

    /**
     * @param Closure(): PersistenceInterface $persistence
     * @return Closure(): PersistenceInterface the same factory, counted with every operation of the stores it opens
     */
    public function ofStores(Closure $persistence): Closure
    {
        return function () use ($persistence): PersistenceInterface {
            $this->storesOpened++;

            return new InterceptedPersistence($persistence(), function (): void {
                $this->storeOperations++;
            });
        };
    }

    /**
     * @param Closure(): Workflow $factory
     * @return Closure(): Workflow the same factory, counted
     */
    public function ofWorkflows(Closure $factory): Closure
    {
        return function () use ($factory): Workflow {
            $this->workflowsBuilt++;

            return $factory();
        };
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'stores opened' => $this->storesOpened,
            'store operations' => $this->storeOperations,
            'workflows built' => $this->workflowsBuilt,
        ];
    }
}
