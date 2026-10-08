<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

/**
 * A class that declares its own store, its own lease and the retention of its
 * completion, as an application's workflow may. It completes in one segment.
 */
class SelfConfiguredWorkflow extends Workflow
{
    public const LEASE = 3600;

    /**
     * @param Closure(): void $whileRunning called from inside the only node
     */
    public function __construct(protected PersistenceInterface $ownStore, protected Closure $whileRunning)
    {
        parent::__construct();

        $this->retainCompletionUntilAcknowledged();
    }

    protected function persistence(): PersistenceInterface
    {
        return $this->ownStore;
    }

    protected function leaseTimeout(): int
    {
        return self::LEASE;
    }

    protected function nodes(): array
    {
        return [new WitnessNode($this->whileRunning)];
    }
}
