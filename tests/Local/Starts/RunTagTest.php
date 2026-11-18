<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Starts;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronCore\IQ\ManagedWorkflow;
use NeuronCore\IQ\Tests\Stub\WaitingWorkflow;
use NeuronCore\IQ\Tests\Support\Local\LocalTestCase;
use NeuronCore\IQ\Tests\Support\TestBench;
use NeuronCore\IQ\Tests\Support\Units\Queued;
use PHPUnit\Framework\Attributes\DataProvider;

use function iterator_to_array;

/**
 * Every start the SDK gives Neuron carries the name of its definition as the
 * tag of the run, wherever the start executes. The tag is read here with
 * Neuron's engine alone, from the run the start left waiting for its payment.
 */
class RunTagTest extends LocalTestCase
{
    protected const DEFINITION = 'order-fulfilment';

    protected const RUN_IN_PLACE = 'run()';

    protected const STREAMED = 'events()';

    protected const DISPATCHED = 'dispatch(), executed by a worker';

    protected const SENT_BY_THE_PLATFORM = 'a start of the platform, executed by a worker';

    #[DataProvider('startsOnBackends')]
    public function test_a_run_carries_the_name_of_the_definition_that_started_it_as_its_tag(string $start, string $backend): void
    {
        $this->benchOn($backend);

        $this->start($start, $this->orders());

        self::assertSame(self::DEFINITION, $this->tagOfTheStoredRun());
    }

    #[DataProvider('startsOnBackends')]
    public function test_the_tag_of_a_run_is_the_name_alone_whatever_version_started_it(string $start, string $backend): void
    {
        $this->benchOn($backend);

        $this->start($start, $this->orders(version: 2), runId: '2-0192f3a8-7c1e');

        self::assertSame(self::DEFINITION, $this->tagOfTheStoredRun());
    }

    /**
     * @return array<string, array{string, string}> how the start executes, and the backend
     */
    public static function startsOnBackends(): array
    {
        $rows = [];
        foreach (TestBench::backends() as $backendName => [$backend]) {
            foreach ([self::RUN_IN_PLACE, self::STREAMED, self::DISPATCHED, self::SENT_BY_THE_PLATFORM] as $start) {
                $rows["{$start} on {$backendName}"] = [$start, $backend];
            }
        }

        return $rows;
    }

    /**
     * "order-fulfilment" waits for its payment: the run of a start stays in the store.
     */
    protected function orders(int $version = 1): ManagedWorkflow
    {
        $executions = $this->bench->executions;

        return $this->bench->iq
            ->register(self::DEFINITION, static fn (): Workflow => new WaitingWorkflow($executions), $version)
            ->workflow(self::DEFINITION, self::WORKFLOW_ID);
    }

    /**
     * @param string $runId the run ID the platform minted, for the start it sends
     */
    protected function start(string $start, ManagedWorkflow $orders, string $runId = self::RUN_ID): void
    {
        match ($start) {
            self::RUN_IN_PLACE => $orders->start()->run(),
            self::STREAMED => iterator_to_array($orders->start()->events(), false),
            self::DISPATCHED => $orders->start()->dispatch(),
            default => $this->bench->iq->invoke(Queued::start(self::DEFINITION, runId: $runId)),
        };

        $this->queue->runAll($this->bench->iq);
    }

    protected function tagOfTheStoredRun(): ?string
    {
        return (new WorkflowEngine(($this->bench->persistence)()))->inspect(self::WORKFLOW_ID)?->tag;
    }
}
