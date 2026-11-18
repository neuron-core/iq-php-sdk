<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint;

use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Outcome;
use NeuronCore\IQ\Report;
use NeuronCore\IQ\Tests\Stub\Runner\BusyNode;
use NeuronCore\IQ\Tests\Stub\Runner\BusyWorkflow;
use NeuronCore\IQ\Tests\Support\Endpoint\EndpointTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function time;
use function usleep;

/**
 * A run whose worker went silent: the platform asks, sees a lease that has
 * passed, and wakes the run with the attempt it was shown. The worker is
 * played by a node that outlives its lease: no test of a single process can
 * kill one.
 */
class DeadRunTest extends EndpointTestCase
{
    #[DataProvider('backends')]
    public function test_the_platform_wakes_a_run_whose_lease_has_passed_with_the_attempt_an_inspect_showed(string $backend): void
    {
        $bench = $this->benchOn($backend);
        $executions = $bench->executions;
        $takeover = null;
        $outlivesItsLease = function () use ($executions, &$takeover): void {
            // The wake executes the node again, in this process: only the first execution is the silent worker.
            if ($executions->count(BusyNode::class) > 1) {
                return;
            }

            $dead = $this->runShownOnceItsLeaseHasPassed();
            $this->deliver($this->wake($dead['attempt']));
            [$takeover] = $this->work();
        };
        $bench->iq->register(
            self::DEFINITION,
            static fn (): Workflow => new BusyWorkflow($executions, $outlivesItsLease),
            leaseTimeout: 1,
        );
        $this->deliver($this->start());

        [$silent] = $this->work();

        self::assertInstanceOf(Report::class, $takeover);
        self::assertSame([Outcome::Completed, 2], [$takeover->outcome, $takeover->executedAttempt]);
        self::assertInstanceOf(Report::class, $silent);
        self::assertSame([Outcome::Refused, RefusalReason::StaleAttempt->value], [$silent->outcome, $silent->code], 'The first worker lost its run.');
        self::assertNull(self::fieldsOf($this->deliver($this->inspect()))['run']);
    }

    /**
     * The platform asks about the run until the lease it is shown has passed.
     *
     * @return array<string, mixed> the run, as the last answer showed it
     */
    protected function runShownOnceItsLeaseHasPassed(): array
    {
        do {
            usleep(50_000);
            $run = self::fieldsOf($this->deliver($this->inspect(self::RUN_ID)))['run'];
        } while ($run['leaseExpiresAt'] > time());

        self::assertSame('running', $run['status']);

        return $run;
    }
}
