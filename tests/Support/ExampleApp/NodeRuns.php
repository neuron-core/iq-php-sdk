<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use NeuronCore\Cloud\Tests\Support\ExecutionLog;

use function touch;

/**
 * Counts, order by order, how many times each node body was entered and how
 * many times it ran to its end. It counts in files, so the counts hold across
 * the web server and the workers.
 */
final class NodeRuns
{
    protected const ENTERED = 'entered';

    protected const FINISHED = 'finished';

    /**
     * @param string $directory an existing directory
     */
    public function __construct(protected string $directory)
    {
    }

    /**
     * @param class-string $node
     */
    public function entered(string $node, string $orderId): void
    {
        $this->log(self::ENTERED, $orderId)->record($node);
    }

    /**
     * @param class-string $node
     */
    public function finished(string $node, string $orderId): void
    {
        $this->log(self::FINISHED, $orderId)->record($node);
    }

    /**
     * Neuron runs a body again from its top when its wait is answered: a body with one wait is entered twice.
     *
     * @param class-string $node
     */
    public function timesEntered(string $node, string $orderId): int
    {
        return $this->log(self::ENTERED, $orderId)->count($node);
    }

    /**
     * @param class-string $node
     */
    public function timesFinished(string $node, string $orderId): int
    {
        return $this->log(self::FINISHED, $orderId)->count($node);
    }

    protected function log(string $moment, string $orderId): ExecutionLog
    {
        $path = "{$this->directory}/{$moment}.{$orderId}";
        touch($path);

        return new ExecutionLog($path);
    }
}
